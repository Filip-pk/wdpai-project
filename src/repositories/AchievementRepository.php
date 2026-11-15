<?php

declare(strict_types=1);

namespace App\Repository;

use App\Data\Achievements;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AchievementRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** Execute one complete synchronization without committing an outer request transaction. */
    public function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        $savepoint = 'achievements_' . bin2hex(random_bytes(6));
        if ($ownsTransaction) {
            $this->database->beginTransaction();
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
            throw $exception;
        }
    }

    /** @return array{quizCount: int, correctAnswers: int, perfectQuizzes: int, episodes: int, completedAnime: int, completedGenres: int, quizDates: list<string>} */
    public function metricsForUser(int $userId, string $today): array
    {
        $this->validateUser($userId);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if ($parsed === false || $parsed->format('Y-m-d') !== $today) {
            throw new InvalidArgumentException('Nieprawidłowa data osiągnięć.');
        }
        $statement = $this->database->prepare(
            'WITH quiz_metrics AS (
                SELECT COUNT(*) AS quiz_count, COALESCE(SUM(attempts.score::BIGINT), 0) AS correct_answers,
                       COUNT(*) FILTER (WHERE attempts.score = jsonb_array_length(quizzes.questions)) AS perfect_quizzes
                FROM quiz_attempts AS attempts
                JOIN daily_quizzes AS quizzes ON quizzes.id = attempts.quiz_id
                WHERE attempts.user_id = :quiz_user AND quizzes.quiz_date <= CAST(:today AS date)
             ), anime_metrics AS (
                SELECT COALESCE(SUM(watched::BIGINT), 0) AS episodes,
                       COUNT(*) FILTER (WHERE completed) AS completed_anime
                FROM user_anime_experience WHERE user_id = :anime_user
             ), genre_metrics AS (
                SELECT COUNT(DISTINCT genres.genre_id) AS completed_genres
                FROM user_anime_experience AS experience
                JOIN anime_genres AS genres ON genres.anime_id = experience.anime_id
                WHERE experience.user_id = :genre_user AND experience.completed
             )
             SELECT quiz_metrics.*, anime_metrics.*, genre_metrics.*
             FROM quiz_metrics CROSS JOIN anime_metrics CROSS JOIN genre_metrics'
        );
        $statement->execute(['quiz_user' => $userId, 'today' => $today, 'anime_user' => $userId, 'genre_user' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $dates = $this->database->prepare(
            'SELECT quizzes.quiz_date FROM quiz_attempts AS attempts
             JOIN daily_quizzes AS quizzes ON quizzes.id = attempts.quiz_id
             WHERE attempts.user_id = :user_id AND quizzes.quiz_date <= CAST(:today AS date)
             ORDER BY quizzes.quiz_date'
        );
        $dates->execute(['user_id' => $userId, 'today' => $today]);

        return [
            'quizCount' => (int) $row['quiz_count'],
            'correctAnswers' => (int) $row['correct_answers'],
            'perfectQuizzes' => (int) $row['perfect_quizzes'],
            'episodes' => (int) $row['episodes'],
            'completedAnime' => (int) $row['completed_anime'],
            'completedGenres' => (int) $row['completed_genres'],
            'quizDates' => $dates->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    /** @return array{guessCompletedDays: int, guessPerfectDays: int, guessFirstClueCorrect: int, guessCorrectAnswers: int, guessDates: list<string>} */
    public function clueMetricsForUser(int $userId, string $today): array
    {
        $this->validateUser($userId);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if ($parsed === false || $parsed->format('Y-m-d') !== $today) {
            throw new InvalidArgumentException('Nieprawidłowa data osiągnięć.');
        }
        $statement = $this->database->prepare(
            'WITH days AS (
                SELECT COUNT(*) AS completed, COUNT(*) FILTER (WHERE attempts.score = 15) AS perfect
                FROM anime_clue_attempts AS attempts
                JOIN daily_anime_clues AS games ON games.id = attempts.game_id
                WHERE attempts.user_id = :day_user AND attempts.completed_at IS NOT NULL
                  AND games.game_date <= CAST(:day_date AS date)
             ), answers AS (
                SELECT COUNT(*) FILTER (WHERE answers.correct) AS correct,
                       COUNT(*) FILTER (WHERE answers.correct AND answers.hints_used = 1) AS sharp
                FROM anime_clue_answers AS answers
                JOIN daily_anime_clues AS games ON games.id = answers.game_id
                WHERE answers.user_id = :answer_user AND games.game_date <= CAST(:answer_date AS date)
             ) SELECT days.*, answers.* FROM days CROSS JOIN answers'
        );
        $statement->execute(['day_user' => $userId, 'day_date' => $today, 'answer_user' => $userId, 'answer_date' => $today]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $dates = $this->database->prepare(
            'SELECT games.game_date FROM anime_clue_attempts AS attempts
             JOIN daily_anime_clues AS games ON games.id = attempts.game_id
             WHERE attempts.user_id = :user_id AND attempts.completed_at IS NOT NULL
               AND games.game_date <= CAST(:today AS date) ORDER BY games.game_date'
        );
        $dates->execute(['user_id' => $userId, 'today' => $today]);
        return [
            'guessCompletedDays' => (int) $row['completed'],
            'guessPerfectDays' => (int) $row['perfect'],
            'guessFirstClueCorrect' => (int) $row['sharp'],
            'guessCorrectAnswers' => (int) $row['correct'],
            'guessDates' => $dates->fetchAll(PDO::FETCH_COLUMN),
        ];
    }

    /** @return array<string, string> Achievement code mapped to its first unlock timestamp. */
    public function unlockedForUser(int $userId): array
    {
        $this->validateUser($userId);
        $statement = $this->database->prepare(
            'SELECT achievement_code, unlocked_at FROM user_achievements
             WHERE user_id = :user_id ORDER BY unlocked_at DESC, achievement_code'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param list<string> $codes @return list<string> Only codes first unlocked by this statement. */
    public function unlockForUser(int $userId, array $codes, DateTimeImmutable $unlockedAt): array
    {
        $this->validateUser($userId);
        $known = array_column(Achievements::all(), 'code');
        foreach ($codes as $code) {
            if (!is_string($code) || !in_array($code, $known, true)) {
                throw new InvalidArgumentException('Nieznany kod osiągnięcia.');
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }
        $values = [];
        $parameters = [];
        foreach ($codes as $index => $code) {
            $values[] = '(:user_' . $index . ', :code_' . $index . ', :time_' . $index . ')';
            $parameters['user_' . $index] = $userId;
            $parameters['code_' . $index] = $code;
            $parameters['time_' . $index] = $unlockedAt->format('Y-m-d H:i:s.uP');
        }
        $statement = $this->database->prepare(
            'INSERT INTO user_achievements (user_id, achievement_code, unlocked_at) VALUES ' . implode(', ', $values)
            . ' ON CONFLICT (user_id, achievement_code) DO NOTHING RETURNING achievement_code'
        );
        $statement->execute($parameters);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator konta musi być dodatni.');
        }
    }
}
