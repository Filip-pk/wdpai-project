<?php

declare(strict_types=1);

use App\Data\QuizQuestions;
use App\Service\QuizService;

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
$questions = [];
$correct = [];
$wrong = [];
for ($index = 0; $index < 5; $index++) {
    $id = $index + 11;
    $questions[] = ['id' => $id, 'correctOption' => $index % 4];
    $correct[$id] = $index % 4;
    $wrong[$id] = ($index + 1) % 4;
}

$run('Pula ma co najmniej 60 jednoznacznych pytań z kompletem odpowiedzi i źródeł', static function () use ($assert): void {
    $pool = QuizQuestions::all();
    $assert(count($pool) >= 60);
    $ids = [];
    $prompts = [];
    foreach ($pool as $question) {
        $assert(is_int($question['id']) && $question['id'] > 0 && !isset($ids[$question['id']]));
        $ids[$question['id']] = true;
        foreach (['question', 'explanation', 'sourceUrl'] as $field) {
            $assert(is_string($question[$field]) && trim($question[$field]) !== '' && preg_match('//u', $question[$field]) === 1);
        }
        $assert(!isset($prompts[$question['question']]), 'Pytania w puli muszą się różnić.');
        $prompts[$question['question']] = true;
        $assert(array_is_list($question['options']) && count($question['options']) === 4
            && count(array_unique($question['options'])) === 4);
        foreach ($question['options'] as $option) {
            $assert(is_string($option) && trim($option) !== '' && preg_match('//u', $option) === 1);
        }
        $assert(is_int($question['correctOption']) && $question['correctOption'] >= 0 && $question['correctOption'] <= 3);
        $assert(filter_var($question['sourceUrl'], FILTER_VALIDATE_URL) !== false
            && parse_url($question['sourceUrl'], PHP_URL_SCHEME) === 'https');
    }
});

$run('Pełny poprawny zestaw otrzymuje 5/5, a błędny 0/5', static function () use ($assert, $questions, $correct, $wrong): void {
    $assert(QuizService::scoreAnswers($questions, $correct) === ['score' => 5, 'answers' => $correct]);
    $assert(QuizService::scoreAnswers($questions, $wrong) === ['score' => 0, 'answers' => $wrong]);
});

$run('Odpowiedzi formularza są normalizowane po ID pytania, nie po kolejności', static function () use ($assert, $questions, $correct): void {
    $answers = array_reverse(array_map(static fn (int $value): string => (string) $value, $correct), true);
    $assert(QuizService::scoreAnswers($questions, $answers) === ['score' => 5, 'answers' => $correct]);
    $answers[11] = '3';
    $assert(QuizService::scoreAnswers($questions, $answers)['score'] === 4);
});

$run('Brakujące, nadmiarowe i obce ID odpowiedzi są odrzucane', static function () use ($assert, $questions, $correct): void {
    $missing = $correct;
    unset($missing[11]);
    $extra = $correct + [99 => 0];
    $foreign = $correct;
    unset($foreign[11]);
    $foreign[99] = 0;
    foreach ([$missing, $extra, $foreign, []] as $answers) {
        $rejected = false;
        try {
            QuizService::scoreAnswers($questions, $answers);
        } catch (InvalidArgumentException $exception) {
            $rejected = $exception->getMessage() === 'quiz.error.answers';
        }
        $assert($rejected);
    }
});

$run('Zagnieżdżone dane, boolean, float i niekanoniczne indeksy nie przechodzą walidacji', static function () use ($assert, $questions, $correct): void {
    foreach ([null, [], ['0'], true, false, 0.0, -1, 4, '04', '0.0', ' 0', '0 ', '+0', '1e0', new stdClass()] as $value) {
        $answers = $correct;
        $answers[11] = $value;
        $rejected = false;
        try {
            QuizService::scoreAnswers($questions, $answers);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected, 'Niedozwolona wartość odpowiedzi została zaakceptowana.');
    }
});

$run('Pusty udział ma serię i rekord zero', static function () use ($assert): void {
    $assert(QuizService::streaks([], '2026-11-14') === ['currentStreak' => 0, 'bestStreak' => 0, 'completedToday' => false]);
});

$run('Dzisiejszy pierwszy udział rozpoczyna serię od jednego', static function () use ($assert): void {
    $assert(QuizService::streaks(['2026-11-14'], '2026-11-14') === ['currentStreak' => 1, 'bestStreak' => 1, 'completedToday' => true]);
});

$run('Seria zakończona wczoraj pozostaje aktywna do końca dzisiaj', static function () use ($assert): void {
    $assert(QuizService::streaks(['2026-11-11', '2026-11-12', '2026-11-13'], '2026-11-14') === [
        'currentStreak' => 3, 'bestStreak' => 3, 'completedToday' => false,
    ]);
});

$run('Pominięty pełny dzień zeruje aktywną serię, zachowując rekord', static function () use ($assert): void {
    $assert(QuizService::streaks(['2026-11-10', '2026-11-11', '2026-11-12'], '2026-11-14') === [
        'currentStreak' => 0, 'bestStreak' => 3, 'completedToday' => false,
    ]);
    $assert(QuizService::streaks(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-14'], '2026-11-14') === [
        'currentStreak' => 1, 'bestStreak' => 3, 'completedToday' => true,
    ]);
});

$run('Powtórzone daty i dowolna kolejność nie nabijają serii', static function () use ($assert): void {
    $assert(QuizService::streaks(['2026-11-14', '2026-11-12', '2026-11-14', '2026-11-13'], '2026-11-14') === [
        'currentStreak' => 3, 'bestStreak' => 3, 'completedToday' => true,
    ]);
});

$run('Zmiany czasu w Warszawie zachowują kolejne dni kalendarzowe', static function () use ($assert): void {
    foreach ([['2026-03-28', '2026-03-29', '2026-03-30'], ['2026-10-24', '2026-10-25', '2026-10-26']] as $dates) {
        $assert(QuizService::streaks($dates, $dates[2]) === ['currentStreak' => 3, 'bestStreak' => 3, 'completedToday' => true]);
    }
});

$run('Koniec roku i dzień przestępny nie przerywają serii', static function () use ($assert): void {
    foreach ([['2027-12-31', '2028-01-01'], ['2028-02-28', '2028-02-29', '2028-03-01']] as $dates) {
        $assert(QuizService::streaks($dates, end($dates))['currentStreak'] === count($dates));
    }
});

$run('Przyszłe daty nie tworzą dzisiejszego udziału ani rekordu', static function () use ($assert): void {
    $assert(QuizService::streaks(['2026-11-14', '2026-11-15', '2026-11-16'], '2026-11-14') === [
        'currentStreak' => 1, 'bestStreak' => 1, 'completedToday' => true,
    ]);
});

$run('Niepoprawne daty nie są automatycznie przesuwane na inny dzień', static function () use ($assert): void {
    foreach ([['2026-02-30', []], ['2026-11-14', ['2026-02-30']]] as [$today, $dates]) {
        $rejected = false;
        try {
            QuizService::streaks($dates, $today);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected);
    }
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
