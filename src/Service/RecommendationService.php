<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RecommendationRepository;
use InvalidArgumentException;

final class RecommendationService
{
    public function __construct(private readonly RecommendationRepository $recommendations)
    {
    }

    /** @return array{entries: list<array>, preferredGenreIds: list<int>, sourceCount: int, mode: 'personalized'|'starter'} */
    public function recommendForUser(int $userId, int $limit = 6): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator użytkownika musi być dodatni.');
        }

        $signals = $this->weightedSignals($this->recommendations->signalsForUser($userId));
        $statistics = $this->recommendations->catalogStatistics();
        $rarity = [];
        foreach ($statistics['genreCounts'] as $genreId => $count) {
            $rarity[$genreId] = min(4.0, 1.0 + log(($statistics['total'] + 1) / ($count + 1)));
        }
        $profile = $this->buildProfile($signals, $rarity);
        $personalized = $profile['preferredGenreIds'] !== [];
        $entries = (new RecommendationRanker())->rank(
            $this->recommendations->candidatePoolForUser($userId, $profile['genreWeights']),
            $signals, $profile, $rarity, max(1, min(12, $limit)),
        );

        return [
            'entries' => $entries,
            'preferredGenreIds' => $profile['preferredGenreIds'],
            'sourceCount' => count($signals),
            'mode' => $personalized ? 'personalized' : 'starter',
        ];
    }

    /**
     * @param list<array> $signals
     * @param array<int, float> $genreRarity
     * @return array{genreWeights: array<int, float>, typeWeights: array<string, float>, preferredGenreIds: list<int>}
     */
    public function buildProfile(array $signals, array $genreRarity = []): array
    {
        $genreWeights = [];
        $typeWeights = [];

        foreach ($this->weightedSignals($signals) as $signal) {
            $weight = $signal['weight'];
            // Każde anime wnosi łącznie tę samą wagę niezależnie od liczby gatunków.
            $genreIds = array_values(array_unique($signal['genreIds']));
            foreach ($genreIds as $genreId) {
                $genreWeights[$genreId] = ($genreWeights[$genreId] ?? 0.0) + $weight / count($genreIds);
            }
            $type = strtoupper(trim($signal['type']));
            if ($type === 'TV SPECIAL') {
                $type = 'SPECIAL';
            }
            if ($type !== '' && $type !== 'UNKNOWN') {
                $typeWeights[$type] = ($typeWeights[$type] ?? 0.0) + $weight;
            }
        }

        foreach ($genreWeights as $genreId => &$weight) {
            $weight *= $genreRarity[$genreId] ?? 1.0;
        }
        unset($weight);
        $genreWeights = $this->normalize($genreWeights);
        $typeWeights = $this->normalize($typeWeights);
        $preferredGenreIds = array_keys(array_filter($genreWeights, static fn (float $weight): bool => $weight > 0));
        usort($preferredGenreIds, static fn (int $first, int $second): int =>
            ($genreWeights[$second] <=> $genreWeights[$first]) ?: ($first <=> $second));

        return [
            'genreWeights' => $genreWeights,
            'typeWeights' => $typeWeights,
            'preferredGenreIds' => array_slice($preferredGenreIds, 0, 3),
        ];
    }

    /** @param list<array> $signals @return list<array> */
    private function weightedSignals(array $signals): array
    {
        $ratings = array_column(array_filter($signals, static fn (array $signal): bool =>
            $signal['rating'] !== null && $signal['status'] !== 'dropped'), 'rating');
        sort($ratings, SORT_NUMERIC);
        $count = count($ratings);
        // A large, generously scored watchlist needs to distinguish favourites from merely finished titles.
        $baseline = $count >= 10
            ? max(5.0, min(9.0, ($ratings[intdiv($count - 1, 2)] + $ratings[intdiv($count, 2)]) / 2))
            : null;
        foreach ($signals as &$signal) {
            $weight = match ($signal['status']) {
                'completed' => 3.0, 'watching' => 2.0, 'planned' => 1.0,
                'on_hold' => 0.5, 'dropped' => -2.0, default => 0.0,
            };
            $rating = $signal['rating'];
            if ($signal['status'] !== 'dropped' && $rating !== null) {
                $weight = match (true) {
                    $rating <= 4 => -abs($weight) * (5 - $rating) / 4,
                    $rating <= 6 => $weight * ($baseline === null ? 0.5 : 0.5 + 0.2 * ($rating - $baseline)),
                    default => $weight * (1 + ($rating - 6) / 4)
                        * ($baseline === null ? 1.0 : max(0.2, 1 + 0.35 * ($rating - $baseline))),
                };
            }
            $signal['weight'] = $weight;
        }
        unset($signal);

        return $signals;
    }

    /** @param array<int|string, float> $weights @return array<int|string, float> */
    private function normalize(array $weights): array
    {
        $weights = array_filter($weights, static fn (float $weight): bool => $weight !== 0.0);
        ksort($weights);
        $total = array_sum(array_map(abs(...), $weights));

        return $total === 0 ? [] : array_map(static fn (float $weight): float => $weight / $total, $weights);
    }
}
