<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;

final class RatingRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function findForUser(int $userId, int $animeId): ?int
    {
        $statement = $this->database->prepare(
            'SELECT rating FROM user_anime_ratings WHERE user_id = :user_id AND anime_id = :anime_id'
        );
        $statement->execute(['user_id' => $userId, 'anime_id' => $animeId]);
        $rating = $statement->fetchColumn();

        return $rating === false ? null : (int) $rating;
    }

    /** @param list<int> $animeIds @return array<int, int> */
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
        $animeIds = array_values(array_unique($animeIds));
        $parameters = ['user_id' => $userId];
        $placeholders = [];
        foreach ($animeIds as $index => $animeId) {
            $placeholders[] = ':anime_' . $index;
            $parameters['anime_' . $index] = $animeId;
        }
        $statement = $this->database->prepare(
            'SELECT anime_id, rating FROM user_anime_ratings
             WHERE user_id = :user_id AND anime_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY anime_id'
        );
        $statement->execute($parameters);
        $ratings = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ratings[(int) $row['anime_id']] = (int) $row['rating'];
        }

        return $ratings;
    }

    public function saveForUser(int $userId, int $animeId, int $rating): void
    {
        if ($rating < 1 || $rating > 10) {
            throw new InvalidArgumentException('Ocena musi być liczbą całkowitą od 1 do 10.');
        }
        $statement = $this->database->prepare(
            'INSERT INTO user_anime_ratings (user_id, anime_id, rating)
             VALUES (:user_id, :anime_id, :rating)
             ON CONFLICT (user_id, anime_id)
             DO UPDATE SET rating = EXCLUDED.rating, updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute(['user_id' => $userId, 'anime_id' => $animeId, 'rating' => $rating]);
    }

    public function removeForUser(int $userId, int $animeId): void
    {
        $statement = $this->database->prepare(
            'DELETE FROM user_anime_ratings WHERE user_id = :user_id AND anime_id = :anime_id'
        );
        $statement->execute(['user_id' => $userId, 'anime_id' => $animeId]);
    }
}
