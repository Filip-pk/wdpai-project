<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Deletes data that has outlived its purpose. Every query is safe to run again. */
final class MaintenanceRepository
{
    // Two-part advisory key: 746562 is AniVibe's namespace, 33 identifies the cleanup task.
    private const LOCK = [746562, 33];

    public function __construct(private readonly PDO $database)
    {
    }

    /** Session lock, so a slow run and the next scheduled one never overlap. */
    public function tryLock(): bool
    {
        $statement = $this->database->prepare('SELECT pg_try_advisory_lock(:namespace, :task)');
        $statement->execute(['namespace' => self::LOCK[0], 'task' => self::LOCK[1]]);

        return in_array($statement->fetchColumn(), [true, 1, '1', 't'], true);
    }

    public function unlock(): void
    {
        $statement = $this->database->prepare('SELECT pg_advisory_unlock(:namespace, :task)');
        $statement->execute(['namespace' => self::LOCK[0], 'task' => self::LOCK[1]]);
    }

    /** Failed sign-ins no longer count towards the limit after the retention period. */
    public function deleteLoginAttemptsOlderThan(int $seconds): int
    {
        return $this->deleteOlderThan('login_attempts', 'attempted_at', $seconds);
    }

    /** Rate-limit history is only read for the last few minutes. */
    public function deleteActivityOlderThan(int $seconds): int
    {
        return $this->deleteOlderThan('community_activity', 'created_at', $seconds);
    }

    /** An expired e-mail change can no longer be confirmed, but still holds a token and a password hash. */
    public function deleteExpiredEmailChanges(): int
    {
        $statement = $this->database->prepare('DELETE FROM user_email_changes WHERE expires_at <= CURRENT_TIMESTAMP');
        $statement->execute();

        return $statement->rowCount();
    }

    /** @return list<string> */
    public function avatarPaths(): array
    {
        return $this->database->query('SELECT avatar_path FROM users WHERE avatar_path IS NOT NULL')
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param 'login_attempts'|'community_activity' $table */
    private function deleteOlderThan(string $table, string $column, int $seconds): int
    {
        $statement = $this->database->prepare(
            'DELETE FROM ' . $table . ' WHERE ' . $column . ' < clock_timestamp() - make_interval(secs => :seconds)'
        );
        $statement->bindValue('seconds', $seconds, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }
}
