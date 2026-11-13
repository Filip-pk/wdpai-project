<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ModerationRepository;
use PDO;

/** One transaction and connection for the entire authenticated HTTP request. */
final class AccountAccessGuard
{
    private static ?PDO $connection = null;
    private static ?int $sessionLockUserId = null;

    public static function begin(PDO $connection, int $userId, bool $repeatableRead = false): ?array
    {
        self::$connection = $connection;
        // Reserve negative user IDs in the bigint advisory keyspace for account access.
        // Positive BIGSERIAL IDs therefore retain their full range without collisions.
        if ($repeatableRead) {
            // Imports need a stable snapshot. Acquire the ban lock BEFORE creating that
            // snapshot so a writer queued behind a ban cannot read a pre-ban snapshot.
            $lock = $connection->prepare('SELECT pg_advisory_lock_shared(-CAST(:userId AS bigint))');
            $lock->execute(['userId' => $userId]);
            self::$sessionLockUserId = $userId;
        }
        $connection->beginTransaction();
        if ($repeatableRead) {
            $connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        // Ban decisions take the exclusive version of this lock before locking users.
        // A pending writer either commits before the ban or sees it after this wait.
        $lock = $connection->prepare('SELECT pg_advisory_xact_lock_shared(-CAST(:userId AS bigint))');
        $lock->execute(['userId' => $userId]);

        return (new ModerationRepository($connection))->activeBanForUser($userId);
    }

    public static function finish(bool $success = true): void
    {
        $connection = self::$connection;
        self::$connection = null;
        try {
            if ($connection !== null && $connection->inTransaction()) {
                if ($success) {
                    $connection->commit();
                } else {
                    $connection->rollBack();
                }
            }
        } finally {
            if ($connection !== null && self::$sessionLockUserId !== null) {
                $unlock = $connection->prepare('SELECT pg_advisory_unlock_shared(-CAST(:userId AS bigint))');
                $unlock->execute(['userId' => self::$sessionLockUserId]);
                self::$sessionLockUserId = null;
            }
        }
    }
}
