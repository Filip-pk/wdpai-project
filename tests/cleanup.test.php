<?php

declare(strict_types=1);

use App\Database;
use App\Repository\MaintenanceRepository;
use App\Service\ApplicationLogger;
use App\Service\AvatarStorage;
use App\Service\CleanupService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Temporary tables shadow the real ones inside a transaction that is always rolled back,
// and files go to a temporary directory, so the test never deletes real data.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$passed = 0;
$failed = 0;
$connection = null;
$directory = sys_get_temp_dir() . '/anivibe-cleanup-' . bin2hex(random_bytes(8));
$remove = static function (string $path) use (&$remove): void {
    foreach (is_dir($path) ? array_diff(scandir($path) ?: [], ['.', '..']) : [] as $name) {
        is_dir($path . '/' . $name) ? $remove($path . '/' . $name) : unlink($path . '/' . $name);
    }
    if (is_dir($path)) {
        rmdir($path);
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach (['users', 'login_attempts', 'community_activity', 'user_email_changes'] as $table) {
        $connection->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING ALL) ON COMMIT DROP");
    }
    $hash = str_repeat('a', 64);
    $connection->exec("INSERT INTO login_attempts (email_hash, ip_hash, attempted_at) VALUES
        ('$hash', '$hash', clock_timestamp() - INTERVAL '25 hours'),
        ('$hash', '$hash', clock_timestamp() - INTERVAL '3 days'),
        ('$hash', '$hash', clock_timestamp() - INTERVAL '1 hour')");
    $connection->exec("INSERT INTO community_activity (user_id, action, content_hash, created_at) VALUES
        (1, 'comment', '$hash', clock_timestamp() - INTERVAL '2 days'),
        (1, 'comment', '$hash', clock_timestamp() - INTERVAL '5 minutes')");
    $connection->exec("INSERT INTO user_email_changes (user_id, new_email, token_hash, password_hash, requested_at, expires_at) VALUES
        (1, 'old@example.test', '$hash', 'hash', CURRENT_TIMESTAMP - INTERVAL '2 days', CURRENT_TIMESTAMP - INTERVAL '1 day'),
        (2, 'new@example.test', '$hash', 'hash', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP + INTERVAL '1 hour')");

    $storage = new AvatarStorage($directory . '/uploads');
    $used = $storage->save('used');
    $orphan = $storage->save('orphan');
    $fresh = $storage->save('fresh');
    touch($directory . '/uploads/' . $used, time() - 7200);
    touch($directory . '/uploads/' . $orphan, time() - 7200);
    $statement = $connection->prepare("INSERT INTO users (id, name, email, password_hash, avatar_path, avatar_version)
        VALUES (900000001, 'Cleanup', 'cleanup@example.test', 'hash', :path, :version)");
    $statement->execute(['path' => $used, 'version' => str_repeat('b', 32)]);

    $logger = new ApplicationLogger($directory . '/logs');
    $cleanup = new CleanupService(new MaintenanceRepository($connection), $storage, $logger);
    $count = static fn (string $table): int => (int) $connection->query("SELECT COUNT(*) FROM $table")->fetchColumn();

    $result = $cleanup->run();
    $assert($result === ['status' => 'finished', 'loginAttempts' => 2, 'emailChanges' => 1, 'activity' => 1, 'avatarFiles' => 1],
        'Usunięte tylko przeterminowane dane: ' . json_encode($result));
    $assert($count('login_attempts') === 1 && $count('community_activity') === 1 && $count('user_email_changes') === 1,
        'Aktualne wpisy zostają.');
    $assert($storage->read($used) === 'used' && $storage->read($fresh) === 'fresh' && $storage->read($orphan) === null,
        'Usunięty tylko stary plik bez właściciela.');
    $entry = $logger->recent('info', 'maintenance')['items'][0] ?? null;
    $assert($entry !== null && $entry['messageKey'] === 'admin.log.cleanup_finished'
        && $entry['context'] === ['loginAttempts' => 2, 'emailChanges' => 1, 'activity' => 1, 'avatarFiles' => 1],
        'Wynik trafia do logu aplikacji, widocznego w panelu administratora.');
    echo "[OK] Zadanie usuwa przeterminowane dane i zapisuje wynik w logu\n";
    $passed++;

    $assert($cleanup->run() === ['status' => 'finished', 'loginAttempts' => 0, 'emailChanges' => 0, 'activity' => 0, 'avatarFiles' => 0],
        'Ponowne uruchomienie niczego już nie usuwa.');
    echo "[OK] Ponowne uruchomienie jest bezpieczne\n";
    $passed++;

    // A second connection holds the task lock, as an overlapping scheduled run would.
    $other = (new Database())->connect();
    $other->query('SELECT pg_advisory_lock(746562, 33)')->fetchColumn();
    try {
        $assert($cleanup->run() === ['status' => 'skipped'], 'Równoległe uruchomienie jest pomijane.');
    } finally {
        $other->query('SELECT pg_advisory_unlock(746562, 33)')->fetchColumn();
    }
    $assert(($logger->recent('info', 'maintenance')['items'][0]['messageKey'] ?? '') === 'admin.log.cleanup_skipped',
        'Pominięcie jest zapisane w logu.');
    $assert(in_array($other->query('SELECT pg_try_advisory_lock(746562, 33)')->fetchColumn(), [true, 't'], true),
        'Po zakończeniu zadanie zwalnia blokadę.');
    $other->query('SELECT pg_advisory_unlock(746562, 33)')->fetchColumn();
    echo "[OK] Nakładające się uruchomienia nie działają jednocześnie\n";
    $passed++;

    $connection->exec('SAVEPOINT cleanup_failure');
    $connection->exec('ALTER TABLE pg_temp.user_email_changes RENAME COLUMN expires_at TO expired_at');
    try {
        $cleanup->run();
        $assert(false, 'Błąd zapytania musi zakończyć zadanie wyjątkiem.');
    } catch (PDOException) {
        // Expected: the scheduled script exits with a non-zero status.
    }
    $connection->exec('ROLLBACK TO SAVEPOINT cleanup_failure');
    $connection->query('SELECT pg_advisory_unlock_all()')->fetchColumn();
    $failure = $logger->recent('error', 'maintenance')['items'][0] ?? null;
    $assert($failure !== null && $failure['messageKey'] === 'admin.log.cleanup_failed'
        && $failure['context'] === ['class' => 'PDOException'], 'Błąd jest zapisany bez treści wyjątku.');
    echo "[OK] Błąd zadania jest zapisany w logu\n";
    $passed++;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    $remove($directory);
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
