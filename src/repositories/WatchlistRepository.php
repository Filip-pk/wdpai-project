<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
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

        return array_map($this->mapEntry(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{entries: list<array{animeId: int, status: string, watched: int}>, page: int, totalPages: int, total: int, watchlistTotal: int, completedCount: int} */
    public function findPageForUser(int $userId, int $page = 1, int $perPage = 24, string $status = ''): array
    {
        $filteredCount = $status === '' ? 'count(*)' : 'count(*) FILTER (WHERE status = :status)';
        $statement = $this->database->prepare(
            "SELECT count(*) AS watchlist_total,
                    count(*) FILTER (WHERE status = 'completed') AS completed_count,
                    " . $filteredCount . ' AS total
             FROM user_watchlist WHERE user_id = :user_id'
        );
        $parameters = ['user_id' => $userId];
        if ($status !== '') {
            $parameters['status'] = $status;
        }
        $statement->execute($parameters);
        $counts = $statement->fetch(PDO::FETCH_ASSOC);
        $total = (int) $counts['total'];
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $statement = $this->database->prepare(
            'SELECT anime_id, status, watched FROM user_watchlist WHERE user_id = :user_id'
            . ($status === '' ? '' : ' AND status = :status')
            . ' ORDER BY anime_id LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($status !== '') {
            $statement->bindValue(':status', $status);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'entries' => array_map($this->mapEntry(...), $statement->fetchAll(PDO::FETCH_ASSOC)),
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'watchlistTotal' => (int) $counts['watchlist_total'],
            'completedCount' => (int) $counts['completed_count'],
        ];
    }

    /** @param list<int> $animeIds @return list<array{animeId: int, status: string, watched: int}> */
    public function findByAnimeIdsForUser(int $userId, array $animeIds): array
    {
        if ($animeIds === []) {
            return [];
        }
        foreach ($animeIds as $animeId) {
            if (!is_int($animeId) || $animeId <= 0) {
                throw new InvalidArgumentException('Identyfikatory anime muszą być dodatnimi liczbami całkowitymi.');
            }
        }
        $parameters = ['user_id' => $userId];
        $placeholders = [];
        foreach (array_values(array_unique($animeIds)) as $index => $animeId) {
            $placeholders[] = ':anime_' . $index;
            $parameters['anime_' . $index] = $animeId;
        }
        $statement = $this->database->prepare(
            'SELECT anime_id, status, watched FROM user_watchlist
             WHERE user_id = :user_id AND anime_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY anime_id'
        );
        $statement->execute($parameters);

        return array_map($this->mapEntry(...), $statement->fetchAll(PDO::FETCH_ASSOC));
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

    /** @return array{animeId: int, status: string, watched: int} */
    private function mapEntry(array $row): array
    {
        return [
            'animeId' => (int) $row['anime_id'],
            'status' => (string) $row['status'],
            'watched' => (int) $row['watched'],
        ];
    }
}
