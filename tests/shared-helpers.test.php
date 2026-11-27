<?php

declare(strict_types=1);

use App\Model\Pagination;
use App\Repository\DatabaseValue;
use App\Service\AccountSecurityService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Pure functions shared by every paginated list and boolean read; no database needed.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$passed = 0;
$failed = 0;

try {
    $assert(Pagination::of(45, 2, 20) === ['page' => 2, 'perPage' => 20, 'total' => 45, 'totalPages' => 3], 'Zwykła strona.');
    $assert(Pagination::of(45, 99, 20)['page'] === 3, 'Zbyt duży numer strony wskazuje ostatnią.');
    $assert(Pagination::of(45, -5, 20)['page'] === 1, 'Ujemny numer strony wskazuje pierwszą.');
    $assert(Pagination::of(0, 3, 20) === ['page' => 1, 'perPage' => 20, 'total' => 0, 'totalPages' => 1], 'Pusta lista ma jedną stronę.');
    $assert(Pagination::of(500, 1, 1000)['perPage'] === Pagination::MAX_PER_PAGE, 'Rozmiar strony ma górny limit.');
    $assert(Pagination::of(5, 1, 0)['perPage'] === 1, 'Rozmiar strony ma dolny limit.');
    $assert(Pagination::of(61, PHP_INT_MAX, 30)['page'] === 3, 'PHP_INT_MAX wskazuje ostatnią stronę.');
    echo "[OK] Paginacja ogranicza numer i rozmiar strony\n";
    $passed++;

    foreach ([true, 1, '1', 't'] as $value) {
        $assert(DatabaseValue::toBool($value), 'Prawda z PostgreSQL: ' . var_export($value, true));
    }
    foreach ([false, 0, '0', 'f', null, '', 'true', 'yes', 2] as $value) {
        $assert(!DatabaseValue::toBool($value), 'Fałsz lub nieznana wartość: ' . var_export($value, true));
    }
    echo "[OK] Wartości logiczne z bazy są odczytywane ściśle\n";
    $passed++;

    foreach (['ola@example.test', 'a.b+anime@sub.example.test', str_repeat('a', 64) . '@' . str_repeat('b', 60) . '.test'] as $email) {
        $assert(AccountSecurityService::isEmailValid($email), 'Poprawny adres: ' . $email);
    }
    foreach (['', 'bez-malpy', 'a@', '"a b"@example.test', '"<x>"@example.test', "a@example.test\r\nBcc: x@y.z",
        str_repeat('a', 250) . '@example.test'] as $email) {
        $assert(!AccountSecurityService::isEmailValid($email), 'Odrzucony adres: ' . json_encode($email));
    }
    echo "[OK] Jedna reguła adresu e-mail odrzuca spacje, nawiasy i znaki sterujące\n";
    $passed++;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] ' . $exception->getMessage() . PHP_EOL);
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
