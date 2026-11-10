<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\RecommendationRepository;
use InvalidArgumentException;

final class RecommendationService
{
    public const GENRE_WEIGHT = 90;
    public const TYPE_WEIGHT = 10;

    public function __construct(private readonly RecommendationRepository $recommendations)
    {
    }

    /** @return array{entries: list<array{animeId: int, matchedGenreIds: list<int>}>, preferredGenreIds: list<int>, sourceCount: int, mode: 'personalized'|'starter'} */
    public function recommendForUser(int $userId, int $limit = 6): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator użytkownika musi być dodatni.');
        }

        $signals = $this->recommendations->signalsForUser($userId);
        $profile = $this->buildProfile($signals);
        $personalized = $profile['preferredGenreIds'] !== [];
        $entries = $this->recommendations->findCandidatesForUser(
            $userId,
            $profile['genreWeights'],
            $personalized ? $profile['typeWeights'] : [],
            max(1, min(12, $limit)),
        );

        foreach ($entries as &$entry) {
            $matchedGenres = array_values(array_filter(
                $entry['matchedGenreIds'],
                static fn (int $genreId): bool => ($profile['genreWeights'][$genreId] ?? 0.0) > 0,
            ));
            usort($matchedGenres, static fn (int $first, int $second): int =>
                ($profile['genreWeights'][$second] <=> $profile['genreWeights'][$first]) ?: ($first <=> $second));
            $entry['matchedGenreIds'] = array_slice($matchedGenres, 0, 2);
        }
        unset($entry);

        return [
            'entries' => $entries,
            'preferredGenreIds' => $profile['preferredGenreIds'],
            'sourceCount' => count($signals),
            'mode' => $personalized ? 'personalized' : 'starter',
        ];
    }

    /**
     * @param list<array{animeId: int, status: string, rating: ?int, genreIds: list<int>, type: string}> $signals
     * @return array{genreWeights: array<int, float>, typeWeights: array<string, float>, preferredGenreIds: list<int>}
     */
    public function buildProfile(array $signals): array
    {
        $genreWeights = [];
        $typeWeights = [];

        foreach ($signals as $signal) {
            $weight = match ($signal['status']) {
                'completed' => 3.0,
                'watching' => 2.0,
                'planned' => 1.0,
                'on_hold' => 0.5,
                'dropped' => -2.0,
                default => 0.0,
            };
            $rating = $signal['rating'];
            if ($signal['status'] !== 'dropped' && $rating !== null) {
                $weight = match (true) {
                    $rating <= 4 => -abs($weight) * (5 - $rating) / 4,
                    $rating <= 6 => $weight * 0.5,
                    default => $weight * (1 + ($rating - 6) / 4),
                };
            }

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

    /** @param array<int|string, float> $weights @return array<int|string, float> */
    private function normalize(array $weights): array
    {
        $weights = array_filter($weights, static fn (float $weight): bool => $weight !== 0.0);
        ksort($weights);
        $total = array_sum(array_map(abs(...), $weights));

        return $total === 0 ? [] : array_map(static fn (float $weight): float => $weight / $total, $weights);
    }
}
