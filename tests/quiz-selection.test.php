<?php

declare(strict_types=1);

use App\Data\QuizQuestions;
use App\Service\QuizQuestionLocalizer;
use App\Service\QuizQuestionSelector;
use App\Service\QuizService;
use App\Service\Translator;

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
$fixture = [];
foreach ([['description', 'medium'], ['character', 'easy'], ['trivia', 'easy'], ['trivia', 'medium'], ['trivia', 'hard']] as $group => [$type, $difficulty]) {
    foreach ([0, 1] as $alternative) {
        $id = $group * 2 + $alternative + 1;
        $options = array_map(static fn (int $index): string => 'PL-choice-' . $id . '-' . $index, [0, 1, 2, 3]);
        $translations = [];
        foreach (['en', 'de', 'fr', 'es', 'ru'] as $locale) {
            $translations[$locale] = [
                'question' => $locale . '-question-' . $id,
                'options' => array_map(static fn (int $index): string => $locale . '-choice-' . $id . '-' . $index, [0, 1, 2, 3]),
                'explanation' => $locale . '-explanation-' . $id,
            ];
        }
        $fixture[] = [
            'id' => $id, 'animeKey' => 'anime-' . $id, 'type' => $type, 'difficulty' => $difficulty,
            'question' => 'PL-question-' . $id, 'options' => $options, 'correctOption' => $id % 4,
            'explanation' => 'PL-explanation-' . $id, 'translations' => $translations,
        ];
    }
}
$counts = static function (array $selected, string $field): array {
    $counts = array_count_values(array_column($selected, $field));
    ksort($counts);
    return $counts;
};
$expectedDifficulty = ['easy' => 2, 'hard' => 1, 'medium' => 2];
$expectedTypes = ['character' => 1, 'description' => 1, 'trivia' => 3];

$run('Każdy zestaw zawiera 2 łatwe, 2 średnie, 1 trudne pytanie i trzy formaty z pięciu anime', static function () use ($fixture, $counts, $expectedDifficulty, $expectedTypes, $assert): void {
    $original = $fixture;
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $selected = QuizQuestionSelector::select($fixture);
        $assert(count($selected) === 5 && count(array_unique(array_column($selected, 'id'))) === 5);
        $assert(count(array_unique(array_column($selected, 'animeKey'))) === 5);
        $assert($counts($selected, 'difficulty') === $expectedDifficulty);
        $assert($counts($selected, 'type') === $expectedTypes);
    }
    $assert($fixture === $original, 'Losowanie nie może modyfikować źródłowej puli.');
});

$run('Nieużywane pytania mają pierwszeństwo przed już opublikowanymi w każdej kategorii', static function () use ($fixture, $assert): void {
    $lastUsed = array_fill_keys([1, 3, 5, 7, 9], '2026-11-01');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $ids = array_column(QuizQuestionSelector::select($fixture, $lastUsed), 'id');
        sort($ids);
        $assert($ids === [2, 4, 6, 8, 10]);
    }
});

$run('Po użyciu całej kategorii wracają najdawniej publikowane pytania bez trwałego wykluczenia', static function () use ($fixture, $assert): void {
    $lastUsed = array_fill_keys([1, 3, 5, 7, 9], '2026-10-01') + array_fill_keys([2, 4, 6, 8, 10], '2026-11-13');
    $ids = array_column(QuizQuestionSelector::select($fixture, $lastUsed), 'id');
    sort($ids);
    $assert($ids === [1, 3, 5, 7, 9]);
    $minimal = array_values(array_filter($fixture, static fn (array $question): bool => $question['id'] % 2 === 1));
    $repeated = array_column(QuizQuestionSelector::select($minimal, $lastUsed), 'id');
    sort($repeated);
    $assert($repeated === [1, 3, 5, 7, 9], 'Skończona pula musi umożliwiać kolejne zestawy.');
});

$run('Dobór nie dopuszcza powtórzonego anime i potrafi ominąć kolidującą wcześniejszą opcję', static function () use ($fixture, $counts, $expectedDifficulty, $expectedTypes, $assert): void {
    $pool = $fixture;
    $pool[2]['animeKey'] = $pool[0]['animeKey'];
    $lastUsed = array_fill_keys([2, 4, 6, 8, 10], '2026-11-01');
    $selected = QuizQuestionSelector::select($pool, $lastUsed);
    $assert(count(array_unique(array_column($selected, 'animeKey'))) === 5);
    $assert($counts($selected, 'difficulty') === $expectedDifficulty && $counts($selected, 'type') === $expectedTypes);
    $impossible = array_map(static function (array $question): array { $question['animeKey'] = 'same-anime'; return $question; }, $fixture);
    $rejected = false;
    try {
        QuizQuestionSelector::select($impossible);
    } catch (RuntimeException) {
        $rejected = true;
    }
    $assert($rejected, 'Niemożliwej puli nie wolno przedstawiać jako różnorodnego zestawu.');
});

$run('Tasowanie opcji zachowuje znaczenie poprawnej odpowiedzi we wszystkich językach', static function () use ($fixture, $assert): void {
    $originals = array_column($fixture, null, 'id');
    for ($attempt = 0; $attempt < 25; $attempt++) {
        $selected = QuizQuestionSelector::select($fixture);
        foreach ($selected as $question) {
            $original = $originals[$question['id']];
            $assert($question['options'][$question['correctOption']] === $original['options'][$original['correctOption']]);
            foreach (['en', 'de', 'fr', 'es', 'ru'] as $locale) {
                $assert($question['translations'][$locale]['options'][$question['correctOption']]
                    === $original['translations'][$locale]['options'][$original['correctOption']]);
            }
        }
    }
});

$run('Lokalizacja nowego snapshotu zmienia wyłącznie prezentację i nie wpływa na zapisane indeksy ani wynik', static function () use ($fixture, $assert): void {
    $quiz = ['id' => 17, 'date' => '2026-11-14', 'questions' => QuizQuestionSelector::select($fixture)];
    $unchanged = $quiz;
    $answers = array_column($quiz['questions'], 'correctOption', 'id');
    foreach (array_keys(Translator::LANGUAGES) as $locale) {
        $localized = QuizQuestionLocalizer::localize($quiz, $locale);
        $assert($localized['id'] === $quiz['id'] && $localized['date'] === $quiz['date']);
        foreach ($localized['questions'] as $index => $question) {
            $original = $quiz['questions'][$index];
            $assert($question['id'] === $original['id'] && $question['correctOption'] === $original['correctOption']);
            $assert($question['animeKey'] === $original['animeKey'] && $question['difficulty'] === $original['difficulty'] && $question['type'] === $original['type']);
            $expected = $locale === 'pl' ? $original : $original['translations'][$locale];
            foreach (['question', 'options', 'explanation'] as $field) {
                $assert($question[$field] === $expected[$field]);
            }
            $assert(!isset($question['translations']), 'Widok otrzymuje tylko treść wybranego języka.');
        }
        $assert(QuizService::scoreAnswers($localized['questions'], $answers)['score'] === 5);
    }
    $assert($quiz === $unchanged, 'Lokalizacja nie może zmieniać snapshotu używanego przez serwer do sprawdzania odpowiedzi.');
});

$run('Wszystkie 200 pytań ma komplet sześciu języków oraz jednoznaczne opcje', static function () use ($assert): void {
    $pool = QuizQuestions::all();
    $assert(count($pool) === 200);
    $ids = array_column($pool, 'id');
    sort($ids);
    $assert($ids === range(1, 200));
    $assert(count(array_filter($pool, static fn (array $question): bool => $question['type'] === 'description')) === 10);
    foreach ($pool as $question) {
        $assert(in_array($question['difficulty'], ['easy', 'medium', 'hard'], true));
        $assert(in_array($question['type'], ['trivia', 'description', 'character'], true));
        $assert(is_string($question['animeKey']) && $question['animeKey'] !== '');
        $assert(is_int($question['correctOption']) && $question['correctOption'] >= 0 && $question['correctOption'] <= 3);
        foreach (array_keys(Translator::LANGUAGES) as $locale) {
            $text = $locale === 'pl' ? $question : ($question['translations'][$locale] ?? []);
            foreach (['question', 'explanation'] as $field) {
                $assert(is_string($text[$field] ?? null) && trim($text[$field]) !== '' && preg_match('//u', $text[$field]) === 1,
                    'Niepełne tłumaczenie: ' . $question['id'] . '/' . $locale . '/' . $field);
            }
            $assert(array_is_list($text['options']) && count($text['options']) === 4 && count(array_unique($text['options'])) === 4,
                'Niepoprawne opcje: ' . $question['id'] . '/' . $locale);
            foreach ($text['options'] as $option) {
                $assert(is_string($option) && trim($option) !== '' && preg_match('//u', $option) === 1);
            }
        }
    }
});

$run('Stary polski snapshot z przestawionymi opcjami otrzymuje tłumaczenia bez zmiany oceniania', static function () use ($assert): void {
    $bank = array_column(QuizQuestions::all(), null, 'id');
    $order = [3, 0, 2, 1];
    $quiz = ['id' => 3, 'date' => '2026-11-13', 'questions' => []];
    foreach ([1, 2, 10, 41, 48] as $id) {
        $question = $bank[$id];
        $question['options'] = array_map(static fn (int $index): string => $question['options'][$index], $order);
        $question['correctOption'] = array_search($question['correctOption'], $order, true);
        unset($question['translations'], $question['animeKey'], $question['difficulty'], $question['type']);
        $quiz['questions'][] = $question;
    }
    $unchanged = $quiz;
    $answers = array_column($quiz['questions'], 'correctOption', 'id');
    foreach (array_keys(Translator::LANGUAGES) as $locale) {
        $localized = QuizQuestionLocalizer::localize($quiz, $locale);
        foreach ($localized['questions'] as $index => $question) {
            $original = $quiz['questions'][$index];
            $source = $bank[$question['id']];
            $translation = $locale === 'pl' ? $source : $source['translations'][$locale];
            $assert($question['id'] === $original['id'] && $question['correctOption'] === $original['correctOption']);
            $assert($question['question'] === $translation['question'] && $question['explanation'] === $translation['explanation']);
            $expectedOptions = array_map(static fn (int $option): string => $translation['options'][$option], $order);
            $assert($question['options'] === $expectedOptions);
            $assert($question['type'] === $source['type'] && $question['difficulty'] === $source['difficulty']);
        }
        $assert(QuizService::scoreAnswers($localized['questions'], $answers)['score'] === 5);
    }
    $assert($quiz === $unchanged);
});

$run('Niezgodnego starego snapshotu nie wolno po cichu zastąpić zmienioną treścią puli', static function () use ($assert): void {
    $question = QuizQuestions::all()[0];
    unset($question['translations'], $question['animeKey'], $question['difficulty'], $question['type']);
    $question['options'][0] = 'Zmieniona dawna odpowiedź';
    $quiz = ['id' => 4, 'date' => '2026-11-12', 'questions' => [$question]];
    $localized = QuizQuestionLocalizer::localize($quiz, 'en');
    foreach (['id', 'question', 'options', 'correctOption', 'explanation'] as $field) {
        $assert($localized['questions'][0][$field] === $question[$field]);
    }
});

$run('Pełna pula tworzy zróżnicowany zestaw także po wykorzystaniu wszystkich pytań', static function () use ($counts, $expectedDifficulty, $expectedTypes, $assert): void {
    $pool = QuizQuestions::all();
    $usage = array_fill_keys(array_column($pool, 'id'), '2026-11-01');
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $selected = QuizQuestionSelector::select($pool, $usage);
        $assert($counts($selected, 'difficulty') === $expectedDifficulty && $counts($selected, 'type') === $expectedTypes);
        $assert(count(array_unique(array_column($selected, 'animeKey'))) === 5);
        foreach ($selected as $question) {
            $usage[$question['id']] = '2026-11-' . sprintf('%02d', min(28, $attempt + 2));
        }
    }
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
