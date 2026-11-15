<?php

declare(strict_types=1);

use App\Service\ProfileShowcaseService;

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

$run('Pusta gablotka i trzy puste pola anime są prawidłowe', static function () use ($assert): void {
    foreach ([[], ['', '', '']] as $input) {
        $assert(ProfileShowcaseService::normalize($input, []) === ['animeIds' => [], 'badgeCodes' => [], 'errors' => []]);
    }
});

$run('Identyfikatory formularza są normalizowane bez zmiany wybranej kolejności', static function () use ($assert): void {
    $result = ProfileShowcaseService::normalize(['3', '', '1'], ['quiz_perfect', 'anime_first']);
    $assert($result === ['animeIds' => [3, 1], 'badgeCodes' => ['quiz_perfect', 'anime_first'], 'errors' => []]);
    $assert(ProfileShowcaseService::normalize([3, 2, 1], [])['animeIds'] === [3, 2, 1]);
});

$run('Zduplikowane anime i odznaki są odrzucane także dla liczby i jej zapisu tekstowego', static function () use ($assert): void {
    $result = ProfileShowcaseService::normalize([1, '1'], ['anime_first', 'anime_first']);
    $assert($result['errors'] === ['showcase.error.anime_duplicate', 'showcase.error.badge_duplicate']);
});

$run('Cztery pozycje przekraczają limit nawet gdy jeden slot jest pusty', static function () use ($assert): void {
    $result = ProfileShowcaseService::normalize(['1', '2', '3', ''], ['anime_first', 'quiz_first', 'quiz_perfect', 'quiz_streak_7']);
    $assert($result['errors'] === ['showcase.error.anime_limit', 'showcase.error.badge_limit']);
});

$run('Skalary, tablice asocjacyjne i zagnieżdżone wartości nie omijają walidacji', static function () use ($assert): void {
    foreach ([null, '1', 1, false, ['slot' => '1'], [['1']], [true], [1.0], [null]] as $input) {
        $assert(in_array('showcase.error.anime_invalid', ProfileShowcaseService::normalize($input, [])['errors'], true));
    }
    foreach ([null, 'anime_first', 1, false, ['slot' => 'anime_first'], [['anime_first']], [true], [null]] as $input) {
        $assert(in_array('showcase.error.badge_invalid', ProfileShowcaseService::normalize([], $input)['errors'], true));
    }
});

$run('Zero, wartości ujemne, ułamki, SQL i zbyt duże identyfikatory są odrzucane', static function () use ($assert): void {
    foreach (['0', '-1', '1.5', '1 OR 1=1', '<svg onload=alert(1)>', '9999999999999999999999999999', "1\0"] as $input) {
        $assert(ProfileShowcaseService::normalize([$input], [])['errors'] === ['showcase.error.anime_invalid'], $input);
    }
});

$run('Niepoprawne kody odznak nie trafiają do zapytań', static function () use ($assert): void {
    foreach (['', 'ANIME_FIRST', 'anime-first', '<script>', "anime_first\0", str_repeat('a', 65)] as $input) {
        $assert(ProfileShowcaseService::normalize([], [$input])['errors'] === ['showcase.error.badge_invalid']);
    }
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
