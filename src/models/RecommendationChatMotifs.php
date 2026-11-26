<?php

declare(strict_types=1);

namespace App\Model;

final class RecommendationChatMotifs
{
    private const ALIASES = [
        'magia' => 'magic', 'magii' => 'magic', 'magią' => 'magic', 'magię' => 'magic',
        'school of magic' => 'magic school', 'magic academy' => 'magic school',
        'magical academy' => 'magic school', 'magical school' => 'magic school',
        'szkoła magii' => 'magic school', 'szkołę magii' => 'magic school',
        'szkole magii' => 'magic school', 'magiczna szkoła' => 'magic school', 'magicznej szkole' => 'magic school',
    ];

    private const PHRASES = [
        'magic' => [
            'magic', 'magical', 'sorcery', 'spellcasting', 'casting spells', 'casts spells',
            'wizard', 'wizardry', 'witchcraft', 'mage',
            'magia', 'magii', 'magią', 'magię', 'czary', 'czarodziej', 'czarodziejka', 'czarodziejów',
        ],
        'magic school' => [
            'magic school', 'magical school', 'school of magic', 'magic academy', 'magical academy',
            'academy of magic', 'academy for mages', 'wizarding school', 'school of sorcery',
            'szkoła magii', 'szkołę magii', 'szkole magii', 'szkoły magii',
            'magiczna szkoła', 'magicznej szkole', 'akademia magii', 'akademii magii',
        ],
    ];

    public static function canonical(string $name): string
    {
        $name = mb_strtolower(trim($name));
        return self::ALIASES[$name] ?? $name;
    }

    public static function hasPhraseVariants(string $motif): bool
    {
        return isset(self::PHRASES[self::canonical($motif)]);
    }

    /** @return list<string> */
    public static function searchPhrases(string $motif): array
    {
        $name = self::canonical($motif);
        return self::PHRASES[$name] ?? [$name];
    }

    public static function label(string $motif, callable $translate): string
    {
        $name = self::canonical($motif);
        return isset(self::PHRASES[$name]) ? $translate('chat.motif.' . str_replace(' ', '_', $name)) : $motif;
    }
}
