<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\AnimeRankingCriteria;
use InvalidArgumentException;
use PDO;

final class AnimeRankingRepository
{
    private const TOTALS = '
        WITH ratings AS (
            SELECT anime_id, AVG(rating) AS community_average, COUNT(*) AS community_count
            FROM user_anime_ratings GROUP BY anime_id
        ), viewers AS (
            SELECT anime_id, COUNT(*) AS watcher_count FROM user_watchlist
            WHERE status IN (\'watching\', \'completed\') GROUP BY anime_id
        ) ';

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{entries: list<array{id: int, rank: int, communityAverage: ?float, communityCount: int, watcherCount: int}>, total: int, page: int, totalPages: int} */
    public function findPage(AnimeRankingCriteria $criteria, int $perPage = 20): array
    {
        if ($criteria->errors !== []) {
            throw new InvalidArgumentException('Nieprawidłowe kryteria rankingu anime.');
        }
        $conditions = [$criteria->mode === 'best' ? 'ratings.community_count >= :min_votes' : 'viewers.watcher_count > 0'];
        $parameters = $criteria->mode === 'best' ? ['min_votes' => $criteria->minVotes] : [];
        if ($criteria->genreId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM anime_genres WHERE anime_id = anime.id AND genre_id = CAST(:genre_id AS BIGINT))';
            $parameters['genre_id'] = $criteria->genreId;
        }
        if ($criteria->year !== null) {
            $conditions[] = 'anime.release_year = :year';
            $parameters['year'] = $criteria->year;
        }
        $from = ' FROM anime
            LEFT JOIN ratings ON ratings.anime_id = anime.id
            LEFT JOIN viewers ON viewers.anime_id = anime.id
            WHERE ' . implode(' AND ', $conditions);
        $count = $this->database->prepare(self::TOTALS . 'SELECT COUNT(*)' . $from);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($criteria->page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $order = $criteria->mode === 'best'
            ? 'ratings.community_average DESC NULLS LAST, ratings.community_count DESC, LOWER(anime.title), anime.id'
            : 'viewers.watcher_count DESC, ratings.community_average DESC NULLS LAST, ratings.community_count DESC NULLS LAST, LOWER(anime.title), anime.id';
        $statement = $this->database->prepare(
            self::TOTALS . 'SELECT anime.id, ratings.community_average,
                COALESCE(ratings.community_count, 0) AS community_count,
                COALESCE(viewers.watcher_count, 0) AS watcher_count'
            . $from . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_INT);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $entries = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index => $row) {
            $entries[] = [
                'id' => (int) $row['id'], 'rank' => $offset + $index + 1,
                'communityAverage' => $row['community_average'] === null ? null : (float) $row['community_average'],
                'communityCount' => (int) $row['community_count'], 'watcherCount' => (int) $row['watcher_count'],
            ];
        }

        return ['entries' => $entries, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
    }
}
