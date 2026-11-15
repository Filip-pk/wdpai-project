<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;
use Throwable;

final class ProfileShowcaseRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{animeIds: list<int>, badgeCodes: list<string>} */
    public function findForUser(int $userId): array
    {
        $this->validateUser($userId);
        // One statement keeps both halves of the showcase in the same database snapshot.
        $statement = $this->database->prepare(
            "SELECT 'anime' AS kind, position, anime_id, NULL::varchar AS achievement_code
             FROM user_showcase_anime WHERE user_id = :anime_user
             UNION ALL
             SELECT 'badge' AS kind, position, NULL::bigint AS anime_id, achievement_code
             FROM user_showcase_badges WHERE user_id = :badge_user
             ORDER BY kind, position"
        );
        $statement->execute(['anime_user' => $userId, 'badge_user' => $userId]);
        $selection = ['animeIds' => [], 'badgeCodes' => []];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['kind'] === 'anime') {
                $selection['animeIds'][] = (int) $row['anime_id'];
            } else {
                $selection['badgeCodes'][] = (string) $row['achievement_code'];
            }
        }

        return $selection;
    }

    /** Replace both lists atomically, preserving the submitted order and any outer transaction. */
    public function saveForUser(int $userId, array $animeIds, array $badgeCodes): void
    {
        $this->validateUser($userId);
        if (!array_is_list($animeIds) || !array_is_list($badgeCodes)
            || count($animeIds) > 3 || count($badgeCodes) > 3) {
            throw new InvalidArgumentException('Gablotka mieści do trzech anime i trzech odznak.');
        }
        foreach ($animeIds as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Nieprawidłowe anime w gablotce.');
            }
        }
        foreach ($badgeCodes as $code) {
            if (!is_string($code) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $code) !== 1) {
                throw new InvalidArgumentException('Nieprawidłowa odznaka w gablotce.');
            }
        }
        if (count(array_unique($animeIds)) !== count($animeIds)
            || count(array_unique($badgeCodes)) !== count($badgeCodes)) {
            throw new InvalidArgumentException('Elementy gablotki nie mogą się powtarzać.');
        }

        $ownsTransaction = !$this->database->inTransaction();
        $savepoint = 'showcase_' . bin2hex(random_bytes(6));
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        } else {
            $this->database->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            // Lock the account, including an initially empty showcase, before replacing either list.
            $lock = $this->database->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
            $lock->execute(['user_id' => $userId]);
            if ($lock->fetchColumn() === false) {
                throw new InvalidArgumentException('Nie znaleziono konta gablotki.');
            }
            foreach (['user_showcase_anime', 'user_showcase_badges'] as $table) {
                $this->database->prepare('DELETE FROM ' . $table . ' WHERE user_id = :user_id')
                    ->execute(['user_id' => $userId]);
            }
            $anime = $this->database->prepare(
                'INSERT INTO user_showcase_anime (user_id, position, anime_id) VALUES (:user_id, :position, :anime_id)'
            );
            foreach ($animeIds as $index => $id) {
                $anime->execute(['user_id' => $userId, 'position' => $index + 1, 'anime_id' => $id]);
            }
            $badge = $this->database->prepare(
                'INSERT INTO user_showcase_badges (user_id, position, achievement_code)
                 VALUES (:user_id, :position, :achievement_code)'
            );
            foreach ($badgeCodes as $index => $code) {
                $badge->execute(['user_id' => $userId, 'position' => $index + 1, 'achievement_code' => $code]);
            }
            if ($ownsTransaction) {
                $this->database->commit();
            } else {
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            } elseif (!$ownsTransaction && $this->database->inTransaction()) {
                $this->database->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    private function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator konta musi być dodatni.');
        }
    }
}
