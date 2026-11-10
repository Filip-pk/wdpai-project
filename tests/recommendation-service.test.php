<?php

declare(strict_types=1);

use App\Service\RecommendationService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$service = (new ReflectionClass(RecommendationService::class))->newInstanceWithoutConstructor();
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$close = static function (float $actual, float $expected) use ($assert): void {
    $assert(abs($actual - $expected) < 0.00000001, 'Waga profilu ma nieprawidłową wartość.');
};
$signal = static fn (int $id, string $status, array $genres, ?int $rating = null, string $type = 'UNKNOWN'): array => [
    'animeId' => $id, 'status' => $status, 'rating' => $rating, 'genreIds' => $genres, 'type' => $type,
];
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

$run('Statusy odróżniają ukończone, oglądane, planowane, wstrzymane i porzucone anime', static function () use ($service, $signal, $assert, $close): void {
    $profile = $service->buildProfile([
        $signal(1, 'completed', [1]), $signal(2, 'watching', [2]), $signal(3, 'planned', [3]),
        $signal(4, 'on_hold', [4]), $signal(5, 'dropped', [5]),
    ]);
    foreach ([1 => 3.0, 2 => 2.0, 3 => 1.0, 4 => 0.5, 5 => -2.0] as $genreId => $weight) {
        $close($profile['genreWeights'][$genreId], $weight / 8.5);
    }
    $assert($profile['preferredGenreIds'] === [1, 2, 3]);
});

$run('Niskie oceny odwracają sygnał, neutralne go osłabiają, a wysokie wzmacniają', static function () use ($service, $signal, $close): void {
    $profile = $service->buildProfile([
        $signal(1, 'completed', [1], 1), $signal(2, 'completed', [2], 4),
        $signal(3, 'completed', [3], 5), $signal(4, 'completed', [4], 6),
        $signal(5, 'completed', [5], 7), $signal(6, 'completed', [6], 10),
    ]);
    foreach ([1 => -3.0, 2 => -0.75, 3 => 1.5, 4 => 1.5, 5 => 3.75, 6 => 6.0] as $genreId => $weight) {
        $close($profile['genreWeights'][$genreId], $weight / 16.5);
    }
});

$run('Wysoka ocena nie zamienia porzuconego anime w pozytywną preferencję', static function () use ($service, $signal, $assert, $close): void {
    $profile = $service->buildProfile([
        $signal(1, 'dropped', [1], 10), $signal(2, 'dropped', [2], 1), $signal(3, 'planned', [3]),
    ]);
    $close($profile['genreWeights'][1], -0.4);
    $close($profile['genreWeights'][2], -0.4);
    $assert($profile['preferredGenreIds'] === [3]);
});

$run('Waga anime dzieli się na unikalne gatunki zamiast rosnąć wraz z liczbą tagów', static function () use ($service, $signal, $assert, $close): void {
    $profile = $service->buildProfile([
        $signal(1, 'completed', [2, 1, 2]), $signal(2, 'planned', [3]),
    ]);
    $close($profile['genreWeights'][1], 0.375);
    $close($profile['genreWeights'][2], 0.375);
    $close($profile['genreWeights'][3], 0.25);
    $assert($profile['preferredGenreIds'] === [1, 2, 3]);
});

$run('Sygnały dodatnie i ujemne sumują się, a normalizacja zachowuje niechęć', static function () use ($service, $signal, $assert, $close): void {
    $profile = $service->buildProfile([
        $signal(1, 'completed', [1]), $signal(2, 'dropped', [1]),
        $signal(3, 'planned', [2]), $signal(4, 'dropped', [3]),
    ]);
    $close($profile['genreWeights'][1], 0.25);
    $close($profile['genreWeights'][2], 0.25);
    $close($profile['genreWeights'][3], -0.5);
    $assert($profile['preferredGenreIds'] === [1, 2]);
    $close(array_sum(array_map(abs(...), $profile['genreWeights'])), 1.0);
});

$run('Formaty są normalizowane niezależnie od gatunków i łączą alias TV Special', static function () use ($service, $signal, $assert, $close): void {
    $profile = $service->buildProfile([
        $signal(1, 'planned', [1], null, ' tv special '), $signal(2, 'watching', [2], null, 'SPECIAL'),
        $signal(3, 'completed', [3], null, ' tv '), $signal(4, 'dropped', [4], null, 'TV'),
        $signal(5, 'completed', [], null, 'unknown'), $signal(6, 'completed', [], null, ' '),
    ]);
    $assert(array_keys($profile['typeWeights']) === ['SPECIAL', 'TV']);
    $close($profile['typeWeights']['SPECIAL'], 0.75);
    $close($profile['typeWeights']['TV'], 0.25);
});

$run('Profil zwraca najwyżej trzy dodatnie gatunki, remisy rozstrzyga rosnące ID', static function () use ($service, $signal, $assert): void {
    $profile = $service->buildProfile([
        $signal(1, 'planned', [9]), $signal(2, 'planned', [3]), $signal(3, 'planned', [1]),
        $signal(4, 'planned', [2]), $signal(5, 'dropped', [4]),
    ]);
    $assert($profile['preferredGenreIds'] === [1, 2, 3]);
    $assert(array_keys($profile['genreWeights']) === [1, 2, 3, 4, 9]);
});

$run('Pusty profil i dokładnie znoszące się preferencje nie wymuszają dopasowania', static function () use ($service, $signal, $assert): void {
    $empty = ['genreWeights' => [], 'typeWeights' => [], 'preferredGenreIds' => []];
    $assert($service->buildProfile([]) === $empty);
    $assert($service->buildProfile([
        $signal(1, 'watching', [1], null, 'TV'), $signal(2, 'dropped', [1], null, 'TV'),
    ]) === $empty);
    $profile = $service->buildProfile([$signal(1, 'dropped', [1], null, 'TV')]);
    $assert($profile['genreWeights'] === [1 => -1.0] && $profile['preferredGenreIds'] === []);
});

$run('Brak metadanych nie tworzy sztucznych gatunków ani formatu', static function () use ($service, $signal, $assert): void {
    $profile = $service->buildProfile([$signal(1, 'completed', [])]);
    $assert($profile === ['genreWeights' => [], 'typeWeights' => [], 'preferredGenreIds' => []]);
    $formatsOnly = $service->buildProfile([$signal(1, 'completed', [], null, 'TV')]);
    $assert($formatsOnly['preferredGenreIds'] === [] && $formatsOnly['typeWeights'] === ['TV' => 1.0]);
});

$run('Ponowne zbudowanie profilu uwzględnia zmianę oceny bez zachowania poprzednich wag', static function () use ($service, $signal, $assert): void {
    $liked = $service->buildProfile([$signal(1, 'completed', [1], 10)]);
    $disliked = $service->buildProfile([$signal(1, 'completed', [1], 1)]);
    $assert($liked['genreWeights'] === [1 => 1.0] && $liked['preferredGenreIds'] === [1]);
    $assert($disliked['genreWeights'] === [1 => -1.0] && $disliked['preferredGenreIds'] === []);
    $assert($service->buildProfile([])['genreWeights'] === []);
});

$run('Rzadsze motywy odróżniają zainteresowania od samych popularnych gatunków', static function () use ($service, $signal, $assert): void {
    $profile = $service->buildProfile([
        $signal(1, 'completed', [1]), $signal(2, 'completed', [2]),
    ], [1 => 1.5, 2 => 3.0]);
    $assert($profile['genreWeights'][2] > $profile['genreWeights'][1]);
    $assert($profile['preferredGenreIds'] === [2, 1]);
});

$run('Duża wysoko oceniana lista odróżnia ulubione anime od słabszych własnych ocen', static function () use ($service, $signal, $assert): void {
    $signals = [];
    for ($id = 1; $id <= 15; $id++) {
        $signals[] = $signal($id, 'completed', [1], 9);
    }
    $signals[] = $signal(20, 'completed', [2], 6);
    $signals[] = $signal(21, 'completed', [3], 10);
    $signals[] = $signal(22, 'completed', [4], 8);
    $profile = $service->buildProfile($signals);
    $assert($profile['genreWeights'][2] < 0, 'Ocena 6 nie jest mocnym pozytywem przy zwykłych ocenach 9.');
    $assert($profile['genreWeights'][3] > $profile['genreWeights'][4], 'Ulubione anime ma większą siłę niż jedynie dobre.');
    $assert($profile['genreWeights'][4] > 0, 'Ocena 8 nadal może wzmacniać preferencję.');
});

$run('Nieprawidłowe ID użytkownika jest odrzucane przed dostępem do repozytorium', static function () use ($service, $assert): void {
    foreach ([0, -1, PHP_INT_MIN] as $userId) {
        $rejected = false;
        try {
            $service->recommendForUser($userId);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected);
    }
});

echo PHP_EOL . 'Wynik: ' . $passed . ' OK, ' . $failed . ' błędów.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
