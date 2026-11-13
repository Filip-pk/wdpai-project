<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

final class SecuritySettingsRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function passwordHashForUser(int $userId): ?string
    {
        $statement = $this->database->prepare('SELECT password_hash FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $hash = $statement->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function changePassword(int $userId, string $expectedHash, string $newHash): bool
    {
        $statement = $this->database->prepare(
            'UPDATE users SET password_hash = :newHash WHERE id = :id AND password_hash = :expectedHash'
        );
        $statement->execute(['id' => $userId, 'expectedHash' => $expectedHash, 'newHash' => $newHash]);

        return $statement->rowCount() === 1;
    }

    public function requestEmailChange(int $userId, string $email, string $tokenHash, string $passwordHash): bool
    {
        $statement = $this->database->prepare(
            "INSERT INTO user_email_changes (user_id, new_email, token_hash, password_hash, expires_at)
             VALUES (:id, :email, :tokenHash, :passwordHash, CURRENT_TIMESTAMP + INTERVAL '30 minutes')
             ON CONFLICT (user_id) DO UPDATE SET new_email = EXCLUDED.new_email,
                 token_hash = EXCLUDED.token_hash, password_hash = EXCLUDED.password_hash,
                 requested_at = CURRENT_TIMESTAMP, expires_at = EXCLUDED.expires_at
             WHERE user_email_changes.requested_at <= CURRENT_TIMESTAMP - INTERVAL '60 seconds'"
        );
        $statement->execute([
            'id' => $userId, 'email' => $email, 'tokenHash' => $tokenHash, 'passwordHash' => $passwordHash,
        ]);

        return $statement->rowCount() === 1;
    }

    public function cancelEmailChange(int $userId, string $tokenHash): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM user_email_changes WHERE user_id = :id AND token_hash = :tokenHash'
        );
        $statement->execute(['id' => $userId, 'tokenHash' => $tokenHash]);
    }

    public function pendingEmailForUser(int $userId): ?string
    {
        $statement = $this->database->prepare(
            'SELECT pending.new_email FROM user_email_changes pending
             JOIN users account ON account.id = pending.user_id AND account.password_hash = pending.password_hash
             WHERE pending.user_id = :id AND pending.expires_at > CURRENT_TIMESTAMP'
        );
        $statement->execute(['id' => $userId]);
        $email = $statement->fetchColumn();

        return is_string($email) ? $email : null;
    }

    public function confirmEmailChange(int $userId, string $tokenHash): ?string
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        } else {
            $this->database->exec('SAVEPOINT confirm_email_change');
        }

        try {
            // Serializacja z równoległą zmianą hasła lub danych tego samego konta.
            $user = $this->database->prepare('SELECT password_hash FROM users WHERE id = :id FOR UPDATE');
            $user->execute(['id' => $userId]);
            $currentHash = $user->fetchColumn();
            $pending = $this->database->prepare(
                'DELETE FROM user_email_changes
                 WHERE user_id = :id AND token_hash = :tokenHash
                    AND expires_at > CURRENT_TIMESTAMP AND password_hash = :passwordHash
                 RETURNING new_email'
            );
            $pending->execute(['id' => $userId, 'tokenHash' => $tokenHash, 'passwordHash' => $currentHash ?: '']);
            $email = $pending->fetchColumn();

            if (!is_string($email)) {
                if ($ownsTransaction) {
                    $this->database->rollBack();
                } else {
                    $this->database->exec('ROLLBACK TO SAVEPOINT confirm_email_change');
                    $this->database->exec('RELEASE SAVEPOINT confirm_email_change');
                }
                return null;
            }

            $update = $this->database->prepare('UPDATE users SET email = :email WHERE id = :id');
            $update->execute(['id' => $userId, 'email' => $email]);
            if ($ownsTransaction) {
                $this->database->commit();
            } else {
                $this->database->exec('RELEASE SAVEPOINT confirm_email_change');
            }

            return $email;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            } elseif (!$ownsTransaction && $this->database->inTransaction()) {
                $this->database->exec('ROLLBACK TO SAVEPOINT confirm_email_change');
                $this->database->exec('RELEASE SAVEPOINT confirm_email_change');
            }
            throw $exception;
        }
    }
}
