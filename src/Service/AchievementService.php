<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\Achievements;
use App\Repository\AchievementRepository;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

final class AchievementService
{
    private readonly ?Closure $clock;

    public function __construct(private readonly AchievementRepository $achievements, ?callable $clock = null)
    {
        $this->clock = $clock === null ? null : Closure::fromCallable($clock);
    }

    /** @return list<string> Codes first unlocked by this synchronization. */
    public function synchronize(int $userId): array
    {
        $this->validateUser($userId);

        return $this->achievements->transaction(function () use ($userId): array {
            $now = $this->now();
            $metrics = $this->metricsForUser($userId, $now);
            $eligible = self::eligibleCodes($metrics);
            $unlocked = $this->achievements->unlockForUser($userId, $eligible, $now);

            return array_values(array_intersect($eligible, $unlocked));
        });
    }

    /** Read only; the caller explicitly synchronizes the signed-in user's own view. */
    public function overviewForUser(int $userId): array
    {
        $this->validateUser($userId);

        return self::overview(
            $this->metricsForUser($userId, $this->now()),
            $this->achievements->unlockedForUser($userId),
        );
    }

    /** Read-only earned badges for public profiles; never awards or exposes locked progress. */
    public function earnedForUser(int $userId): array
    {
        $this->validateUser($userId);
        $unlocked = $this->achievements->unlockedForUser($userId);
        $cards = self::overview([], $unlocked)['items'];
        $cards = array_values(array_filter($cards, static fn (array $card): bool => $card['unlocked']));
        usort($cards, static fn (array $left, array $right): int =>
            strcmp($right['unlockedAt'], $left['unlockedAt']) ?: strcmp($left['code'], $right['code']));

        return $cards;
    }

    /** @param array<string, int> $metrics @return list<string> */
    public static function eligibleCodes(array $metrics): array
    {
        $eligible = [];
        foreach (Achievements::all() as $achievement) {
            if (($metrics[$achievement['metric']] ?? 0) >= $achievement['target']) {
                $eligible[] = $achievement['code'];
            }
        }

        return $eligible;
    }

    /**
     * Earned badges retain a complete progress bar even when a user corrects saved activity.
     * @param array<string, int> $metrics
     * @param array<string, string> $unlockedAtByCode
     * @return array{items: list<array>, unlockedCount: int, totalCount: int}
     */
    public static function overview(array $metrics, array $unlockedAtByCode = []): array
    {
        $items = [];
        $unlockedCount = 0;
        foreach (Achievements::all() as $achievement) {
            $code = $achievement['code'];
            $unlockedAt = $unlockedAtByCode[$code] ?? null;
            $unlocked = $unlockedAt !== null;
            $unlockedCount += $unlocked ? 1 : 0;
            $items[] = [
                'code' => $code,
                'category' => $achievement['category'],
                'icon' => $achievement['icon'],
                'titleKey' => 'achievements.' . $code . '.title',
                'descriptionKey' => 'achievements.' . $code . '.description',
                'progress' => $unlocked ? $achievement['target'] : min($achievement['target'], max(0, $metrics[$achievement['metric']] ?? 0)),
                'target' => $achievement['target'],
                'unlocked' => $unlocked,
                'unlockedAt' => $unlockedAt,
            ];
        }

        return ['items' => $items, 'unlockedCount' => $unlockedCount, 'totalCount' => count($items)];
    }

    private function metricsForUser(int $userId, DateTimeImmutable $now): array
    {
        $today = $now->format('Y-m-d');
        $metrics = $this->achievements->metricsForUser($userId, $today);
        $metrics['bestQuizStreak'] = QuizService::streaks($metrics['quizDates'], $today)['bestStreak'];
        unset($metrics['quizDates']);

        return $metrics;
    }

    private function now(): DateTimeImmutable
    {
        $value = $this->clock === null ? new DateTimeImmutable('now') : ($this->clock)();
        if (!$value instanceof DateTimeInterface) {
            throw new RuntimeException('Zegar osiągnięć musi zwracać datę.');
        }

        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone(QuizService::TIMEZONE));
    }

    private function validateUser(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator konta musi być dodatni.');
        }
    }
}
