<?php

declare(strict_types=1);

use App\Database;
use App\Repository\SecuritySettingsRepository;
use App\Service\AccountSecurityService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$connection = (new Database())->connect();
$connection->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING ALL)');
$connection->exec('CREATE TEMP TABLE user_email_changes (LIKE public.user_email_changes INCLUDING ALL)');
foreach (['users', 'user_email_changes'] as $table) {
    if ((int) $connection->query("SELECT COUNT(*) FROM pg_class WHERE oid = to_regclass('{$table}') AND relnamespace = pg_my_temp_schema()")->fetchColumn() !== 1) {
        throw new RuntimeException('Test wymaga tabel tymczasowych.');
    }
}
$repository = new SecuritySettingsRepository($connection);
$hash = password_hash('Original-password-2026!', PASSWORD_DEFAULT);
$newHash = password_hash('Changed-password-2026!', PASSWORD_DEFAULT);
$token = hash('sha256', 'one-time-code');
$assert = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Warunek testu nie został spełniony.');
    }
};
$passed = 0;
$failed = 0;
$run = static function (string $label, callable $test) use ($connection, $hash, &$passed, &$failed): void {
    try {
        $connection->exec('TRUNCATE pg_temp.users, pg_temp.user_email_changes');
        $statement = $connection->prepare('INSERT INTO pg_temp.users (id, name, email, password_hash) VALUES (:id, :name, :email, :hash)');
        foreach ([1 => 'first@example.test', 2 => 'second@example.test'] as $id => $email) {
            $statement->execute(['id' => $id, 'name' => 'Test', 'email' => $email, 'hash' => $hash]);
        }
        $test();
        $passed++;
        echo '[OK] ' . $label . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $label . ': ' . $exception->getMessage() . PHP_EOL);
    }
};

$run('Nowe hasła respektują liczbę znaków, limit bcrypt i NUL', static function () use ($assert): void {
    foreach (['1234567', str_repeat('a', 73), str_repeat('ą', 37), "password\0", "invalid\xFF"] as $invalid) {
        $assert(!AccountSecurityService::isPasswordValid($invalid));
    }
    foreach (['12345678', str_repeat('ą', 36)] as $valid) {
        $assert(AccountSecurityService::isPasswordValid($valid));
    }
});

$run('Zmiana hasła wymaga aktualnego hasha i izoluje konto', static function () use ($repository, $assert, $hash, $newHash): void {
    $assert(!$repository->changePassword(1, 'outdated-hash', $newHash));
    $assert($repository->changePassword(1, $hash, $newHash));
    $assert(!$repository->changePassword(1, $hash, $hash));
    $assert($repository->passwordHashForUser(1) === $newHash);
    $assert($repository->passwordHashForUser(2) === $hash);
});

$run('Potwierdzenie jest przypisane do konta, jednorazowe i nie zmienia adresu przed kodem', static function () use ($repository, $connection, $assert, $hash, $token): void {
    $assert($repository->requestEmailChange(1, 'new@example.test', $token, $hash));
    $assert($connection->query('SELECT email FROM users WHERE id = 1')->fetchColumn() === 'first@example.test');
    $assert($repository->confirmEmailChange(2, $token) === null);
    $assert($repository->confirmEmailChange(1, hash('sha256', 'wrong')) === null);
    $assert($repository->pendingEmailForUser(1) === 'new@example.test');
    $assert($repository->confirmEmailChange(1, $token) === 'new@example.test');
    $assert($repository->confirmEmailChange(1, $token) === null);
});

$run('Wygasły kod nie zmienia adresu', static function () use ($repository, $connection, $assert, $hash, $token): void {
    $repository->requestEmailChange(1, 'new@example.test', $token, $hash);
    $connection->exec("UPDATE user_email_changes SET requested_at = CURRENT_TIMESTAMP - INTERVAL '31 minutes', expires_at = CURRENT_TIMESTAMP - INTERVAL '1 minute'");
    $assert($repository->confirmEmailChange(1, $token) === null);
    $assert($repository->pendingEmailForUser(1) === null);
});

$run('Zmiana hasła unieważnia wydany kod e-maila', static function () use ($repository, $assert, $hash, $newHash, $token): void {
    $repository->requestEmailChange(1, 'new@example.test', $token, $hash);
    $repository->changePassword(1, $hash, $newHash);
    $assert($repository->confirmEmailChange(1, $token) === null);
    $assert($repository->pendingEmailForUser(1) === null);
});

$run('Ograniczenie ponownej wysyłki i nowy kod unieważniają poprzedni', static function () use ($repository, $connection, $assert, $hash, $token): void {
    $repository->requestEmailChange(1, 'new@example.test', $token, $hash);
    $next = hash('sha256', 'next-code');
    $assert(!$repository->requestEmailChange(1, 'another@example.test', $next, $hash));
    $connection->exec("UPDATE user_email_changes SET requested_at = CURRENT_TIMESTAMP - INTERVAL '61 seconds'");
    $assert($repository->requestEmailChange(1, 'another@example.test', $next, $hash));
    $assert($repository->confirmEmailChange(1, $token) === null);
    $assert($repository->confirmEmailChange(1, $next) === 'another@example.test');
});

$run('Zajęcie adresu przed potwierdzeniem wycofuje zmianę', static function () use ($repository, $connection, $assert, $hash, $token): void {
    $repository->requestEmailChange(1, 'SECOND@example.test', $token, $hash);
    $caught = false;
    try {
        $repository->confirmEmailChange(1, $token);
    } catch (PDOException $exception) {
        $caught = $exception->getCode() === '23505';
    }
    $assert($caught);
    $assert($connection->query('SELECT email FROM users WHERE id = 1')->fetchColumn() === 'first@example.test');
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.%s', $passed, $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
