<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class WatchlistRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array{animeId: int, status: string, watched: int}> */
    public function findForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT anime_id, status, watched FROM user_watchlist WHERE user_id = :user_id ORDER BY anime_id'
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): array => [
            'animeId' => (int) $row['anime_id'],
            'status' => (string) $row['status'],
            'watched' => (int) $row['watched'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addForUser(int $userId, int $animeId): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO user_watchlist (user_id, anime_id) VALUES (:user_id, :anime_id)
             ON CONFLICT (user_id, anime_id) DO NOTHING'
        );
        $statement->execute(['user_id' => $userId, 'anime_id' => $animeId]);
    }

    public function saveForUser(int $userId, int $animeId, string $status, int $watched): void
    {
        $statement = $this->database->prepare(
            "WITH saved AS (
                INSERT INTO user_watchlist (user_id, anime_id, status, watched)
                VALUES (:user_id, :anime_id, :status, :watched)
                ON CONFLICT (user_id, anime_id)
                DO UPDATE SET status = EXCLUDED.status, watched = EXCLUDED.watched
                RETURNING user_id, anime_id, status, watched
             )
             INSERT INTO user_anime_experience (user_id, anime_id, watched, completed)
             SELECT user_id, anime_id, watched, status = 'completed' FROM saved WHERE TRUE
             ON CONFLICT (user_id, anime_id)
             DO UPDATE SET watched = EXCLUDED.watched, completed = EXCLUDED.completed,
                           updated_at = CURRENT_TIMESTAMP"
        );
        $statement->execute([
            'user_id' => $userId,
            'anime_id' => $animeId,
            'status' => $status,
            'watched' => $watched,
        ]);
    }

    public function removeForUser(int $userId, int $animeId): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM user_watchlist WHERE user_id = :user_id AND anime_id = :anime_id'
        );
        $statement->execute(['user_id' => $userId, 'anime_id' => $animeId]);
    }
}
