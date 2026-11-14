<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
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
