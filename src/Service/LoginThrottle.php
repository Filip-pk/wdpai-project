<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LoginAttemptRepository;

/** Limits failed sign-ins for one account from one client and for the whole client. */
final class LoginThrottle
{
    public const ACCOUNT_LIMIT = 5;
    public const CLIENT_LIMIT = 100;
    public const WINDOW_SECONDS = 900;
    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly LoginAttemptRepository $attempts)
    {
    }

    /** Returns the number of seconds to wait, or 0 when another attempt is allowed. */
    public function retryAfter(string $email, string $clientAddress): int
    {
        $failures = $this->attempts->recentFailures(
            self::fingerprint($email), self::fingerprint($clientAddress), self::WINDOW_SECONDS,
        );
        $wait = 0;
        if ($failures['accountFailures'] >= self::ACCOUNT_LIMIT) {
            $wait = max($wait, $failures['accountRetryAfter']);
        }
        if ($failures['clientFailures'] >= self::CLIENT_LIMIT) {
            $wait = max($wait, $failures['clientRetryAfter']);
        }

        return min($wait, self::WINDOW_SECONDS);
    }

    public function recordFailure(string $email, string $clientAddress): void
    {
        $this->attempts->recordFailure(
            self::fingerprint($email), self::fingerprint($clientAddress), self::RETENTION_SECONDS,
        );
    }

    public function recordSuccess(string $email, string $clientAddress): void
    {
        $this->attempts->clearAccount(self::fingerprint($email), self::fingerprint($clientAddress));
    }

    /** A one-way identifier, so neither the address nor the e-mail is stored in plain text. */
    public static function fingerprint(string $value): string
    {
        return hash('sha256', $value);
    }
}
