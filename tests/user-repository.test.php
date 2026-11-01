<?php

declare(strict_types=1);

use App\Database;
use App\Repository\UserRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Warunek testu nie został spełniony.');
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $connection->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING ALL) ON COMMIT DROP');
    // BIGSERIAL kopiuje domyślną sekwencję, dlatego zastępujemy ją własną.
    $connection->exec('CREATE TEMP SEQUENCE user_repository_test_id_seq');
    $connection->exec(
        "ALTER TABLE pg_temp.users ALTER COLUMN id
         SET DEFAULT nextval('pg_temp.user_repository_test_id_seq'::regclass)"
    );
    $connection->exec('ALTER SEQUENCE pg_temp.user_repository_test_id_seq OWNED BY pg_temp.users.id');
    $assert((int) $connection->query(
        "SELECT count(*) FROM pg_class
         WHERE oid = to_regclass('users')
           AND oid <> to_regclass('public.users')
           AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
    )->fetchColumn() === 1);
    $assert((int) $connection->query(
        "SELECT count(*) FROM pg_class
         WHERE oid = pg_get_serial_sequence('users', 'id')::regclass
           AND oid <> pg_get_serial_sequence('public.users', 'id')::regclass
           AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
    )->fetchColumn() === 1);

    $repository = new UserRepository($connection);
    $password = 'Bezpieczne-hasło-testowe-123';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT user_repository_test');

        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT user_repository_test');
            $connection->exec('RELEASE SAVEPOINT user_repository_test');
        }
    };

    $run('Brak użytkownika zwraca null', static function () use ($repository, $assert): void {
        $assert($repository->findByEmail('brak@example.com') === null);
    });

    $run('Zapis zachowuje polskie znaki i hash hasła oraz nadaje ID i datę', static function () use (
        $repository, $connection, $assert, $password, $hash,
    ): void {
        $id = $repository->create('Łukasz Żółć', 'lukasz@example.com', $hash);
        $user = $repository->findByEmail('lukasz@example.com');
        $assert($id > 0 && $user !== null && $user['id'] === $id);
        $assert($user['name'] === 'Łukasz Żółć' && $user['email'] === 'lukasz@example.com');
        $assert($user['password_hash'] === $hash && $user['password_hash'] !== $password);
        $assert(password_verify($password, $user['password_hash']));
        $assert(!password_verify('Nieprawidłowe-hasło', $user['password_hash']));
        $assert($connection->query('SELECT created_at FROM users')->fetchColumn() !== null);
    });

    $run('Wyszukiwanie e-maila pomija wielkość liter', static function () use ($repository, $assert, $hash): void {
        $id = $repository->create('Użytkownik', 'User@Example.com', $hash);
        $assert($repository->findByEmail('user@example.com')['id'] === $id);
        $assert($repository->findByEmail('USER@EXAMPLE.COM')['id'] === $id);
    });

    $run('Apostrofy i fragmenty SQL pozostają danymi', static function () use ($repository, $assert, $hash): void {
        $name = "O'Reilly; SELECT 999; --";
        $email = "test' OR 1=1 --@example.com";
        $repository->create($name, $email, $hash);
        $user = $repository->findByEmail($email);
        $assert($user !== null && $user['name'] === $name && $user['email'] === $email);
        $assert($repository->findByEmail("' OR 1=1 --") === null);
    });

    $run('Baza odrzuca drugi e-mail również przy innej wielkości liter', static function () use (
        $repository, $assert, $hash,
    ): void {
        $repository->create('Pierwszy', 'konto@example.com', $hash);
        $exception = null;

        try {
            $repository->create('Drugi', 'KONTO@EXAMPLE.COM', $hash);
        } catch (PDOException $caught) {
            $exception = $caught;
        }

        $assert($exception instanceof PDOException && $exception->getCode() === '23505');
    });
} catch (Throwable) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować izolowanych testów użytkowników.' . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        try {
            $connection->rollBack();
        } catch (Throwable) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] Nie udało się wycofać transakcji testowej.' . PHP_EOL);
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
