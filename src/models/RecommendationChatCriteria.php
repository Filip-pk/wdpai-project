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
        'references' => [3, 255],
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

        $criteria = array_replace($previous, array_diff_key($changes, array_flip(['requireTags', 'excludeTags', 'forgetTags'])));
        [$criteria['requireTags'], $criteria['excludeTags']] = self::applyTags($previous, $changes, $catalogTags);
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

    private static function applyTags(array $previous, array $changes, array $catalogTags): array
    {
        $byName = static function (array $names): array {
            $map = [];
            foreach ($names as $name) {
                $map[mb_strtolower($name)] = $name;
            }
            return $map;
        };
        $canonical = $byName($catalogTags);
        $include = $byName($previous['requireTags'] ?? []);
        $exclude = $byName($previous['excludeTags'] ?? []);
        foreach ($byName($changes['forgetTags'] ?? []) as $key => $name) {
            unset($include[$key], $exclude[$key]);
        }
        foreach ($byName($changes['requireTags'] ?? []) as $key => $name) {
            $include[$key] = $canonical[$key] ?? $name;
            unset($exclude[$key]);
        }
        foreach ($byName($changes['excludeTags'] ?? []) as $key => $name) {
            $exclude[$key] = $canonical[$key] ?? $name;
            unset($include[$key]);
        }
        if (count($include) > self::LISTS['requireTags'][0] || count($exclude) > self::LISTS['excludeTags'][0]) {
            throw new InvalidArgumentException('Too many active chat tags.');
        }
        ksort($include);
        ksort($exclude);

        return [array_values($include), array_values($exclude)];
    }
}
