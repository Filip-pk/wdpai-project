<?php

declare(strict_types=1);

use App\Database;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$fail = static function (string $message): never {
    fwrite(STDERR, '[BŁĄD] ' . $message . PHP_EOL);
    exit(1);
};

if (!extension_loaded('pdo_pgsql') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    $fail('Brak sterownika pdo_pgsql w PHP.');
}

echo '[OK] Sterownik PDO PostgreSQL jest dostępny.' . PHP_EOL;

try {
    $database = new Database();
} catch (RuntimeException) {
    $fail('Brak kompletnej lub poprawnej konfiguracji POSTGRES_* w kontenerze PHP.');
}

echo '[OK] Konfiguracja połączenia jest dostępna.' . PHP_EOL;

$host = getenv('POSTGRES_HOST');

if (gethostbyname($host) === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
    $fail('Nie udało się rozwiązać nazwy hosta PostgreSQL.');
}

echo '[OK] Nazwa hosta PostgreSQL jest rozwiązywana.' . PHP_EOL;

try {
    $connection = $database->connect();

    echo '[OK] Połączenie i uwierzytelnienie PDO działają.' . PHP_EOL;

    if ((int) $connection->query('SELECT 1')->fetchColumn() !== 1) {
        $fail('Zapytanie kontrolne zwróciło nieoczekiwany wynik.');
    }

    echo '[OK] Zapytanie SELECT 1 zakończyło się poprawnie.' . PHP_EOL;
} catch (RuntimeException) {
    $fail('Połączenie lub zapytanie PostgreSQL nie powiodło się. Sprawdź konfigurację i dostępność bazy.');
}
