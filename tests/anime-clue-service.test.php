<?php

declare(strict_types=1);

use App\Service\AnimeClueService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) throw new RuntimeException($message);
};
$run = static function (string $name, callable $operation) use (&$passed, &$failed): void {
    try {
        $operation();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
};
$entries = [];
for ($index = 1; $index <= 8; $index++) {
    $entries[] = ['id' => $index, 'title' => 'Anime Secret ' . $index, 'aliases' => ['Other Alias ' . $index],
        'franchise' => 'franchise-' . $index, 'sourceUrl' => 'https://example.test/secret-' . $index,
        'clues' => ['pl' => ['Widoczna ' . $index, 'Ukryta druga ' . $index, 'Ukryta trzecia ' . $index],
            'en' => ['Visible ' . $index, 'Hidden second ' . $index, 'Hidden third ' . $index]]];
}

$run('Punktacja daje 3/2/1 za poprawne rozpoznanie i zero za błędną odpowiedź', static function () use ($assert): void {
    foreach ([1 => 3, 2 => 2, 3 => 1] as $hints => $points) {
        $assert(AnimeClueService::points(true, $hints) === $points);
        $assert(AnimeClueService::points(false, $hints) === 0);
    }
    foreach ([0, 4, -1] as $hints) {
        $rejected = false;
        try { AnimeClueService::points(true, $hints); } catch (InvalidArgumentException) { $rejected = true; }
        $assert($rejected);
    }
});

$run('Tytuły akceptują wielkość liter, odstępy, znaki interpunkcyjne i diakrytykę', static function () use ($assert): void {
    $entry = ['title' => 'Koe no Katachi', 'aliases' => ['A Silent Voice']];
    foreach (['  KOE NO KATACHI ', 'Kōe-no-Katachi!', 'A silent voice', 'A.Silent:Voice'] as $answer) {
        $assert(AnimeClueService::matchesAnswer($answer, $entry), $answer);
    }
    foreach (['Koe', 'Silent', 'A Silent Voice sequel', 'another anime', ''] as $answer) {
        $assert(!AnimeClueService::matchesAnswer($answer, $entry));
    }
});

$run('Puste, zagnieżdżone, niepoprawne UTF-8 i zbyt długie odpowiedzi są odrzucane', static function () use ($assert): void {
    foreach (['', '   ', '!!!', [], ['title'], null, true, 15, str_repeat('a', 201), str_repeat('Ł', 201), "abc\nxyz", "abc\0", "\xFF"] as $value) {
        $rejected = false;
        try { AnimeClueService::validateAnswer($value); } catch (InvalidArgumentException $exception) { $rejected = $exception->getMessage() === 'guess.error.answer'; }
        $assert($rejected);
    }
    $assert(AnimeClueService::validateAnswer('  Anime title  ') === 'Anime title');
    $assert(AnimeClueService::validateAnswer(str_repeat('Ł', 200)) === str_repeat('Ł', 200));
});

$run('Dobór bierze pięć różnych franczyz, preferując niewykorzystane i najstarsze', static function () use ($assert, $entries): void {
    $usage = [6 => '2026-11-01', 7 => '2026-11-02', 8 => '2026-11-03'];
    $selected = AnimeClueService::selectEntries($entries, $usage);
    $ids = array_column($selected, 'id');
    sort($ids);
    $assert($ids === [1, 2, 3, 4, 5]);
    $usage[5] = '2026-11-10';
    $selected = AnimeClueService::selectEntries($entries, $usage);
    $ids = array_column($selected, 'id');
    sort($ids);
    $assert($ids === [1, 2, 3, 4, 6]);
    $duplicates = $entries;
    $duplicates[1]['franchise'] = $duplicates[0]['franchise'];
    $assert(count(array_unique(array_column(AnimeClueService::selectEntries($duplicates), 'franchise'))) === 5);
});

$run('Wykorzystana pula może powtarzać pytania bez blokowania kolejnych dni', static function () use ($assert, $entries): void {
    $usage = array_fill_keys(array_column($entries, 'id'), '2026-11-01');
    $assert(count(AnimeClueService::selectEntries($entries, $usage)) === 5);
    $rejected = false;
    try { AnimeClueService::selectEntries(array_slice($entries, 0, 4)); } catch (RuntimeException) { $rejected = true; }
    $assert($rejected);
});

$run('Pierwszy widok nie ujawnia żadnego tytułu, aliasu, źródła ani kolejnych wskazówek', static function () use ($assert, $entries): void {
    $game = ['id' => 22, 'date' => '2026-11-15', 'entries' => array_slice($entries, 0, 5)];
    $attempt = ['roundIndex' => 0, 'hintsUsed' => 1, 'score' => 0, 'correctCount' => 0];
    $state = AnimeClueService::publicState($game, $attempt, [], 'en');
    $assert($state['clues'] === ['Visible 1'] && $state['results'] === [] && !$state['completed']);
    $encoded = json_encode($state, JSON_THROW_ON_ERROR);
    foreach (['Secret', 'Alias', 'source', 'franchise', 'Hidden', 'Visible 2'] as $secret) {
        $assert(!str_contains($encoded, $secret), $secret);
    }
});

$run('Zmiana języka pokazuje dokładnie tyle wskazówek, ile odkrył serwer', static function () use ($assert, $entries): void {
    $game = ['id' => 22, 'date' => '2026-11-15', 'entries' => array_slice($entries, 0, 5)];
    foreach ([1, 2, 3] as $hints) {
        $attempt = ['roundIndex' => 1, 'hintsUsed' => $hints, 'score' => 3, 'correctCount' => 1];
        foreach (['pl', 'en'] as $locale) {
            $state = AnimeClueService::publicState($game, $attempt, [], $locale);
            $assert($state['clues'] === array_slice($entries[1]['clues'][$locale], 0, $hints));
            $assert($state['hintsUsed'] === $hints && $state['score'] === 3 && $state['roundIndex'] === 1);
        }
    }
});

$run('Omówienie ujawnia wyłącznie zamknięte rundy, a koniec dnia nie pokazuje kolejnej zagadki', static function () use ($assert, $entries): void {
    $game = ['id' => 22, 'date' => '2026-11-15', 'entries' => array_slice($entries, 0, 5)];
    $attempt = ['roundIndex' => 1, 'hintsUsed' => 1, 'score' => 3, 'correctCount' => 1];
    $answer = ['roundIndex' => 0, 'answer' => 'user text', 'correct' => true, 'points' => 3, 'hintsUsed' => 1, 'skipped' => false];
    $state = AnimeClueService::publicState($game, $attempt, [$answer], 'pl');
    $assert($state['results'][0]['title'] === $entries[0]['title']);
    $encoded = json_encode($state, JSON_THROW_ON_ERROR);
    $assert(!str_contains($encoded, $entries[1]['title']) && !str_contains($encoded, $entries[2]['title']));
    $attempt['roundIndex'] = 5;
    $completed = AnimeClueService::publicState($game, $attempt, [$answer], 'en');
    $assert($completed['completed'] && $completed['clues'] === [] && $completed['maxPoints'] === 15 && $completed['roundCount'] === 5);
});

$run('Historia błędów oddziela bieżącą rundę od zamkniętych i obsługuje starsze wyniki', static function () use ($assert, $entries): void {
    $game = ['id' => 22, 'date' => '2026-11-15', 'entries' => array_slice($entries, 0, 5)];
    $attempt = ['roundIndex' => 1, 'hintsUsed' => 2, 'score' => 0, 'correctCount' => 0,
        'wrongAnswers' => [['roundIndex' => 0, 'answer' => 'Earlier wrong', 'hintsUsed' => 1],
            ['roundIndex' => 1, 'answer' => '<wrong current>', 'hintsUsed' => 1]]];
    $answer = ['roundIndex' => 0, 'answer' => 'Final old wrong', 'correct' => false, 'points' => 0, 'hintsUsed' => 3, 'skipped' => false];
    $state = AnimeClueService::publicState($game, $attempt, [$answer], 'pl');
    $assert($state['wrongAnswers'] === [['answer' => '<wrong current>', 'hintsUsed' => 1]]);
    $assert($state['results'][0]['wrongAnswers'] === [['answer' => 'Earlier wrong', 'hintsUsed' => 1], ['answer' => 'Final old wrong', 'hintsUsed' => 3]]);
    $attempt['wrongAnswers'][] = ['roundIndex' => 0, 'answer' => 'Final old wrong', 'hintsUsed' => 3];
    $state = AnimeClueService::publicState($game, $attempt, [$answer], 'pl');
    $assert(count($state['results'][0]['wrongAnswers']) === 2, 'The final wrong answer is not duplicated.');
    $assert(!str_contains(json_encode($state, JSON_THROW_ON_ERROR), $entries[1]['title']));
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
