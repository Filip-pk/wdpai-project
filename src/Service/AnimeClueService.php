<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\AnimeClues;
use App\Repository\AnimeClueRepository;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class AnimeClueService
{
    public const ROUND_COUNT = 5;
    public const MAX_POINTS = 15;
    private readonly ?Closure $clock;

    public function __construct(private readonly AnimeClueRepository $games, ?callable $clock = null)
    {
        $this->clock = $clock === null ? null : Closure::fromCallable($clock);
    }

    /** Safe presentation only: no aliases, source IDs, unopened clues or future titles. */
    public function stateForUser(int $userId, string $locale = 'pl', bool $createDay = true): ?array
    {
        $this->validateUser($userId);
        $today = $this->now()->format('Y-m-d');
        $game = $this->games->findForDate($today);
        if ($game === null && $createDay) {
            $game = $this->games->createForDate($today, self::selectEntries(AnimeClues::all(), $this->games->usageBeforeDate($today)));
        }
        if ($game === null) {
            return null;
        }
        $attempt = $this->games->attemptForUser($userId, $game['id'])
            ?? ['roundIndex' => 0, 'hintsUsed' => 1, 'score' => 0, 'correctCount' => 0, 'completedAt' => null];
        $answers = $this->games->answersForUser($userId, $game['id']);
        return self::publicState($game, $attempt, $answers, $locale);
    }

    public function reveal(int $userId, int $gameId, int $roundIndex, int $expectedHints): void
    {
        if ($expectedHints < 1 || $expectedHints > 3) {
            throw new InvalidArgumentException('guess.error.hints');
        }
        $this->mutate($userId, $gameId, $roundIndex, function (array $game, array $attempt) use ($userId, $gameId, $expectedHints): void {
            if ($attempt['hintsUsed'] === $expectedHints && $expectedHints < 3) {
                $this->games->revealForUser($userId, $gameId, $expectedHints);
            }
        });
    }

    public function answer(int $userId, int $gameId, int $roundIndex, mixed $answer): void
    {
        // Validate after the lock/current-round check: a retry can carry stale fields
        // but must still leave the already recorded answer untouched.
        $this->mutate($userId, $gameId, $roundIndex, function (array $game, array $attempt, DateTimeImmutable $now) use ($userId, $gameId, $roundIndex, $answer): void {
            $answer = self::validateAnswer($answer);
            $correct = self::matchesAnswer($answer, $game['entries'][$roundIndex]);
            $this->games->finalizeRound($userId, $gameId, $roundIndex, $answer, $correct,
                self::points($correct, $attempt['hintsUsed']), $attempt['hintsUsed'], false, $now);
        });
    }

    public function skip(int $userId, int $gameId, int $roundIndex): void
    {
        $this->mutate($userId, $gameId, $roundIndex, function (array $game, array $attempt, DateTimeImmutable $now) use ($userId, $gameId, $roundIndex): void {
            $this->games->finalizeRound($userId, $gameId, $roundIndex, '', false, 0,
                $attempt['hintsUsed'], true, $now);
        });
    }

    public function statistics(int $userId): array
    {
        $this->validateUser($userId);
        $today = $this->now()->format('Y-m-d');
        $totals = $this->games->totalsForUser($userId, $today);
        $streaks = QuizService::streaks($totals['completedDates'], $today);

        return ['points' => $totals['points'], 'correct' => $totals['correct'], 'answered' => $totals['answered'],
            'completedDays' => count($totals['completedDates']), 'currentStreak' => $streaks['currentStreak'],
            'bestStreak' => $streaks['bestStreak']];
    }

    /** @return array{leaders: list<array>, ownRanking: ?array, weekStart: string, weekEnd: string, weekLastDay: string} */
    public function weeklyRanking(int $userId): array
    {
        $this->validateUser($userId);
        $now = $this->now();
        $daysSinceMonday = (int) $now->format('N') - 1;
        $start = $now->setTime(0, 0)->modify('-' . $daysSinceMonday . ' days');
        $end = $start->modify('+7 days');

        return $this->games->weeklyRankingForUser(
            $userId,
            $start->format('Y-m-d'),
            $end->format('Y-m-d'),
            $now->format('Y-m-d'),
        ) + [
            'weekStart' => $start->format('Y-m-d'),
            'weekEnd' => $end->format('Y-m-d'),
            'weekLastDay' => $end->modify('-1 day')->format('Y-m-d'),
        ];
    }

    /** @param list<array> $pool @param array<int,string> $lastUsed @return list<array> */
    public static function selectEntries(array $pool, array $lastUsed = []): array
    {
        shuffle($pool);
        usort($pool, static fn (array $left, array $right): int => strcmp($lastUsed[$left['id']] ?? '', $lastUsed[$right['id']] ?? ''));
        $selected = [];
        $franchises = [];
        foreach ($pool as $entry) {
            if (isset($franchises[$entry['franchise']])) {
                continue;
            }
            $selected[] = $entry;
            $franchises[$entry['franchise']] = true;
            if (count($selected) === self::ROUND_COUNT) {
                shuffle($selected);
                return $selected;
            }
        }
        throw new RuntimeException('Pula nie zawiera pięciu różnych anime.');
    }

    public static function points(bool $correct, int $hintsUsed): int
    {
        if ($hintsUsed < 1 || $hintsUsed > 3) {
            throw new InvalidArgumentException('guess.error.hints');
        }
        return $correct ? 4 - $hintsUsed : 0;
    }

    public static function normalizeAnswer(string $answer): string
    {
        if (preg_match('//u', $answer) !== 1) {
            return '';
        }
        $answer = function_exists('mb_strtolower') ? mb_strtolower($answer, 'UTF-8') : strtolower($answer);
        if (function_exists('iconv')) {
            $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $answer);
            if ($folded !== false && !str_contains($folded, '?')) {
                $answer = strtolower($folded);
            }
        }
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $answer) ?? '';
    }

    public static function matchesAnswer(string $answer, array $entry): bool
    {
        $normalized = self::normalizeAnswer($answer);
        if ($normalized === '') {
            return false;
        }
        foreach ([$entry['title'], ...$entry['aliases']] as $alias) {
            if ($normalized === self::normalizeAnswer($alias)) {
                return true;
            }
        }
        return false;
    }

    public static function validateAnswer(mixed $answer): string
    {
        if (!is_string($answer) || strlen($answer) > 800 || preg_match('//u', $answer) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $answer) === 1
            || preg_match_all('/./us', $answer) > 200 || self::normalizeAnswer($answer) === '') {
            throw new InvalidArgumentException('guess.error.answer');
        }
        return trim($answer);
    }

    public static function publicState(array $game, array $attempt, array $answers, string $locale): array
    {
        $completed = $attempt['roundIndex'] === self::ROUND_COUNT;
        $results = [];
        foreach ($answers as $answer) {
            if ($answer['roundIndex'] >= $attempt['roundIndex']) {
                continue;
            }
            $results[] = $answer + ['title' => $game['entries'][$answer['roundIndex']]['title']];
        }
        $entry = $completed ? null : $game['entries'][$attempt['roundIndex']];
        $clues = $entry === null ? [] : array_slice($entry['clues'][$locale] ?? $entry['clues']['pl'], 0, $attempt['hintsUsed']);

        return ['id' => $game['id'], 'date' => $game['date'], 'roundIndex' => $attempt['roundIndex'],
            'hintsUsed' => $attempt['hintsUsed'], 'clues' => $clues, 'completed' => $completed,
            'score' => $attempt['score'], 'correctCount' => $attempt['correctCount'], 'results' => $results,
            'roundCount' => self::ROUND_COUNT, 'maxPoints' => self::MAX_POINTS];
    }

    private function mutate(int $userId, int $gameId, int $roundIndex, callable $operation): void
    {
        $this->validateUser($userId);
        if ($roundIndex < 0 || $roundIndex >= self::ROUND_COUNT) {
            throw new InvalidArgumentException('guess.error.round');
        }
        $this->games->transaction(function () use ($userId, $gameId, $roundIndex, $operation): void {
            $game = $this->games->findById($gameId);
            if ($game === null || $game['date'] !== $this->now()->format('Y-m-d')) {
                throw new InvalidArgumentException('guess.error.expired');
            }
            $attempt = $this->games->lockAttempt($userId, $gameId);
            $now = $this->now();
            if ($game['date'] !== $now->format('Y-m-d')) {
                throw new InvalidArgumentException('guess.error.expired');
            }
            if ($roundIndex < $attempt['roundIndex']) {
                return;
            }
            if ($roundIndex > $attempt['roundIndex']) {
                throw new InvalidArgumentException('guess.error.round');
            }
            $operation($game, $attempt, $now);
        });
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
        if (!$now instanceof DateTimeInterface) {
            throw new RuntimeException('Zegar gry musi zwracać datę.');
        }
        return DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone(QuizService::TIMEZONE));
    }

    private function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('guess.error.user');
        }
    }
}
