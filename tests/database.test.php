<?php

declare(strict_types=1);

use App\Database;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$configurationError = 'Nieprawidłowa konfiguracja połączenia z bazą danych.';
$connectionError = 'Nie udało się połączyć z bazą danych.';
$environmentNames = [
    'POSTGRES_HOST',
    'POSTGRES_PORT',
    'POSTGRES_DB',
    'POSTGRES_USER',
    'POSTGRES_PASSWORD',
];

$assert = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Warunek testu nie został spełniony.');
    }
};

$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . PHP_EOL);
    }
};

/** @param array<string, string|null> $changes */
$withEnvironment = static function (array $changes, callable $test): void {
    $previousValues = [];

    try {
        foreach ($changes as $name => $value) {
            $previousValues[$name] = getenv($name);
            putenv($value === null ? $name : $name . '=' . $value);
        }

        $test();
    } finally {
        foreach ($previousValues as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }
};

$expectConfigurationError = static function () use ($assert, $configurationError): void {
    $exception = null;

    try {
        new Database();
    } catch (RuntimeException $caught) {
        $exception = $caught;
    }

    $assert($exception !== null && $exception->getMessage() === $configurationError);
};

$run('Połączenie PDO, ustawienia i SELECT 1', static function () use ($assert): void {
    $connection = (new Database())->connect();

    $assert($connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
    $assert($connection->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);
    $assert($connection->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC);
    $assert($connection->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false);

    $row = $connection->query('SELECT 1 AS value')->fetch();

    $assert(is_array($row) && array_keys($row) === ['value'] && (int) $row['value'] === 1);
});

$run('Prepared statements przekazują apostrof i średnik jako dane', static function () use ($assert): void {
    $value = "O'Reilly; SELECT 999; --";
    $connection = (new Database())->connect();
    $statement = $connection->prepare('SELECT CAST(:value AS TEXT) AS value');
    $statement->execute(['value' => $value]);

    $assert($statement->fetch() === ['value' => $value]);
});

$run('Wielokrotne tworzenie Database poprawnie wczytuje konfigurację', static function () use ($assert): void {
    $firstConnection = (new Database())->connect();
    $secondConnection = (new Database())->connect();

    $assert((int) $firstConnection->query('SELECT 1')->fetchColumn() === 1);
    $assert((int) $secondConnection->query('SELECT 1')->fetchColumn() === 1);
});

$run('Instancja Database zapamiętuje parametry w konstruktorze', static function () use (
    $assert,
    $withEnvironment,
): void {
    $database = new Database();

    $withEnvironment([
        'POSTGRES_HOST' => '127.0.0.1',
        'POSTGRES_PORT' => '1',
    ], static function () use ($assert, $database): void {
        $connection = $database->connect();

        $assert((int) $connection->query('SELECT 1')->fetchColumn() === 1);
    });
});

$run('Brak dowolnej wymaganej zmiennej daje ogólny błąd konfiguracji', static function () use (
    $environmentNames,
    $withEnvironment,
    $expectConfigurationError,
): void {
    foreach ($environmentNames as $name) {
        $withEnvironment([$name => null], $expectConfigurationError);
    }
});

$run('Pusta wymagana zmienna daje ogólny błąd konfiguracji', static function () use (
    $environmentNames,
    $withEnvironment,
    $expectConfigurationError,
): void {
    foreach ($environmentNames as $name) {
        $withEnvironment([$name => ''], $expectConfigurationError);
    }
});

$run('Port musi być liczbą całkowitą od 1 do 65535', static function () use (
    $withEnvironment,
    $expectConfigurationError,
): void {
    foreach (['0', '-1', '65536', 'abc', '1.5', '5432;host=other'] as $port) {
        $withEnvironment(['POSTGRES_PORT' => $port], $expectConfigurationError);
    }

    foreach (['1', '5432', '65535'] as $port) {
        $withEnvironment(['POSTGRES_PORT' => $port], static function (): void {
            new Database();
        });
    }
});

$run('Host i nazwa bazy odrzucają separatory DSN i znaki kontrolne', static function () use (
    $withEnvironment,
    $expectConfigurationError,
): void {
    foreach (['POSTGRES_HOST', 'POSTGRES_DB'] as $name) {
        foreach (['value;port=1', "value\nother", "value\rother", "value\tother", "value\x01other", "value\x7Fother"] as $value) {
            $withEnvironment([$name => $value], $expectConfigurationError);
        }
    }
});

$run('Hasło będące ciągiem zero jest poprawną wartością konfiguracji', static function () use (
    $withEnvironment,
): void {
    $withEnvironment(['POSTGRES_PASSWORD' => '0'], static function (): void {
        new Database();
    });
});

$run('Niedostępne połączenie daje ogólny komunikat i zachowuje przyczynę', static function () use (
    $assert,
    $withEnvironment,
    $connectionError,
): void {
    $withEnvironment([
        'POSTGRES_HOST' => '127.0.0.1',
        'POSTGRES_PORT' => '1',
    ], static function () use ($assert, $connectionError): void {
        $exception = null;

        try {
            (new Database())->connect();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $assert($exception !== null && $exception->getMessage() === $connectionError);
        $assert($exception->getPrevious() instanceof PDOException);
    });
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;

exit($failed === 0 ? 0 : 1);
