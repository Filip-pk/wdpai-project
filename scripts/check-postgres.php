<?php

declare(strict_types=1);

$fail = static function (string $message): never {
    fwrite(STDERR, '[BŁĄD] ' . $message . PHP_EOL);
    exit(1);
};

if (!extension_loaded('pdo_pgsql') || !in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    $fail('Brak sterownika pdo_pgsql w PHP.');
}

echo '[OK] Sterownik PDO PostgreSQL jest dostępny.' . PHP_EOL;

$host = getenv('POSTGRES_HOST');
$port = filter_var(getenv('POSTGRES_PORT'), FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
$database = getenv('POSTGRES_DB');
$username = getenv('POSTGRES_USER');
$password = getenv('POSTGRES_PASSWORD');

if (
    $host === false || $host === ''
    || $port === false
    || $database === false || $database === ''
    || $username === false || $username === ''
    || $password === false || $password === ''
) {
    $fail('Brak kompletnej konfiguracji POSTGRES_* w kontenerze PHP.');
}

echo '[OK] Konfiguracja połączenia jest dostępna.' . PHP_EOL;

if (gethostbyname($host) === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
    $fail('Nie udało się rozwiązać nazwy hosta PostgreSQL.');
}

echo '[OK] Nazwa hosta PostgreSQL jest rozwiązywana.' . PHP_EOL;

try {
    $connection = new PDO(
        sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5;sslmode=prefer',
            $host,
            $port,
            $database,
        ),
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );

    echo '[OK] Połączenie i uwierzytelnienie PDO działają.' . PHP_EOL;

    if ((int) $connection->query('SELECT 1')->fetchColumn() !== 1) {
        $fail('Zapytanie kontrolne zwróciło nieoczekiwany wynik.');
    }

    echo '[OK] Zapytanie SELECT 1 zakończyło się poprawnie.' . PHP_EOL;
} catch (PDOException) {
    $fail('Połączenie lub zapytanie PostgreSQL nie powiodło się. Sprawdź konfigurację i dostępność bazy.');
}
