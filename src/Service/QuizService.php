<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\QuizQuestions;
use App\Repository\QuizRepository;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class QuizService
{
    public const QUESTION_COUNT = 5;
    public const TIMEZONE = 'Europe/Warsaw';

    private readonly ?Closure $clock;

    public function __construct(private readonly QuizRepository $quizzes, ?callable $clock = null)
    {
        $this->clock = $clock === null ? null : Closure::fromCallable($clock);
    }

    /** Read-only lookup for error responses whose request transaction will be rolled back.
     * @return array{id: int, date: string, questions: list<array>}|null
     */
    public function availableQuiz(): ?array
    {
        return $this->quizzes->findForDate($this->now()->format('Y-m-d'));
    }

    /** @return array{id: int, date: string, questions: list<array>} */
    public function dailyQuiz(): array
    {
        $date = $this->now()->format('Y-m-d');
        $existing = $this->quizzes->findForDate($date);
        if ($existing !== null) {
            return $existing;
        }

        $pool = QuizQuestions::all();
        if (count($pool) < self::QUESTION_COUNT) {
            throw new RuntimeException('Pula pytań quizu jest zbyt mała.');
        }
        $questions = QuizQuestionSelector::select($pool, $this->quizzes->questionUsageBeforeDate($date));

        return $this->quizzes->createForDate($date, $questions);
    }

    /** @return array{score: int, total: int, answers: array<int, int>, completedAt: string}|null */
    public function resultForUser(int $userId, int $quizId): ?array
    {
        $this->validateUser($userId);

        return $this->quizzes->resultForUser($userId, $quizId);
    }

    /**
     * @param array<int|string, mixed> $answers
     * @return array{score: int, total: int, answers: array<int, int>, completedAt: string}
     */
    public function submit(int $userId, int $quizId, array $answers): array
    {
        $this->validateUser($userId);
        $now = $this->now();
        $quiz = $this->quizzes->findById($quizId);
        if ($quiz === null || $quiz['date'] !== $now->format('Y-m-d')) {
            throw new InvalidArgumentException('quiz.error.expired');
        }
        $existing = $this->quizzes->resultForUser($userId, $quizId);
        if ($existing !== null) {
            return $existing;
        }
        $scored = self::scoreAnswers($quiz['questions'], $answers);

        return $this->quizzes->saveResult($userId, $quizId, $scored['score'], $scored['answers'], $now);
    }

    /** @return array{currentStreak: int, bestStreak: int, completedToday: bool, correct: int, total: int, completedQuizzes: int} */
    public function statistics(int $userId): array
    {
        $this->validateUser($userId);

        return self::streaks($this->quizzes->completedDates($userId), $this->now()->format('Y-m-d'))
            + $this->quizzes->totalsForUser($userId);
    }

    public function ranking(int $userId, string $period = 'overall'): array
    {
        $this->validateUser($userId);
        if (!in_array($period, ['overall', 'monthly'], true)) {
            throw new InvalidArgumentException('quiz.error.period');
        }
        $month = $this->now()->modify('first day of this month')->setTime(0, 0);
        $ranking = $this->quizzes->rankingForUser(
            $userId,
            $period === 'monthly' ? $month->format('Y-m-d') : null,
            $period === 'monthly' ? $month->modify('+1 month')->format('Y-m-d') : null,
        );

        return $ranking + ['period' => $period, 'month' => $month->format('Y-m')];
    }

    /**
     * Answers must be keyed by the exact question IDs, with integer or canonical form values 0–3.
     * @param list<array> $questions
     * @param array<int|string, mixed> $answers
     * @return array{score: int, answers: array<int, int>}
     */
    public static function scoreAnswers(array $questions, array $answers): array
    {
        if (count($questions) !== self::QUESTION_COUNT || count($answers) !== self::QUESTION_COUNT) {
            throw new InvalidArgumentException('quiz.error.answers');
        }
        $normalized = [];
        $score = 0;
        foreach ($questions as $question) {
            $id = $question['id'];
            $value = $answers[$id] ?? null;
            if ((!is_int($value) && !is_string($value)) || !in_array($value, [0, 1, 2, 3, '0', '1', '2', '3'], true)
                || array_key_exists($id, $normalized)) {
                throw new InvalidArgumentException('quiz.error.answers');
            }
            $normalized[$id] = (int) $value;
            if ((int) $value === $question['correctOption']) {
                $score++;
            }
        }

        return ['score' => $score, 'answers' => $normalized];
    }

    /**
     * A streak through yesterday remains active until today's full calendar day has passed.
     * Calendar arithmetic also handles 23/25-hour days at daylight-saving transitions.
     * @param list<string> $dates
     * @return array{currentStreak: int, bestStreak: int, completedToday: bool}
     */
    public static function streaks(array $dates, string $today): array
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today, $timezone);
        if ($todayDate === false || $todayDate->format('Y-m-d') !== $today) {
            throw new InvalidArgumentException('Nieprawidłowa data quizu.');
        }
        $dates = array_values(array_unique(array_filter($dates, static fn (string $date): bool => $date <= $today)));
        sort($dates, SORT_STRING);
        $previous = null;
        $run = 0;
        $best = 0;
        foreach ($dates as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Nieprawidłowa data ukończenia quizu.');
            }
            $run = $previous !== null && $previous->modify('+1 day')->format('Y-m-d') === $date ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $parsed;
        }
        $latest = $previous?->format('Y-m-d');
        $active = $latest === $today || $latest === $todayDate->modify('-1 day')->format('Y-m-d');

        return [
            'currentStreak' => $active ? $run : 0,
            'bestStreak' => $best,
            'completedToday' => $latest === $today,
        ];
    }

    private function now(): DateTimeImmutable
    {
        $value = $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
        if (!$value instanceof DateTimeInterface) {
            throw new RuntimeException('Zegar quizu musi zwracać datę.');
        }

        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    private function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('quiz.error.user');
        }
    }
}
