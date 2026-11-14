<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class QuizQuestionSelector
{
    /**
     * Five different anime, two easy/two medium/one hard, and three question formats.
     * Oldest use wins within each eligible group; exhausted groups can repeat.
     * @param list<array> $pool
     * @param array<int, string> $lastUsed
     * @return list<array>
     */
    public static function select(array $pool, array $lastUsed = []): array
    {
        $groups = ['description' => [], 'character' => [], 'trivia' => []];
        shuffle($pool);
        usort($pool, static fn (array $a, array $b): int => strcmp($lastUsed[$a['id']] ?? '', $lastUsed[$b['id']] ?? ''));
        foreach ($pool as $question) {
            if (isset($groups[$question['type'] ?? ''])) {
                $groups[$question['type']][] = $question;
            }
        }
        $slots = ['description', 'character', 'trivia', 'trivia', 'trivia'];
        $budget = 10000;
        $choose = static function (int $slot, array $selected, array $remaining, array $anime) use (&$choose, &$budget, $groups, $slots): ?array {
            if ($slot === count($slots)) {
                return $selected;
            }
            foreach ($groups[$slots[$slot]] as $question) {
                $difficulty = $question['difficulty'];
                if (($remaining[$difficulty] ?? 0) === 0 || isset($anime[$question['animeKey']])) {
                    continue;
                }
                if (--$budget < 0) {
                    return null;
                }
                $nextRemaining = $remaining;
                $nextRemaining[$difficulty]--;
                $result = $choose($slot + 1, [...$selected, $question], $nextRemaining, $anime + [$question['animeKey'] => true]);
                if ($result !== null) {
                    return $result;
                }
            }
            return null;
        };
        $selected = $choose(0, [], ['easy' => 2, 'medium' => 2, 'hard' => 1], []);
        if ($selected === null) {
            throw new RuntimeException('Pula pytań nie pozwala utworzyć zróżnicowanego quizu.');
        }
        shuffle($selected);
        foreach ($selected as &$question) {
            $order = [0, 1, 2, 3];
            shuffle($order);
            $question['options'] = array_map(static fn (int $index): string => $question['options'][$index], $order);
            $question['correctOption'] = array_search($question['correctOption'], $order, true);
            foreach ($question['translations'] as &$translation) {
                $translation['options'] = array_map(static fn (int $index): string => $translation['options'][$index], $order);
            }
            unset($translation);
        }
        unset($question);

        return $selected;
    }
}
