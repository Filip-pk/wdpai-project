<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Counts failures in the window for one account from one client and for the whole client.
     *
     * @return array{accountFailures: int, clientFailures: int, accountRetryAfter: int, clientRetryAfter: int}
     */
    public function recentFailures(string $emailHash, string $ipHash, int $windowSeconds): array
    {
        $statement = $this->database->prepare(
            'WITH recent AS (
                SELECT email_hash = :email_hash AS same_account, attempted_at
                FROM login_attempts
                WHERE ip_hash = :ip_hash
                    AND attempted_at > clock_timestamp() - make_interval(secs => :window_seconds)
            )
            SELECT COUNT(*) FILTER (WHERE same_account) AS account_failures,
                COUNT(*) AS client_failures,
                EXTRACT(EPOCH FROM clock_timestamp() - MIN(attempted_at) FILTER (WHERE same_account)) AS account_age,
                EXTRACT(EPOCH FROM clock_timestamp() - MIN(attempted_at)) AS client_age
            FROM recent'
        );
        $statement->bindValue('email_hash', $emailHash);
        $statement->bindValue('ip_hash', $ipHash);
        $statement->bindValue('window_seconds', $windowSeconds, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        // The oldest failure in the window leaves it first, which frees the next attempt.
        $retryAfter = static fn (mixed $age): int => max(1, $windowSeconds - (int) floor((float) ($age ?? 0)));

        return [
            'accountFailures' => (int) ($row['account_failures'] ?? 0),
            'clientFailures' => (int) ($row['client_failures'] ?? 0),
            'accountRetryAfter' => $retryAfter($row['account_age'] ?? null),
            'clientRetryAfter' => $retryAfter($row['client_age'] ?? null),
        ];
    }

    public function recordFailure(string $emailHash, string $ipHash, int $retentionSeconds): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO login_attempts (email_hash, ip_hash) VALUES (:email_hash, :ip_hash)'
        );
        $statement->execute(['email_hash' => $emailHash, 'ip_hash' => $ipHash]);

        $cleanup = $this->database->prepare(
            'DELETE FROM login_attempts WHERE attempted_at < clock_timestamp() - make_interval(secs => :retention_seconds)'
        );
        $cleanup->bindValue('retention_seconds', $retentionSeconds, PDO::PARAM_INT);
        $cleanup->execute();
    }

    public function clearAccount(string $emailHash, string $ipHash): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM login_attempts WHERE email_hash = :email_hash AND ip_hash = :ip_hash'
        );
        $statement->execute(['email_hash' => $emailHash, 'ip_hash' => $ipHash]);
    }
}
