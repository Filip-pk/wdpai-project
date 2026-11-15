<?php

declare(strict_types=1);

use App\Data\Achievements;
use App\Service\AchievementService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
};

$run('Katalog ma dziesięć stabilnych, unikalnych kodów i dodatnie cele', static function () use ($assert): void {
    $catalogue = Achievements::all();
    $assert(count($catalogue) === 10 && count(array_unique(array_column($catalogue, 'code'))) === 10);
    $assert(count(array_filter($catalogue, static fn (array $entry): bool => $entry['category'] === 'quiz')) === 5);
    foreach ($catalogue as $entry) {
        $assert(preg_match('/\A[a-z][a-z0-9_]*\z/', $entry['code']) === 1);
        $assert(in_array($entry['category'], ['quiz', 'anime'], true));
        $assert(is_int($entry['target']) && $entry['target'] > 0 && $entry['metric'] !== '' && $entry['icon'] !== '');
    }
});

$run('Puste konto nie spełnia żadnego warunku i ma zerowy postęp', static function () use ($assert): void {
    $assert(AchievementService::eligibleCodes([]) === []);
    $overview = AchievementService::overview([]);
    $assert($overview['unlockedCount'] === 0 && $overview['totalCount'] === 10);
    foreach ($overview['items'] as $item) {
        $assert(!$item['unlocked'] && $item['unlockedAt'] === null && $item['progress'] === 0);
    }
});

$run('Każdy cel odblokowuje się dokładnie na progu, nie jeden krok wcześniej', static function () use ($assert): void {
    foreach (Achievements::all() as $entry) {
        $before = AchievementService::eligibleCodes([$entry['metric'] => $entry['target'] - 1]);
        $at = AchievementService::eligibleCodes([$entry['metric'] => $entry['target']]);
        $assert(!in_array($entry['code'], $before, true) && in_array($entry['code'], $at, true), $entry['code']);
    }
});

$run('Sam udział bez poprawnej odpowiedzi przyznaje pierwszy quiz, ale nie perfekcyjny wynik', static function () use ($assert): void {
    $assert(AchievementService::eligibleCodes(['quizCount' => 1, 'correctAnswers' => 0, 'perfectQuizzes' => 0]) === ['quiz_first']);
    $assert(AchievementService::eligibleCodes(['quizCount' => 1, 'perfectQuizzes' => 1, 'correctAnswers' => 5]) === ['quiz_first', 'quiz_perfect']);
});

$run('Rekord serii 30 dni spełnia też próg siedmiu dni', static function () use ($assert): void {
    $assert(AchievementService::eligibleCodes(['bestQuizStreak' => 30]) === ['quiz_streak_7', 'quiz_streak_30']);
});

$run('Bieżący postęp nie oznacza zdobycia przed zapisem odznaki', static function () use ($assert): void {
    $items = array_column(AchievementService::overview(['correctAnswers' => 42, 'episodes' => 150])['items'], null, 'code');
    $assert($items['quiz_correct_100']['progress'] === 42 && !$items['quiz_correct_100']['unlocked']);
    $assert($items['anime_episodes_100']['progress'] === 100 && !$items['anime_episodes_100']['unlocked']);
    $assert($items['quiz_correct_100']['titleKey'] === 'achievements.quiz_correct_100.title');
});

$run('Zdobyte odznaki zachowują datę i pełny pasek po korekcie zapisanej aktywności', static function () use ($assert): void {
    $time = '2026-11-14 12:00:00+00';
    $overview = AchievementService::overview([], ['anime_completed_10' => $time, 'unknown_future_code' => $time]);
    $items = array_column($overview['items'], null, 'code');
    $assert($overview['unlockedCount'] === 1 && $overview['totalCount'] === 10);
    $assert($items['anime_completed_10']['unlocked'] && $items['anime_completed_10']['progress'] === 10
        && $items['anime_completed_10']['unlockedAt'] === $time);
    $assert(!$items['anime_first']['unlocked']);
});

$run('Jedna synchronizacja może spełnić wszystkie progi bez zmiany danych wejściowych', static function () use ($assert): void {
    $metrics = ['quizCount' => 30, 'correctAnswers' => 150, 'perfectQuizzes' => 30,
        'bestQuizStreak' => 30, 'episodes' => 1000, 'completedAnime' => 50, 'completedGenres' => 8];
    $original = $metrics;
    $assert(AchievementService::eligibleCodes($metrics) === array_column(Achievements::all(), 'code'));
    $assert($metrics === $original);
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
