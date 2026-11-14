<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\QuizQuestions;

final class QuizQuestionLocalizer
{
    /** Localize presentation only: IDs, option indices and the saved score never change. */
    public static function localize(array $quiz, string $locale): array
    {
        $bank = null;
        foreach ($quiz['questions'] as &$question) {
            // The first release stored Polish-only snapshots. Recover translations
            // only when the ID, wording and all answer meanings still match.
            if (!isset($question['translations'])) {
                $bank ??= array_column(QuizQuestions::all(), null, 'id');
                $original = $bank[$question['id']] ?? null;
                if ($original !== null && $original['question'] === $question['question']) {
                    $order = array_map(static fn (string $option): int|false => array_search($option, $original['options'], true), $question['options']);
                    if (count($order) === 4 && !in_array(false, $order, true) && count(array_unique($order)) === 4) {
                        $question['translations'] = $original['translations'];
                        foreach ($question['translations'] as &$translation) {
                            $translation['options'] = array_map(static fn (int $index): string => $translation['options'][$index], $order);
                        }
                        unset($translation);
                        $question['difficulty'] = $original['difficulty'];
                        $question['type'] = $original['type'];
                        $question['animeKey'] = $original['animeKey'];
                    }
                }
            }
            $translation = $question['translations'][$locale] ?? null;
            if ($locale !== 'pl' && is_array($translation)) {
                foreach (['question', 'options', 'explanation'] as $field) {
                    $question[$field] = $translation[$field];
                }
            }
            $question['difficulty'] ??= 'easy';
            $question['type'] ??= 'trivia';
            unset($question['translations']);
        }
        unset($question);

        return $quiz;
    }
}
