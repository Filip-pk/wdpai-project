<?php

declare(strict_types=1);

namespace App\Model;

use InvalidArgumentException;
use stdClass;

final class RecommendationChatCriteria
{
    private const RANGES = [
        'minEpisodes' => ['integer', 1, 2147483647],
        'maxEpisodes' => ['integer', 1, 2147483647],
        'minYear' => ['integer', 1900, 2200],
        'maxYear' => ['integer', 1900, 2200],
        'minScore' => ['number', 0, 10],
        'maxScore' => ['number', 0, 10],
    ];
    private const ENUMS = [
        'format' => ['TV', 'MOVIE', 'OVA', 'ONA', 'SPECIAL', 'MUSIC', 'CM', 'PV'],
        'status' => AnimeAiringStatus::VALUES,
        'length' => ['short', 'long'],
    ];
    private const LISTS = [
        'requireTags' => [12, 100],
        'excludeTags' => [12, 100],
        'forgetTags' => [12, 100],
        'exactTags' => [12, 100],
        'references' => [3, 255],
        'motifs' => [6, 100],
        'excludeMotifs' => [6, 100],
        'forgetMotifs' => [12, 100],
        'themes' => [5, 80],
    ];

    public static function schema(): array
    {
        $properties = [];
        foreach (self::RANGES as $key => [$type, $minimum, $maximum]) {
            $properties[$key] = ['type' => [$type, 'null'], 'minimum' => $minimum, 'maximum' => $maximum];
        }
        foreach (self::ENUMS as $key => $values) {
            $properties[$key] = ['enum' => [...$values, null]];
        }
        foreach (self::LISTS as $key => [$maximum, $length]) {
            $properties[$key] = [
                'type' => 'array', 'maxItems' => $maximum,
                'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => $length],
            ];
        }

        return [
            'type' => 'object',
            'properties' => [
                'action' => ['enum' => ['search', 'more', 'clarify']],
                'changes' => ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false],
            ],
            'required' => ['action', 'changes'],
            'additionalProperties' => false,
        ];
    }

    /** @return array{action: string, criteria: array} */
    public static function apply(array $previous, stdClass $plan, array $catalogTags): array
    {
        if (count(get_object_vars($plan)) !== 2 || !in_array($plan->action ?? null, ['search', 'more', 'clarify'], true)
            || !(($plan->changes ?? null) instanceof stdClass)) {
            throw new InvalidArgumentException('Invalid chat plan.');
        }
        $changes = self::validateChanges(get_object_vars($plan->changes));
        if ($plan->action !== 'search') {
            if ($changes !== []) {
                throw new InvalidArgumentException('Unexpected changes for chat action.');
            }
            return ['action' => $plan->action, 'criteria' => $previous];
        }

        $terms = self::mergeTerms($previous, $changes, $catalogTags);
        $criteria = array_replace($previous, array_diff_key($changes, $terms + ['forgetTags' => [], 'forgetMotifs' => []]), $terms);
        foreach (['Episodes', 'Year', 'Score'] as $range) {
            $min = $criteria['min' . $range] ?? null;
            $max = $criteria['max' . $range] ?? null;
            if ($min !== null && $max !== null && $min > $max) {
                return ['action' => 'clarify', 'criteria' => $previous];
            }
        }
        $criteria = array_filter($criteria, static fn (mixed $value): bool => $value !== null && $value !== []);
        ksort($criteria);

        return ['action' => 'search', 'criteria' => $criteria];
    }

    private static function validateChanges(array $changes): array
    {
        foreach ($changes as $key => &$value) {
            if (isset(self::RANGES[$key])) {
                [$type, $min, $max] = self::RANGES[$key];
                if ($value !== null && ((!is_int($value) && ($type === 'integer' || !is_float($value)))
                    || !is_finite((float) $value) || $value < $min || $value > $max)) {
                    throw new InvalidArgumentException('Invalid chat range.');
                }
                $value = $type === 'number' && $value !== null ? (float) $value : $value;
            } elseif (isset(self::ENUMS[$key])) {
                if ($value !== null && !in_array($value, self::ENUMS[$key], true)) {
                    throw new InvalidArgumentException('Invalid chat enum.');
                }
            } elseif (isset(self::LISTS[$key])) {
                [$max, $length] = self::LISTS[$key];
                if (!is_array($value) || !array_is_list($value) || count($value) > $max) {
                    throw new InvalidArgumentException('Invalid chat list.');
                }
                foreach ($value as &$item) {
                    if (!is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > $length
                        || preg_match('/[\x00-\x1F\x7F]/u', $item) === 1) {
                        throw new InvalidArgumentException('Invalid chat term.');
                    }
                    $item = trim($item);
                }
                unset($item);
                $value = array_values(array_unique($value));
            } else {
                throw new InvalidArgumentException('Unknown chat criterion.');
            }
        }
        unset($value);

        return $changes;
    }

    private static function mergeTerms(array $previous, array $changes, array $catalogTags): array
    {
        $knownTags = self::tagMap($catalogTags);
        [$requiredFromMotifs, $newMotifs] = self::matchMotifsWithTags($changes['motifs'] ?? [], $knownTags);
        [$excludedFromMotifs, $newExcludedMotifs] = self::matchMotifsWithTags($changes['excludeMotifs'] ?? [], $knownTags);
        $newRequired = self::tagMap($changes['requireTags'] ?? []) + $requiredFromMotifs;
        $newExcluded = self::tagMap($changes['excludeTags'] ?? []) + $excludedFromMotifs;
        $forgetTags = $changes['forgetTags'] ?? [];
        $exactTags = self::tagMap($changes['exactTags'] ?? [])
            + array_diff_key(self::tagMap($previous['exactTags'] ?? []), self::tagMap($forgetTags));
        [$requiredTags, $excludedTags] = self::editLists(
            self::tagMap($previous['requireTags'] ?? []),
            self::tagMap($previous['excludeTags'] ?? []),
            $newRequired,
            $newExcluded,
            self::tagMap($forgetTags),
        );
        [$requiredTags, $fromRequired] = self::partitionTags($requiredTags, $knownTags, $exactTags);
        [$excludedTags, $fromExcluded] = self::partitionTags($excludedTags, $knownTags, $exactTags);

        // Older sessions can contain a motif saved as a guessed tag. Resolve it on the next search.
        [$motifs, $excludeMotifs] = self::editLists(
            self::motifMap($previous['motifs'] ?? []),
            self::motifMap($previous['excludeMotifs'] ?? []),
            $fromRequired,
            $fromExcluded,
            [],
        );
        $tagsAdded = array_intersect_key($requiredTags + $excludedTags, $newRequired + $newExcluded);
        [$motifs, $excludeMotifs] = self::editLists(
            $motifs,
            $excludeMotifs,
            $newMotifs,
            $newExcludedMotifs,
            self::motifMap([...$forgetTags, ...$tagsAdded, ...($changes['forgetMotifs'] ?? [])]),
        );
        $terms = [
            'requireTags' => $requiredTags, 'excludeTags' => $excludedTags,
            'exactTags' => array_intersect_key($requiredTags + $excludedTags, $exactTags),
            'motifs' => $motifs, 'excludeMotifs' => $excludeMotifs,
        ];
        foreach ($terms as $key => &$values) {
            if (count($values) > self::LISTS[$key][0]) {
                throw new InvalidArgumentException('Too many active chat terms.');
            }
            ksort($values);
            $values = array_values($values);
        }
        unset($values);

        return $terms;
    }

    private static function tagMap(array $tags): array
    {
        return array_combine(array_map(mb_strtolower(...), $tags), $tags);
    }

    private static function motifMap(array $motifs): array
    {
        $names = array_map(RecommendationChatMotifs::canonical(...), $motifs);
        return array_combine($names, $names);
    }

    private static function matchMotifsWithTags(array $motifs, array $knownTags): array
    {
        $descriptive = self::motifMap($motifs);
        $strict = [];
        foreach ($descriptive as $name) {
            if (isset($knownTags[$name]) && !RecommendationChatMotifs::hasPhraseVariants($name)) {
                $strict[$name] = $knownTags[$name];
                unset($descriptive[$name]);
            }
        }
        return [$strict, $descriptive];
    }

    private static function partitionTags(array $tags, array $knownTags, array $exactTags): array
    {
        $strict = [];
        $descriptive = [];
        foreach ($tags as $key => $tag) {
            if (isset($knownTags[$key]) || isset($exactTags[$key])) {
                $strict[$key] = $knownTags[$key] ?? $tag;
            } else {
                $name = RecommendationChatMotifs::canonical($tag);
                $descriptive[$name] = $name;
            }
        }

        return [$strict, $descriptive];
    }

    private static function editLists(array $included, array $excluded, array $add, array $exclude, array $forget): array
    {
        foreach ($forget as $key => $name) {
            unset($included[$key], $excluded[$key]);
        }
        foreach ($add as $key => $name) {
            $included[$key] = $name;
            unset($excluded[$key]);
        }
        foreach ($exclude as $key => $name) {
            $excluded[$key] = $name;
            unset($included[$key]);
        }

        return [$included, $excluded];
    }
}
