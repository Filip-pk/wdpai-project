<?php

declare(strict_types=1);

namespace App\Service;

use App\Data\AnimeClues;

final class AnimeClueTitleSearch
{
    /** Search the whole bank, independently of the daily set and the user's round.
     * @return list<array{title: string, matchedTitle: string}>
     */
    public static function suggestions(mixed $query): array
    {
        if (!is_string($query) || strlen($query) > 800 || preg_match('//u', $query) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $query) === 1 || preg_match_all('/./us', $query) > 200) {
            return [];
        }
        $query = AnimeClueService::normalizeAnswer($query);
        if (preg_match_all('/./us', $query) < 2) {
            return [];
        }

        $matches = [];
        foreach (AnimeClues::all() as $entry) {
            $best = null;
            foreach ([$entry['title'], ...$entry['aliases']] as $title) {
                $normalized = AnimeClueService::normalizeAnswer($title);
                if (!str_contains($normalized, $query)) {
                    continue;
                }
                $priority = $normalized === $query ? 0 : (str_starts_with($normalized, $query) ? 1 : 2);
                if ($best === null || $priority < $best['priority']) {
                    $best = ['title' => $entry['title'], 'matchedTitle' => $title, 'priority' => $priority];
                }
            }
            if ($best !== null) {
                $matches[] = $best;
            }
        }
        usort($matches, static fn (array $left, array $right): int =>
            $left['priority'] <=> $right['priority'] ?: strnatcasecmp($left['title'], $right['title']));

        return array_map(static fn (array $match): array => [
            'title' => $match['title'], 'matchedTitle' => $match['matchedTitle'],
        ], array_slice($matches, 0, 10));
    }
}
