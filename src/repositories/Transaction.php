<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

/**
 * Runs repository work atomically. Outside a transaction it opens and commits its own;
 * inside one (every signed-in request already has one, see AccountAccessGuard) it uses a
 * savepoint, so a failure undoes only this work and the outer transaction stays usable.
 */
final class Transaction
{
    private static int $sequence = 0;

    /**
     * @template T
     * @param callable(): T $operation
     * @param ?callable(T): bool $keep Decides from the result whether to keep the changes; when it
     *                                 returns false they are rolled back and the result is still returned.
     * @param ?string $isolation Isolation level for a transaction this call opens itself.
     * @return T
     */
    public static function run(PDO $database, callable $operation, ?callable $keep = null, ?string $isolation = null): mixed
    {
        // Only known levels reach the SQL; an unknown one fails before anything is opened.
        $isolation = $isolation === null ? null : match ($isolation) {
            'REPEATABLE READ', 'SERIALIZABLE', 'READ COMMITTED' => $isolation,
        };
        $ownsTransaction = !$database->inTransaction();
        $savepoint = 'anivibe_' . ++self::$sequence;
        if ($ownsTransaction) {
            $database->beginTransaction();
            if ($isolation !== null) {
                $database->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolation);
            }
        } else {
            $database->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            self::undo($database, $ownsTransaction, $savepoint);
            throw $exception;
        }

        if ($keep !== null && !$keep($result)) {
            self::undo($database, $ownsTransaction, $savepoint);
        } elseif ($ownsTransaction) {
            $database->commit();
        } else {
            $database->exec('RELEASE SAVEPOINT ' . $savepoint);
        }

        return $result;
    }

    private static function undo(PDO $database, bool $ownsTransaction, string $savepoint): void
    {
        if (!$database->inTransaction()) {
            return;
        }
        if ($ownsTransaction) {
            $database->rollBack();
        } else {
            $database->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $database->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    }
}
