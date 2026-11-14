<?php

declare(strict_types=1);

use App\Data\QuizQuestions;
use App\Data\QuizQuestionTranslations;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$translations = QuizQuestionTranslations::all();
$assert(array_keys($translations) === range(1, 60), 'All original question IDs must have translations.');
$questions = array_column(QuizQuestions::all(), null, 'id');
foreach ($translations as $id => $locales) {
    $assert(array_keys($locales) === ['en', 'de', 'fr', 'es', 'ru'], 'All five translations must be present for ID ' . $id);
    foreach ($locales as $locale => $question) {
        foreach (['question', 'explanation'] as $field) {
            $assert(is_string($question[$field]) && trim($question[$field]) !== ''
                && preg_match('//u', $question[$field]) === 1, 'Invalid translated text: ' . $id . '/' . $locale . '/' . $field);
            $assert($question[$field] !== $questions[$id][$field], 'Polish text must not be used as a translation: ' . $id . '/' . $locale . '/' . $field);
        }
        $assert(array_is_list($question['options']) && count($question['options']) === 4
            && count(array_unique($question['options'])) === 4, 'Four distinct translated choices: ' . $id . '/' . $locale);
        foreach ($question['options'] as $option) {
            $assert(is_string($option) && trim($option) !== '' && preg_match('//u', $option) === 1,
                'Invalid translated choice: ' . $id . '/' . $locale);
        }
        $assert(isset($question['options'][$questions[$id]['correctOption']]), 'The correct choice index must remain valid.');
        // These questions contain ordinary words rather than shared proper names.
        foreach ([2, 6, 7, 10, 13, 14, 15, 18, 20, 23, 25, 27, 29, 31, 34, 39, 40, 41, 44, 48, 50, 54, 55, 59] as $ordinaryId) {
            if ($ordinaryId === $id) {
                $assert($question['options'] !== $questions[$id]['options'], 'Ordinary choices must be translated: ' . $id . '/' . $locale);
            }
        }
    }
}

$assert($translations[2]['en']['options'][3] === 'Hidden Leaf', 'English village options keep the original answer position.');
$assert($translations[25]['de']['options'][3] === 'Volleyball', 'German sport options keep the original answer position.');
$assert($translations[22]['fr']['options'][0] === 'Alters', 'French uses the localized term for Quirks.');
$assert($translations[41]['es']['options'][1] === 'Siete', 'Spanish number options keep the original answer position.');
$assert($translations[59]['ru']['options'][1] === 'С бензопилой', 'Russian tool options keep the original answer position.');

echo '[OK] Original quiz questions: 60 IDs, five complete translations, stable answer positions.' . PHP_EOL;
