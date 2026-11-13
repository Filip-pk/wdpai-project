<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;

final class PrivacySettingsRepository
{
    public const MESSAGE_POLICIES = ['everyone', 'existing', 'nobody'];

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{messagePolicy: string, publicRatings: bool, publicStatistics: bool} */
    public function findForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT message_policy, public_ratings, public_statistics FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false
            ? ['messagePolicy' => 'nobody', 'publicRatings' => false, 'publicStatistics' => false]
            : [
                'messagePolicy' => (string) $row['message_policy'],
                'publicRatings' => in_array($row['public_ratings'], [true, 1, '1', 't'], true),
                'publicStatistics' => in_array($row['public_statistics'], [true, 1, '1', 't'], true),
            ];
    }

    public function saveForUser(int $userId, string $messagePolicy, bool $publicRatings, bool $publicStatistics): void
    {
        if (!in_array($messagePolicy, self::MESSAGE_POLICIES, true)) {
            throw new InvalidArgumentException('Nieprawidłowe ustawienie wiadomości.');
        }

        // UPDATE locks the same account row used by message writes, serializing policy changes.
        $statement = $this->database->prepare(
            'UPDATE users SET message_policy = :policy, public_ratings = :ratings,
                public_statistics = :statistics WHERE id = :id'
        );
        $statement->bindValue('policy', $messagePolicy);
        $statement->bindValue('ratings', $publicRatings, PDO::PARAM_BOOL);
        $statement->bindValue('statistics', $publicStatistics, PDO::PARAM_BOOL);
        $statement->bindValue('id', $userId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function hasConversation(int $userId, int $otherId): bool
    {
        $statement = $this->database->prepare(
            'SELECT 1 FROM private_conversations WHERE first_user_id = :first AND second_user_id = :second'
        );
        $statement->execute(['first' => min($userId, $otherId), 'second' => max($userId, $otherId)]);
        return $statement->fetchColumn() !== false;
    }
}
