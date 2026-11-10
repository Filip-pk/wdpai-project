<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;

final class RecommendationRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array> Watchlist metadata and only this account's private ratings. */
    public function signalsForUser(int $userId): array
    {
        $this->validateUserId($userId);
        $statement = $this->database->prepare(
            "SELECT w.anime_id, w.status, r.rating, a.title, a.studio, a.episodes, a.release_year, a.media_type,
                    COALESCE((SELECT jsonb_agg(ag.genre_id ORDER BY ag.genre_id)
                        FROM anime_genres ag WHERE ag.anime_id = a.id), '[]'::jsonb) AS genre_ids
             FROM user_watchlist w
             JOIN anime a ON a.id = w.anime_id
             LEFT JOIN user_anime_ratings r ON r.user_id = w.user_id AND r.anime_id = w.anime_id
             WHERE w.user_id = :user_id ORDER BY w.anime_id"
        );
        $statement->execute(['user_id' => $userId]);

        return array_map(static fn (array $row): array => [
            'animeId' => (int) $row['anime_id'],
            'status' => $row['status'],
            'rating' => $row['rating'] === null ? null : (int) $row['rating'],
            'genreIds' => array_map(intval(...), json_decode($row['genre_ids'], true, 512, JSON_THROW_ON_ERROR)),
            'type' => $row['media_type'],
            'title' => $row['title'],
            'studio' => $row['studio'],
            'episodes' => $row['episodes'] === null ? null : (int) $row['episodes'],
            'year' => $row['release_year'] === null ? null : (int) $row['release_year'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{total: int, genreCounts: array<int, int>} */
    public function catalogStatistics(): array
    {
        $row = $this->database->query(
            "SELECT (SELECT COUNT(*) FROM anime) AS total,
                    COALESCE(jsonb_object_agg(genre_id, genre_count), '{}'::jsonb) AS genre_counts
             FROM (SELECT genre_id, COUNT(*) AS genre_count FROM anime_genres GROUP BY genre_id) counts"
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'total' => (int) $row['total'],
            'genreCounts' => array_map(intval(...), json_decode($row['genre_counts'], true, 512, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param array<int, float> $genreWeights Normalized, signed preferences.
     * @return list<array> A bounded pool containing both overall and per-genre candidates.
     */
    public function candidatePoolForUser(int $userId, array $genreWeights, int $limit = 300): array
    {
        $this->validateUserId($userId);
        $this->validateWeights($genreWeights);
        $personalized = count(array_filter($genreWeights, static fn (float $weight): bool => $weight > 0)) > 0;
        $condition = $personalized ? 'gm.genre_score > 0' : 'NOT COALESCE(gm.negative_match, FALSE)';
        // Keep candidates from several tastes before the final quality and diversity ranking.
        $selection = $personalized ? ", overall AS (
                SELECT id, ROW_NUMBER() OVER (ORDER BY retrieval_score DESC, LOWER(title), id) AS position
                FROM eligible
             ), per_genre AS (
                SELECT e.id, ROW_NUMBER() OVER (PARTITION BY ag.genre_id
                    ORDER BY e.retrieval_score DESC, LOWER(e.title), e.id) AS position
                FROM eligible e JOIN anime_genres ag ON ag.anime_id = e.id
                JOIN genre_preferences gp ON gp.genre_id = ag.genre_id AND gp.weight > 0
             ), pool AS (
                SELECT id, MIN(position) AS position FROM (
                    SELECT id, position FROM overall WHERE position <= 120
                    UNION ALL
                    SELECT id, position FROM per_genre WHERE position <= 12
                ) picks GROUP BY id
             )
             SELECT e.* FROM eligible e JOIN pool p ON p.id = e.id
             ORDER BY p.position, e.retrieval_score DESC, LOWER(e.title), e.id LIMIT :limit"
            : ' SELECT * FROM eligible ORDER BY score DESC NULLS LAST, LOWER(title), id LIMIT :limit';
        $statement = $this->database->prepare(
            "WITH genre_preferences AS (
                SELECT key::BIGINT AS genre_id, value::DOUBLE PRECISION AS weight
                FROM jsonb_each_text(CAST(:genres AS JSONB))
             ), genre_matches AS (
                SELECT ag.anime_id, COUNT(*) AS genre_count,
                       SUM(COALESCE(gp.weight, 0)) AS genre_score,
                       BOOL_OR(gp.weight < 0) AS negative_match,
                       jsonb_agg(ag.genre_id ORDER BY ag.genre_id) AS genre_ids
                FROM anime_genres ag
                LEFT JOIN genre_preferences gp ON gp.genre_id = ag.genre_id
                GROUP BY ag.anime_id
             ), eligible AS (
                SELECT a.id, a.title, a.score, a.studio, a.media_type, a.episodes, a.release_year,
                       COALESCE(gm.genre_ids, '[]'::jsonb) AS genre_ids,
                       0.55 * GREATEST(COALESCE(gm.genre_score, 0), 0)
                           / SQRT(GREATEST(COALESCE(gm.genre_count, 0), 1)::DOUBLE PRECISION)
                           + 0.45 * COALESCE(a.score, 4) / 10 AS retrieval_score
                FROM anime a
                LEFT JOIN genre_matches gm ON gm.anime_id = a.id
                WHERE NOT EXISTS (SELECT 1 FROM user_watchlist w WHERE w.user_id = :user_id AND w.anime_id = a.id)
                  AND " . $condition . '
             )
             ' . $selection
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('genres', json_encode((object) $genreWeights, JSON_THROW_ON_ERROR));
        $statement->bindValue('limit', max(1, min(600, $limit)), PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'animeId' => (int) $row['id'],
            'title' => $row['title'],
            'score' => $row['score'] === null ? null : (float) $row['score'],
            'genreIds' => array_map(intval(...), json_decode($row['genre_ids'], true, 512, JSON_THROW_ON_ERROR)),
            'studio' => $row['studio'],
            'type' => $row['media_type'],
            'episodes' => $row['episodes'] === null ? null : (int) $row['episodes'],
            'year' => $row['release_year'] === null ? null : (int) $row['release_year'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function validateUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator użytkownika musi być dodatni.');
        }
    }

    private function validateWeights(array $weights): void
    {
        foreach ($weights as $key => $weight) {
            $validKey = is_int($key) && $key > 0;
            if (!$validKey || (!is_float($weight) && !is_int($weight)) || !is_finite((float) $weight) || abs($weight) > 1) {
                throw new InvalidArgumentException('Nieprawidłowe wagi preferencji.');
            }
        }
    }
}
