<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\MalImportChangedException;
use PDO;
use PDOException;
use Throwable;

final class MalImportRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @param list<int> $malIds @return array<int, array> */
    public function statesForUser(int $userId, array $malIds): array
    {
        $states = [];
        foreach (array_chunk(array_values(array_unique($malIds)), 1000) as $chunk) {
            $parameters = ['watchlist_user' => $userId, 'rating_user' => $userId, 'experience_user' => $userId];
            $placeholders = [];
            foreach ($chunk as $index => $malId) {
                $placeholders[] = ':mal_' . $index;
                $parameters['mal_' . $index] = $malId;
            }
            $statement = $this->database->prepare(
                'SELECT anime.id, anime.mal_id, anime.title, anime.episodes,
                        watchlist.status, watchlist.watched, ratings.rating,
                        experience.watched AS credited_watched, experience.completed AS credited_completed
                 FROM anime
                 LEFT JOIN user_watchlist watchlist ON watchlist.anime_id = anime.id AND watchlist.user_id = :watchlist_user
                 LEFT JOIN user_anime_ratings ratings ON ratings.anime_id = anime.id AND ratings.user_id = :rating_user
                 LEFT JOIN user_anime_experience experience ON experience.anime_id = anime.id AND experience.user_id = :experience_user
                 WHERE anime.mal_id IN (' . implode(', ', $placeholders) . ') ORDER BY anime.mal_id'
            );
            $statement->execute($parameters);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $states[(int) $row['mal_id']] = [
                    'animeId' => (int) $row['id'], 'title' => (string) $row['title'],
                    'episodes' => $row['episodes'] === null ? null : (int) $row['episodes'],
                    'status' => $row['status'] === null ? null : (string) $row['status'],
                    'watched' => $row['watched'] === null ? null : (int) $row['watched'],
                    'rating' => $row['rating'] === null ? null : (int) $row['rating'],
                    'creditedWatched' => $row['credited_watched'] === null ? null : (int) $row['credited_watched'],
                    'creditedCompleted' => $row['credited_watched'] === null ? null
                        : in_array($row['credited_completed'], [true, 1, '1', 't'], true),
                ];
            }
        }
        ksort($states);
        return $states;
    }

    public function totalExpForUser(int $userId): int
    {
        $statement = $this->database->prepare(
            'SELECT COALESCE(SUM(watched::BIGINT * 5 + CASE WHEN completed THEN 100 ELSE 0 END), 0)
             FROM user_anime_experience WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    /** Runs the confirmation against one snapshot and rolls back all entries on failure. */
    public function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        $savepoint = 'mal_import_' . bin2hex(random_bytes(6));
        if ($ownsTransaction) {
            $this->database->beginTransaction();
            $this->database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } else {
            $this->database->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->database->commit();
            } else {
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            } elseif (!$ownsTransaction && $this->database->inTransaction()) {
                $this->database->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            if ($exception instanceof PDOException && in_array($exception->getCode(), ['40001', '40P01', '23503'], true)) {
                throw new MalImportChangedException('Dane zmieniły się podczas importu. Sprawdź nowy podgląd i zatwierdź go ponownie.', previous: $exception);
            }
            throw $exception;
        }
    }

    /** @param list<array> $rows */
    public function saveRowsForUser(int $userId, array $rows): void
    {
        foreach (array_chunk($rows, 400) as $chunk) {
            $parameters = [];
            $values = [];
            foreach ($chunk as $index => $row) {
                $values[] = '(:user_' . $index . ', :anime_' . $index . ', :status_' . $index . ', :watched_' . $index . ')';
                $parameters['user_' . $index] = $userId;
                $parameters['anime_' . $index] = $row['animeId'];
                $parameters['status_' . $index] = $row['status'];
                $parameters['watched_' . $index] = $row['watched'];
            }
            $statement = $this->database->prepare(
                "WITH saved AS (
                    INSERT INTO user_watchlist (user_id, anime_id, status, watched)
                    VALUES " . implode(', ', $values) . "
                    ON CONFLICT (user_id, anime_id)
                    DO UPDATE SET status = EXCLUDED.status, watched = EXCLUDED.watched
                    RETURNING user_id, anime_id, status, watched
                 )
                 INSERT INTO user_anime_experience (user_id, anime_id, watched, completed)
                 SELECT user_id, anime_id, watched, status = 'completed' FROM saved WHERE TRUE
                 ON CONFLICT (user_id, anime_id)
                 DO UPDATE SET watched = EXCLUDED.watched, completed = EXCLUDED.completed, updated_at = CURRENT_TIMESTAMP"
            );
            $statement->execute($parameters);
        }

        $rated = array_values(array_filter($rows, static fn (array $row): bool => $row['ratingAction'] === 'set'));
        foreach (array_chunk($rated, 400) as $chunk) {
            $parameters = [];
            $values = [];
            foreach ($chunk as $index => $row) {
                $values[] = '(:user_' . $index . ', :anime_' . $index . ', :rating_' . $index . ')';
                $parameters['user_' . $index] = $userId;
                $parameters['anime_' . $index] = $row['animeId'];
                $parameters['rating_' . $index] = $row['rating'];
            }
            $statement = $this->database->prepare(
                'INSERT INTO user_anime_ratings (user_id, anime_id, rating) VALUES ' . implode(', ', $values) . '
                 ON CONFLICT (user_id, anime_id) DO UPDATE SET rating = EXCLUDED.rating, updated_at = CURRENT_TIMESTAMP'
            );
            $statement->execute($parameters);
        }
        $removed = array_values(array_filter($rows, static fn (array $row): bool => $row['ratingAction'] === 'remove'));
        foreach (array_chunk($removed, 1000) as $chunk) {
            $parameters = ['user_id' => $userId];
            $placeholders = [];
            foreach ($chunk as $index => $row) {
                $placeholders[] = ':anime_' . $index;
                $parameters['anime_' . $index] = $row['animeId'];
            }
            $statement = $this->database->prepare(
                'DELETE FROM user_anime_ratings WHERE user_id = :user_id AND anime_id IN (' . implode(', ', $placeholders) . ')'
            );
            $statement->execute($parameters);
        }
    }
}
