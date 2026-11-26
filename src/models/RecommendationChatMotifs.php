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
        'ryba' => 'fish', 'ryby' => 'fish', 'ryb' => 'fish', 'rybach' => 'fish', 'rybami' => 'fish',
        'fishes' => 'fish', 'wędkarstwo' => 'fishing', 'wędkarstwie' => 'fishing',
        'wędkowanie' => 'fishing', 'wędkowaniu' => 'fishing', 'łowienie ryb' => 'fishing', 'łowieniu ryb' => 'fishing',
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
        'fish' => [
            'fish', 'fishing', 'fisherman', 'angler', 'fishkeeping', 'aquarium',
            'ryba', 'ryby', 'ryb', 'rybach', 'rybami', 'wędkowanie', 'wędkarstwo', 'akwarium',
        ],
        'fishing' => [
            'fishing', 'angler', 'fisherman', 'fishing rod', 'fishing club', 'fly fishing',
            'wędkowanie', 'wędkowaniu', 'wędkarstwo', 'wędkarstwie', 'łowienie ryb', 'łowieniu ryb',
        ],
    ];

    public static function fromSimpleRequest(string $message): ?string
    {
        // Recover only a short, standalone topic. Leave compound requests to the interpreter.
        if (preg_match('/\A(?:(?:szukam|znajd[źz]|poka[żz]|find(?: me)?|show(?: me)?)\h+)?'
            . 'anime\h+(o|about|z|with)\h+([\p{L}][\p{L}\p{M}-]*(?:\h+[\p{L}][\p{L}\p{M}-]*){0,3})[.!?]*\z/iu',
            trim($message), $matches) !== 1 || mb_strlen($matches[2]) > 100) {
            return null;
        }
        $topic = self::canonical($matches[2]);
        if (in_array(mb_strtolower($matches[1]), ['z', 'with'], true) && !self::hasPhraseVariants($topic)) {
            return null;
        }
        $words = preg_split('/\h+/u', $topic);
        if (array_intersect($words, [
            'i', 'lub', 'albo', 'ale', 'bez', 'z', 'do', 'od', 'po', 'przed', 'ponad', 'minimum', 'co',
            'tag', 'tagiem', 'tagu', 'gatunku', 'and', 'or', 'but', 'with', 'without', 'from', 'after', 'before', 'tagged',
            'tym', 'czymś', 'czymkolwiek', 'cokolwiek', 'anything', 'something', 'stuff',
        ]) !== []) {
            return null;
        }
        return $topic;
    }

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
