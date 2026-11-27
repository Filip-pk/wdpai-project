<?php

declare(strict_types=1);

use App\Database;
use App\Repository\LoginAttemptRepository;
use App\Service\LoginThrottle;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$connection = (new Database())->connect();
$connection->exec('CREATE TEMP TABLE login_attempts (LIKE public.login_attempts INCLUDING ALL)');
if ((int) $connection->query("SELECT COUNT(*) FROM pg_class WHERE oid = to_regclass('login_attempts') AND relnamespace = pg_my_temp_schema()")->fetchColumn() !== 1) {
    throw new RuntimeException('Test wymaga tabeli tymczasowej.');
}
$throttle = new LoginThrottle(new LoginAttemptRepository($connection));
$assert = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Warunek testu nie został spełniony.');
    }
};
$passed = 0;
$failed = 0;
$run = static function (string $label, callable $test) use ($connection, &$passed, &$failed): void {
    try {
        $connection->exec('TRUNCATE pg_temp.login_attempts');
        $test();
        $passed++;
        echo '[OK] ' . $label . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $label . ': ' . $exception->getMessage() . PHP_EOL);
    }
};
$insertOld = static function (string $email, string $address, string $age) use ($connection): void {
    $statement = $connection->prepare(
        "INSERT INTO login_attempts (email_hash, ip_hash, attempted_at)
         VALUES (:email_hash, :ip_hash, clock_timestamp() - CAST(:age AS INTERVAL))"
    );
    $statement->execute([
        'email_hash' => LoginThrottle::fingerprint($email),
        'ip_hash' => LoginThrottle::fingerprint($address),
        'age' => $age,
    ]);
};

$run('Piąta nieudana próba blokuje konto tylko z tego samego adresu', static function () use ($throttle, $assert): void {
    for ($attempt = 1; $attempt < LoginThrottle::ACCOUNT_LIMIT; $attempt++) {
        $throttle->recordFailure('ada@example.test', '172.18.0.1');
        $assert($throttle->retryAfter('ada@example.test', '172.18.0.1') === 0);
    }
    $throttle->recordFailure('ada@example.test', '172.18.0.1');
    $wait = $throttle->retryAfter('ada@example.test', '172.18.0.1');
    $assert($wait > LoginThrottle::WINDOW_SECONDS - 60 && $wait <= LoginThrottle::WINDOW_SECONDS);
    $assert($throttle->retryAfter('ada@example.test', '10.0.0.2') === 0);
    $assert($throttle->retryAfter('other@example.test', '172.18.0.1') === 0);
});

$run('Udane logowanie czyści licznik tego konta', static function () use ($throttle, $assert): void {
    for ($attempt = 0; $attempt < LoginThrottle::ACCOUNT_LIMIT; $attempt++) {
        $throttle->recordFailure('ada@example.test', '172.18.0.1');
    }
    $throttle->recordFailure('other@example.test', '172.18.0.1');
    $throttle->recordSuccess('ada@example.test', '172.18.0.1');
    $assert($throttle->retryAfter('ada@example.test', '172.18.0.1') === 0);
});

$run('Limit adresu IP obejmuje próby na różne konta', static function () use ($connection, $throttle, $assert): void {
    $connection->exec("INSERT INTO login_attempts (email_hash, ip_hash)
        SELECT encode(sha256(('user-' || n || '@example.test')::bytea), 'hex'), '" . LoginThrottle::fingerprint('172.18.0.9') . "'
        FROM generate_series(1, " . LoginThrottle::CLIENT_LIMIT . ') AS n');
    $assert($throttle->retryAfter('fresh@example.test', '172.18.0.9') > 0);
    $assert($throttle->retryAfter('fresh@example.test', '172.18.0.10') === 0);
});

$run('Próby sprzed okna nie blokują, a stare rekordy są usuwane', static function () use ($connection, $throttle, $assert, $insertOld): void {
    for ($attempt = 0; $attempt < LoginThrottle::ACCOUNT_LIMIT; $attempt++) {
        $insertOld('ada@example.test', '172.18.0.1', '16 minutes');
    }
    $assert($throttle->retryAfter('ada@example.test', '172.18.0.1') === 0);
    $insertOld('ada@example.test', '172.18.0.1', '2 days');
    $throttle->recordFailure('other@example.test', '172.18.0.1');
    $assert((int) $connection->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at < clock_timestamp() - INTERVAL '1 day'")->fetchColumn() === 0);
});

$run('Tabela przechowuje tylko skróty e-maila i adresu', static function () use ($connection, $throttle, $assert): void {
    $throttle->recordFailure('ada@example.test', '172.18.0.1');
    $row = $connection->query('SELECT email_hash, ip_hash FROM login_attempts')->fetch(PDO::FETCH_ASSOC);
    $assert($row['email_hash'] === hash('sha256', 'ada@example.test'));
    $assert($row['ip_hash'] === hash('sha256', '172.18.0.1'));
    $assert(!str_contains(json_encode($row, JSON_THROW_ON_ERROR), 'example.test'));
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.%s', $passed, $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
