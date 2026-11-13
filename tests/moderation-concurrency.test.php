<?php

declare(strict_types=1);

use App\Database;
use App\Repository\ModerationRepository;
use App\Service\AccountAccessGuard;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** A private schema lets separate connections share fixtures without touching application data. */
$open = static function (string $schema): PDO {
    if (preg_match('/\Amoderation_concurrency_[a-f0-9]{16}\z/', $schema) !== 1) {
        throw new RuntimeException('Invalid isolated schema.');
    }
    $connection = (new Database())->connect();
    $owner = $connection->prepare("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = :schema");
    $owner->execute(['schema' => $schema]);
    if ($owner->fetchColumn() !== 'AniVibe isolated moderation concurrency test') {
        throw new RuntimeException('Fixture schema ownership is missing.');
    }
    $connection->exec('SET search_path TO "' . $schema . '", public');
    $connection->exec("SET lock_timeout = '5s'; SET statement_timeout = '8s'");
    return $connection;
};

if (($argv[1] ?? '') === 'worker') {
    try {
        $schema = $argv[2] ?? '';
        $operation = $argv[3] ?? '';
        $actor = filter_var($argv[4] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]);
        $target = filter_var($argv[5] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]);
        if ($actor === false || $target === false || !in_array($operation, ['ban', 'write', 'write_rr'], true)) {
            throw new RuntimeException('Invalid concurrency worker arguments.');
        }
        $connection = $open($schema);
        echo json_encode(['ready' => true, 'pid' => (int) $connection->query('SELECT pg_backend_pid()')->fetchColumn()], JSON_THROW_ON_ERROR) . PHP_EOL;
        flush();
        $startedAt = microtime(true);
        $ban = AccountAccessGuard::begin($connection, $actor, $operation === 'write_rr');
        $isolation = $connection->query('SHOW transaction_isolation')->fetchColumn();
        if ($operation === 'ban') {
            $result = $ban === null && (new ModerationRepository($connection))->banUser($actor, $target, 'Concurrent ban fixture', 3600, bin2hex(random_bytes(32)));
        } else {
            $result = false;
            if ($ban === null) {
                $update = $connection->prepare("UPDATE users SET name = 'Queued write must be rejected' WHERE id = :id");
                $update->execute(['id' => $actor]);
                $result = true;
            }
        }
        AccountAccessGuard::finish($ban === null);
        echo json_encode(['result' => $result, 'blocked' => $ban !== null, 'isolation' => $isolation, 'elapsed' => microtime(true) - $startedAt], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        AccountAccessGuard::finish(false);
        fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$schema = 'moderation_concurrency_' . bin2hex(random_bytes(8));
$created = false;
$connection = null;
$workers = [];
$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$launch = static function (string $operation, int $actor, int $target) use ($schema, &$workers): array {
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $schema, $operation, (string) $actor, (string) $target], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start a second PHP connection.');
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 10);
    $line = fgets($pipes[1]);
    if (!is_string($line)) {
        throw new RuntimeException('Worker did not announce its database connection: ' . stream_get_contents($pipes[2]));
    }
    $ready = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $worker = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'pid' => $ready['pid']];
    $workers[] = $worker;
    return $worker;
};
$awaitBlocked = static function (PDO $connection, array $worker) use ($assert): void {
    $lookup = $connection->prepare("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE pid = :pid AND locktype = 'advisory'
        AND objsubid = 1 AND classid >= 2147483648 AND NOT granted)");
    $deadline = microtime(true) + 3;
    do {
        $lookup->execute(['pid' => $worker['pid']]);
        if ($lookup->fetchColumn() === true) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    $assert(false, 'The second connection must wait for the account advisory lock.');
};
$result = static function (array $worker) use ($assert): array {
    $line = fgets($worker['stdout']);
    $error = stream_get_contents($worker['stderr']);
    $assert(is_string($line), 'Worker did not return a result: ' . $error);
    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    $assert($error === '', 'Worker failed: ' . $error);
    return $data;
};

try {
    $connection = (new Database())->connect();
    $connection->exec('CREATE SCHEMA "' . $schema . '"');
    $created = true;
    $connection->exec('COMMENT ON SCHEMA "' . $schema . '" IS \'AniVibe isolated moderation concurrency test\'');
    $connection->exec('SET search_path TO "' . $schema . '", public');
    $connection->beginTransaction();
    foreach (['users', 'anime', 'anime_comments', 'community_reports'] as $table) {
        $connection->exec('CREATE TABLE "' . $schema . '".' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL)');
    }
    $connection->exec('ALTER TABLE users ALTER COLUMN id DROP DEFAULT');
    $migration = file_get_contents(dirname(__DIR__) . '/database/23-moderation-and-timed-bans.sql');
    $connection->exec(preg_replace('/\b(?:BEGIN|COMMIT);/', '', $migration));
    // Exercise real BIGINT fixture rows, including PHP/PG's largest positive ID.
    $baseId = random_int(4294967296, 8589934500);
    $actor = PHP_INT_MAX;
    $target = $baseId + 1;
    $otherModerator = $baseId + 2;
    $live = $connection->prepare('SELECT count(*) FROM public.users WHERE id IN (:actor, :target, :other)');
    $live->execute(['actor' => $actor, 'target' => $target, 'other' => $otherModerator]);
    $assert((int) $live->fetchColumn() === 0, 'Advisory test IDs must not belong to real accounts.');
    $fixture = $connection->prepare('INSERT INTO users (id, name, email, password_hash, is_moderator) VALUES (:id, :name, :email, :hash, :moderator)');
    foreach ([[$actor, 'Moderator', true], [$target, 'Original user', false], [$otherModerator, 'Other moderator', true]] as [$id, $name, $moderator]) {
        $fixture->bindValue('id', $id, PDO::PARAM_INT);
        $fixture->bindValue('name', $name);
        $fixture->bindValue('email', $id . '@concurrency.example.test');
        $fixture->bindValue('hash', 'fixture');
        $fixture->bindValue('moderator', $moderator, PDO::PARAM_BOOL);
        $fixture->execute();
    }
    $connection->commit();
    $connection = $open($schema);
    $repository = new ModerationRepository($connection);

    $assert(AccountAccessGuard::begin($connection, $target) === null, 'Fixture target starts without a ban.');
    $write = $connection->prepare("UPDATE users SET name = 'Write committed before ban' WHERE id = :id");
    $write->execute(['id' => $target]);
    $worker = $launch('ban', $actor, $target);
    $awaitBlocked($connection, $worker);
    $assert($repository->activeBanForUser($target) === null, 'The ban must wait until the earlier authenticated write commits.');
    AccountAccessGuard::finish();
    $banResult = $result($worker);
    $assert($banResult['result'] === true, 'The waiting ban should succeed after the writer finishes.');
    $assert($repository->activeBanForUser($target) !== null, 'The resulting ban must be visible.');
    $name = $connection->prepare('SELECT name FROM users WHERE id = :id');
    $name->execute(['id' => $target]);
    $assert($name->fetchColumn() === 'Write committed before ban', 'The prior authorized write must be retained.');
    echo '[OK] Ban czeka na zapis istniejącej sesji i zaczyna działać po jego zatwierdzeniu.' . PHP_EOL;
    $passed++;

    $ban = $repository->activeBanForUser($target);
    $assert($repository->revokeBan($actor, $ban['id'], 'Next isolated scenario', bin2hex(random_bytes(32))), 'Fixture ban must be revoked.');
    $assert(AccountAccessGuard::begin($connection, $actor) === null, 'Moderator starts without a ban.');
    $assert($repository->banUser($actor, $target, 'Ban committed while a queued request waits', 3600, bin2hex(random_bytes(32))), 'Uncommitted fixture ban should be created.');
    $worker = $launch('write', $target, $target);
    $awaitBlocked($connection, $worker);
    AccountAccessGuard::finish();
    $writeResult = $result($worker);
    $assert($writeResult['blocked'] === true && $writeResult['result'] === false, 'A request queued before ban commit must recheck and reject its write.');
    $name->execute(['id' => $target]);
    $assert($name->fetchColumn() === 'Write committed before ban', 'A queued banned writer must not change account data.');
    echo '[OK] Oczekujące żądanie po nadaniu bana sprawdza świeży stan i odrzuca zapis.' . PHP_EOL;
    $passed++;

    $ban = $repository->activeBanForUser($target);
    $assert($repository->revokeBan($actor, $ban['id'], 'Repeatable read fixture', bin2hex(random_bytes(32))), 'Fixture ban must be revoked.');
    AccountAccessGuard::begin($connection, $actor);
    $assert($repository->banUser($actor, $target, 'Ban before repeatable-read snapshot', 3600, bin2hex(random_bytes(32))), 'Fixture ban should be pending.');
    $worker = $launch('write_rr', $target, $target);
    $awaitBlocked($connection, $worker);
    AccountAccessGuard::finish();
    $writeResult = $result($worker);
    $assert($writeResult['isolation'] === 'repeatable read', 'Import guard must preserve repeatable-read isolation.');
    $assert($writeResult['blocked'] === true && $writeResult['result'] === false, 'Repeatable-read snapshot must be created after the ban-lock wait.');
    echo '[OK] Import tworzy stabilny snapshot dopiero po oczekiwaniu i również odrzuca zbanowane konto.' . PHP_EOL;
    $passed++;

    $ban = $repository->activeBanForUser($target);
    $assert($repository->revokeBan($actor, $ban['id'], 'Stable import snapshot fixture', bin2hex(random_bytes(32))), 'Fixture ban must be revoked.');
    $assert(AccountAccessGuard::begin($connection, $target, true) === null, 'Unbanned import guard should open.');
    $name->execute(['id' => $target]);
    $before = $name->fetchColumn();
    $worker = $launch('write', $target, $target);
    $assert($result($worker)['result'] === true, 'The concurrent allowed writer should commit.');
    $name->execute(['id' => $target]);
    $assert($name->fetchColumn() === $before, 'Repeatable-read import must retain its original snapshot.');
    AccountAccessGuard::finish();
    $assert((int) $connection->query("SELECT count(*) FROM pg_locks WHERE pid = pg_backend_pid() AND locktype = 'advisory' AND objsubid = 1 AND classid >= 2147483648")->fetchColumn() === 0, 'Finishing the import must release both session and transaction advisory locks.');
    $name->execute(['id' => $target]);
    $assert($name->fetchColumn() === 'Queued write must be rejected', 'A later request must observe the concurrently committed update.');
    echo '[OK] Import zachowuje jeden snapshot i zwalnia wszystkie blokady po zakończeniu.' . PHP_EOL;
    $passed++;

    $probe = $open($schema);
    $tryLock = $probe->prepare('SELECT pg_try_advisory_xact_lock(-CAST(:user AS bigint))');
    foreach ([true, false] as $success) {
        AccountAccessGuard::begin($connection, $target, true);
        $probe->beginTransaction();
        $tryLock->execute(['user' => $target]);
        $assert($tryLock->fetchColumn() === false, 'A separate connection cannot ban while the import shared lock is held.');
        $probe->rollBack();
        AccountAccessGuard::finish($success);
        $probe->beginTransaction();
        $tryLock->execute(['user' => $target]);
        $assert($tryLock->fetchColumn() === true, 'Both commit and rollback must release the session shared lock for another connection.');
        $probe->rollBack();
    }
    echo '[OK] Zatwierdzenie i wycofanie importu udostępniają blokadę innemu połączeniu.' . PHP_EOL;
    $passed++;

    $first = $launch('ban', $actor, $otherModerator);
    $second = $launch('ban', $otherModerator, $actor);
    $assert($result($first)['result'] === false && $result($second)['result'] === false, 'Mutual moderator-ban attempts must reject without an advisory deadlock.');
    $demote = $connection->prepare('UPDATE users SET is_moderator = false WHERE id = :id');
    $demote->execute(['id' => $actor]);
    $assert($repository->banUser($otherModerator, PHP_INT_MAX, 'Full BIGINT range fixture', 3600, bin2hex(random_bytes(32))), 'The largest positive PostgreSQL BIGINT ID must support an exclusive ban lock.');
    $assert(AccountAccessGuard::begin($connection, PHP_INT_MAX, true) !== null, 'The largest positive PostgreSQL BIGINT ID must support the shared import guard and see its ban.');
    AccountAccessGuard::finish(false);
    echo '[OK] Równoległe bany moderatorów nie zakleszczają się, a blokady obsługują pełny zakres BIGINT.' . PHP_EOL;
    $passed++;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] ' . $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
} finally {
    AccountAccessGuard::finish(false);
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            $state = proc_get_status($worker['process']);
            if ($state['running']) {
                proc_terminate($worker['process']);
            }
            fclose($worker['stdout']);
            fclose($worker['stderr']);
            proc_close($worker['process']);
        }
    }
    if ($created) {
        try {
            $cleanup = $open($schema);
            $cleanup->exec('SET search_path TO public');
            // $open validated the exact generated schema name and its ownership marker.
            $cleanup->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] Isolated schema cleanup: ' . $exception::class . PHP_EOL);
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
