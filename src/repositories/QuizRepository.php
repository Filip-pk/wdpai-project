<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class QuizRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{id: int, date: string, questions: list<array>}|null */
    public function findForDate(string $date): ?array
    {
        $statement = $this->database->prepare('SELECT id, quiz_date, questions FROM daily_quizzes WHERE quiz_date = :date');
        $statement->execute(['date' => $date]);
        $row = $statement->fetch();

        return $row === false ? null : $this->mapQuiz($row);
    }

    /** @return array{id: int, date: string, questions: list<array>}|null */
    public function findById(int $quizId): ?array
    {
        $statement = $this->database->prepare('SELECT id, quiz_date, questions FROM daily_quizzes WHERE id = :id');
        $statement->execute(['id' => $quizId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->mapQuiz($row);
    }

    /**
     * The unique date makes concurrent first visits select the same winning snapshot.
     * Each write is a single statement and also works inside AccountAccessGuard's transaction.
     * @param list<array> $questions
     * @return array{id: int, date: string, questions: list<array>}
     */
    public function createForDate(string $date, array $questions): array
    {
        $statement = $this->database->prepare(
            'INSERT INTO daily_quizzes (quiz_date, questions) VALUES (:date, CAST(:questions AS jsonb))
             ON CONFLICT (quiz_date) DO NOTHING RETURNING id, quiz_date, questions'
        );
        $statement->execute([
            'date' => $date,
            'questions' => json_encode($questions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $row = $statement->fetch();

        return $row === false
            ? ($this->findForDate($date) ?? throw new RuntimeException('Nie znaleziono zestawu quizu dnia.'))
            : $this->mapQuiz($row);
    }

    /** @return array{score: int, total: int, answers: array<int, int>, completedAt: string}|null */
    public function resultForUser(int $userId, int $quizId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT score, answers, completed_at FROM quiz_attempts WHERE user_id = :user_id AND quiz_id = :quiz_id'
        );
        $statement->execute(['user_id' => $userId, 'quiz_id' => $quizId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->mapResult($row);
    }

    /**
     * A retry returns the original result; it never changes answers or awards another participation.
     * @param array<int, int> $answers
     * @return array{score: int, total: int, answers: array<int, int>, completedAt: string}
     */
    public function saveResult(int $userId, int $quizId, int $score, array $answers, DateTimeImmutable $completedAt): array
    {
        $statement = $this->database->prepare(
            'INSERT INTO quiz_attempts (user_id, quiz_id, score, answers, completed_at)
             VALUES (:user_id, :quiz_id, :score, CAST(:answers AS jsonb), :completed_at)
             ON CONFLICT (user_id, quiz_id) DO NOTHING RETURNING score, answers, completed_at'
        );
        $statement->execute([
            'user_id' => $userId,
            'quiz_id' => $quizId,
            'score' => $score,
            'answers' => json_encode((object) $answers, JSON_THROW_ON_ERROR),
            'completed_at' => $completedAt->format('Y-m-d H:i:s.uP'),
        ]);
        $row = $statement->fetch();

        return $row === false
            ? ($this->resultForUser($userId, $quizId) ?? throw new RuntimeException('Nie znaleziono zapisanego wyniku quizu.'))
            : $this->mapResult($row);
    }

    /** @return list<string> */
    public function completedDates(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT quizzes.quiz_date FROM quiz_attempts AS attempts
             JOIN daily_quizzes AS quizzes ON quizzes.id = attempts.quiz_id
             WHERE attempts.user_id = :user_id ORDER BY quizzes.quiz_date'
        );
        $statement->execute(['user_id' => $userId]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<int, string> Last published date for each question, excluding this day and future snapshots. */
    public function questionUsageBeforeDate(string $date): array
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Nieprawidłowa data historii pytań.');
        }
        $statement = $this->database->prepare(
            'SELECT CAST(question.value ->> \'id\' AS bigint) AS question_id, MAX(quizzes.quiz_date) AS last_date
             FROM daily_quizzes AS quizzes
             CROSS JOIN LATERAL jsonb_array_elements(quizzes.questions) AS question(value)
             WHERE quizzes.quiz_date < CAST(:date AS date)
             GROUP BY CAST(question.value ->> \'id\' AS bigint)
             ORDER BY question_id'
        );
        $statement->execute(['date' => $date]);
        $usage = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['question_id'] !== null) {
                $usage[(int) $row['question_id']] = (string) $row['last_date'];
            }
        }

        return $usage;
    }

    /** @return array{correct: int, total: int, completedQuizzes: int} */
    public function totalsForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT COALESCE(SUM(attempts.score::BIGINT), 0) AS correct,
                    COALESCE(SUM(jsonb_array_length(quizzes.questions)::BIGINT), 0) AS total,
                    COUNT(*) AS completed_quizzes
             FROM quiz_attempts AS attempts
             JOIN daily_quizzes AS quizzes ON quizzes.id = attempts.quiz_id
             WHERE attempts.user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'correct' => (int) $row['correct'],
            'total' => (int) $row['total'],
            'completedQuizzes' => (int) $row['completed_quizzes'],
        ];
    }

    /**
     * Monthly bounds are calendar dates in the quiz timezone, with an exclusive end.
     * Only completed participants enter the ranking, including a valid score of zero.
     * @return array{leaders: list<array{userId: int, name: string, avatarUrl: ?string, correct: int, total: int, completedQuizzes: int, rank: int}>, ownRanking: ?array}
     */
    public function rankingForUser(int $userId, ?string $monthStart = null, ?string $monthEnd = null): array
    {
        if (($monthStart === null) !== ($monthEnd === null)) {
            throw new InvalidArgumentException('Obie granice okresu rankingu są wymagane.');
        }
        $filter = '';
        $parameters = ['user_id' => $userId];
        if ($monthStart !== null) {
            foreach ([$monthStart, $monthEnd] as $date) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                    throw new InvalidArgumentException('Nieprawidłowa data okresu rankingu.');
                }
            }
            if ($monthStart >= $monthEnd) {
                throw new InvalidArgumentException('Koniec okresu musi następować po początku.');
            }
            $filter = ' WHERE quizzes.quiz_date >= CAST(:month_start AS date) AND quizzes.quiz_date < CAST(:month_end AS date)';
            $parameters['month_start'] = $monthStart;
            $parameters['month_end'] = $monthEnd;
        }
        $statement = $this->database->prepare(
            'WITH totals AS (
                SELECT attempts.user_id, SUM(attempts.score::BIGINT) AS correct,
                       SUM(jsonb_array_length(quizzes.questions)::BIGINT) AS total,
                       COUNT(*) AS completed_quizzes
                FROM quiz_attempts AS attempts
                JOIN daily_quizzes AS quizzes ON quizzes.id = attempts.quiz_id' . $filter . '
                GROUP BY attempts.user_id
             ), ranked AS (
                SELECT users.id AS user_id, users.name, users.avatar_version,
                       totals.correct, totals.total, totals.completed_quizzes,
                       RANK() OVER (ORDER BY totals.correct DESC) AS user_rank,
                       ROW_NUMBER() OVER (ORDER BY totals.correct DESC, users.id ASC) AS listing_position
                FROM totals JOIN users ON users.id = totals.user_id
             )
             SELECT user_id, name, avatar_version, correct, total, completed_quizzes, user_rank, listing_position
             FROM ranked WHERE listing_position <= 100 OR user_id = :user_id ORDER BY listing_position'
        );
        $statement->execute($parameters);
        $leaders = [];
        $ownRanking = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entry = $this->mapRankingUser($row);
            if ((int) $row['listing_position'] <= 100) {
                $leaders[] = $entry;
            }
            if ($entry['userId'] === $userId) {
                $ownRanking = $entry;
            }
        }

        return ['leaders' => $leaders, 'ownRanking' => $ownRanking];
    }

    private function mapRankingUser(array $row): array
    {
        $version = $row['avatar_version'];

        return [
            'userId' => (int) $row['user_id'],
            'name' => (string) $row['name'],
            'avatarUrl' => is_string($version) && preg_match('/\A[a-f0-9]{32}\z/', $version) === 1
                ? '/avatars/' . (int) $row['user_id'] . '?v=' . $version : null,
            'correct' => (int) $row['correct'],
            'total' => (int) $row['total'],
            'completedQuizzes' => (int) $row['completed_quizzes'],
            'rank' => (int) $row['user_rank'],
        ];
    }

    private function mapQuiz(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'date' => (string) $row['quiz_date'],
            'questions' => json_decode($row['questions'], true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function mapResult(array $row): array
    {
        return [
            'score' => (int) $row['score'],
            'total' => 5,
            'answers' => json_decode($row['answers'], true, 512, JSON_THROW_ON_ERROR),
            'completedAt' => (string) $row['completed_at'],
        ];
    }
}
