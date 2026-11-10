<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\RecommendationService;
use InvalidArgumentException;
use PDO;

final class RecommendationRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<array{animeId: int, status: string, rating: ?int, genreIds: list<int>, type: string}> */
    public function signalsForUser(int $userId): array
    {
        $this->validateUserId($userId);
        $statement = $this->database->prepare(
            "SELECT w.anime_id, w.status, r.rating, a.media_type,
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
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<int, float> $genreWeights Normalized, signed preferences.
     * @param array<string, float> $typeWeights
     * @return list<array{animeId: int, matchedGenreIds: list<int>}>
     */
    public function findCandidatesForUser(int $userId, array $genreWeights, array $typeWeights, int $limit = 6): array
    {
        $this->validateUserId($userId);
        $this->validateWeights($genreWeights, true);
        $this->validateWeights($typeWeights, false);
        $personalized = count(array_filter($genreWeights, static fn (float $weight): bool => $weight > 0)) > 0;
        $condition = $personalized ? 'gm.genre_score > 0' : 'NOT COALESCE(gm.negative_match, FALSE)';
        $order = $personalized
            ? '(:genre_factor * gm.genre_score / SQRT(gm.genre_count::DOUBLE PRECISION)
                + :type_factor * COALESCE(tp.weight, 0)) DESC, '
            : '';
        $statement = $this->database->prepare(
            "WITH genre_preferences AS (
                SELECT key::BIGINT AS genre_id, value::DOUBLE PRECISION AS weight
                FROM jsonb_each_text(CAST(:genres AS JSONB))
             ), type_preferences AS (
                SELECT key AS media_type, value::DOUBLE PRECISION AS weight
                FROM jsonb_each_text(CAST(:types AS JSONB))
             ), genre_matches AS (
                SELECT ag.anime_id, COUNT(*) AS genre_count,
                       SUM(COALESCE(gp.weight, 0)) AS genre_score,
                       BOOL_OR(gp.weight < 0) AS negative_match,
                       jsonb_agg(ag.genre_id ORDER BY ag.genre_id) FILTER (WHERE gp.weight > 0) AS matched_genre_ids
                FROM anime_genres ag
                LEFT JOIN genre_preferences gp ON gp.genre_id = ag.genre_id
                GROUP BY ag.anime_id
             )
             SELECT a.id, COALESCE(gm.matched_genre_ids, '[]'::jsonb) AS matched_genre_ids
             FROM anime a
             LEFT JOIN genre_matches gm ON gm.anime_id = a.id
             LEFT JOIN type_preferences tp ON tp.media_type = CASE UPPER(TRIM(a.media_type))
                WHEN 'TV SPECIAL' THEN 'SPECIAL' ELSE UPPER(TRIM(a.media_type)) END
             WHERE NOT EXISTS (SELECT 1 FROM user_watchlist w WHERE w.user_id = :user_id AND w.anime_id = a.id)
               AND " . $condition . '
             ORDER BY ' . $order . 'a.score DESC NULLS LAST, LOWER(a.title), a.id LIMIT :limit'
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('genres', json_encode((object) $genreWeights, JSON_THROW_ON_ERROR));
        $statement->bindValue('types', json_encode((object) $typeWeights, JSON_THROW_ON_ERROR));
        $statement->bindValue('limit', max(1, min(12, $limit)), PDO::PARAM_INT);
        if ($personalized) {
            $statement->bindValue('genre_factor', RecommendationService::GENRE_WEIGHT, PDO::PARAM_INT);
            $statement->bindValue('type_factor', RecommendationService::TYPE_WEIGHT, PDO::PARAM_INT);
        }
        $statement->execute();

        return array_map(static fn (array $row): array => [
            'animeId' => (int) $row['id'],
            'matchedGenreIds' => array_map(intval(...), json_decode($row['matched_genre_ids'], true, 512, JSON_THROW_ON_ERROR)),
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function validateUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator użytkownika musi być dodatni.');
        }
    }

    private function validateWeights(array $weights, bool $genres): void
    {
        foreach ($weights as $key => $weight) {
            $validKey = $genres ? is_int($key) && $key > 0 : is_string($key) && $key !== '' && strlen($key) <= 20;
            if (!$validKey || (!is_float($weight) && !is_int($weight)) || !is_finite((float) $weight) || abs($weight) > 1) {
                throw new InvalidArgumentException('Nieprawidłowe wagi preferencji.');
            }
        }
    }
}
