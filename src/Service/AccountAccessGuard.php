<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ModerationRepository;
use PDO;

/** One transaction and connection for the entire authenticated HTTP request. */
final class AccountAccessGuard
{
    public const LOCK_NAMESPACE = 74123;

    private static ?PDO $connection = null;
    private static ?int $sessionLockUserId = null;

    public static function begin(PDO $connection, int $userId, bool $repeatableRead = false): ?array
    {
        self::$connection = $connection;
        if ($repeatableRead) {
            // Imports need a stable snapshot. Acquire the ban lock BEFORE creating that
            // snapshot so a writer queued behind a ban cannot read a pre-ban snapshot.
            $lock = $connection->prepare('SELECT pg_advisory_lock_shared(:namespace, :userId)');
            $lock->execute(['namespace' => self::LOCK_NAMESPACE, 'userId' => $userId]);
            self::$sessionLockUserId = $userId;
        }
        $connection->beginTransaction();
        if ($repeatableRead) {
            $connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        // Ban decisions take the exclusive version of this lock before locking users.
        // A pending writer either commits before the ban or sees it after this wait.
        $lock = $connection->prepare('SELECT pg_advisory_xact_lock_shared(:namespace, :userId)');
        $lock->execute(['namespace' => self::LOCK_NAMESPACE, 'userId' => $userId]);

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
                $unlock = $connection->prepare('SELECT pg_advisory_unlock_shared(:namespace, :userId)');
                $unlock->execute(['namespace' => self::LOCK_NAMESPACE, 'userId' => self::$sessionLockUserId]);
                self::$sessionLockUserId = null;
            }
        }
    }
}
