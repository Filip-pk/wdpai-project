<?php

declare(strict_types=1);

namespace App\Data;

final class AnimeClues
{
    /** @return list<array{id: int, title: string, aliases: list<string>, franchise: string, clues: array<string, list<string>>, sourceUrl: string}> */
    public static function all(): array
    {
        $entries = [...AnimeCluesPartOne::all(), ...AnimeCluesPartTwo::all()];
        if (count($entries) !== 200) {
            throw new \RuntimeException('Pula rozpoznawania musi zawierać 200 anime.');
        }
        return $entries;
    }
}
