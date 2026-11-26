<?php

declare(strict_types=1);

namespace App\Model;

final class AnimeAiringStatus
{
    public const VALUES = ['finished', 'airing', 'upcoming'];

    public static function fromSource(mixed $value): ?string
    {
        return match (is_string($value) ? strtolower(trim($value)) : '') {
            'finished', 'finished airing' => 'finished',
            'airing', 'currently airing', 'ongoing' => 'airing',
            'upcoming', 'not yet aired' => 'upcoming',
            default => null,
        };
    }
}
