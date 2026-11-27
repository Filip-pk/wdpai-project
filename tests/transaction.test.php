<?php

declare(strict_types=1);

use App\Database;
use App\Repository\Transaction;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Uses only a temporary table, which disappears with the connection.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$passed = 0;
$failed = 0;
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
};

$database = (new Database())->connect();
$database->exec('CREATE TEMP TABLE transaction_test (value TEXT NOT NULL)');
$values = static fn (): array => $database->query('SELECT value FROM transaction_test ORDER BY value')->fetchAll(PDO::FETCH_COLUMN);
$insert = static fn (string $value) => $database->prepare('INSERT INTO transaction_test (value) VALUES (:value)')->execute(['value' => $value]);
$clear = static fn () => $database->exec('DELETE FROM transaction_test');

$run('Własna transakcja zatwierdza wynik i kończy się poza transakcją', static function () use ($database, $values, $insert, $clear, $assert): void {
    $clear();
    $assert(Transaction::run($database, static function () use ($insert): string {
        $insert('a');
        return 'done';
    }) === 'done', 'Zwraca wynik operacji.');
    $assert($values() === ['a'] && !$database->inTransaction(), 'Zmiana jest zatwierdzona.');
});

$run('Wyjątek wycofuje zmiany i jest przekazywany dalej', static function () use ($database, $values, $insert, $clear, $assert): void {
    $clear();
    try {
        Transaction::run($database, static function () use ($insert): void {
            $insert('b');
            throw new DomainException('stop');
        });
        $assert(false, 'Wyjątek musi dotrzeć do wywołującego.');
    } catch (DomainException) {
    }
    $assert($values() === [] && !$database->inTransaction(), 'Nic nie zostaje zapisane.');
});

$run('Wynik odrzucony przez keep wycofuje zmiany, ale jest zwracany', static function () use ($database, $values, $insert, $clear, $assert): void {
    $clear();
    $result = Transaction::run($database, static function () use ($insert): ?int {
        $insert('c');
        return null;
    }, keep: static fn (?int $value): bool => $value !== null);
    $assert($result === null && $values() === [], 'Zmiana bez wyniku jest wycofana.');
});

$run('Wewnątrz transakcji błąd cofa tylko savepoint', static function () use ($database, $values, $insert, $clear, $assert): void {
    $clear();
    $database->beginTransaction();
    try {
        $insert('outer');
        try {
            Transaction::run($database, static function () use ($insert): void {
                $insert('inner');
                throw new DomainException('stop');
            });
        } catch (DomainException) {
        }
        Transaction::run($database, static fn () => $insert('kept'));
        $assert($database->inTransaction(), 'Zewnętrzna transakcja nadal trwa i działa.');
        $database->commit();
    } finally {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
    }
    $assert($values() === ['kept', 'outer'], 'Zostają zmiany zewnętrzne i z udanego savepointu.');
});

$run('Poziom izolacji dotyczy tylko własnej transakcji', static function () use ($database, $assert): void {
    $level = Transaction::run($database, static fn () => $database->query('SHOW transaction_isolation')->fetchColumn(),
        isolation: 'REPEATABLE READ');
    $assert($level === 'repeatable read', 'Ustawiony wybrany poziom izolacji.');
    try {
        Transaction::run($database, static fn () => null, isolation: 'READ UNCOMMITTED; DROP TABLE users');
        $assert(false, 'Nieznany poziom jest odrzucany.');
    } catch (UnhandledMatchError) {
    }
    $assert(!$database->inTransaction(), 'Odrzucenie poziomu nie zostawia otwartej transakcji.');
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
