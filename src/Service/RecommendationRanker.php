<?php

declare(strict_types=1);

namespace App\Service;

/** Selects a small, varied set from the metadata candidates, without external calls. */
final class RecommendationRanker
{
    /**
     * @param list<array> $candidates
     * @param list<array> $signals Watchlist records with a signed weight computed for this user.
     * @param array{genreWeights: array<int, float>, typeWeights: array<string, float>} $profile
     * @param array<int, float> $genreRarity
     * @return list<array{animeId: int, matchedGenreIds: list<int>, reason: ?array}>
     */
    public function rank(array $candidates, array $signals, array $profile, array $genreRarity, int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));
        $personalized = count(array_filter($profile['genreWeights'], static fn (float $weight): bool => $weight > 0)) > 0;
        $owned = array_fill_keys(array_column($signals, 'animeId'), true);
        $positiveSeeds = $this->representativeSeeds($signals, true, 80);
        $negativeSeeds = $this->representativeSeeds($signals, false, 20);
        $strongestSeed = max([0.01, ...array_column($positiveSeeds, 'weight')]);
        $profileNorm = 0.0;
        foreach ($profile['genreWeights'] as $id => $weight) {
            $profileNorm += $weight ** 2 * ($genreRarity[$id] ?? 1.0);
        }
        $profileNorm = sqrt($profileNorm);
        $ranked = [];
        foreach ($candidates as $candidate) {
            $id = $candidate['animeId'];
            if (isset($owned[$id]) || isset($ranked[$id])) {
                continue;
            }
            $candidate = $this->prepare($candidate);
            $dot = 0.0;
            $norm = 0.0;
            $negativeGenre = false;
            foreach ($candidate['genreIds'] as $genreId) {
                $weight = $profile['genreWeights'][$genreId] ?? 0.0;
                $rarity = $genreRarity[$genreId] ?? 1.0;
                $dot += $weight * $rarity;
                $norm += $rarity;
                $negativeGenre = $negativeGenre || $weight < 0;
            }
            if (($personalized && $dot <= 0) || (!$personalized && $negativeGenre)) {
                continue;
            }
            $affinity = $profileNorm > 0 && $norm > 0 ? max(0.0, $dot / ($profileNorm * sqrt($norm))) : 0.0;
            $evidence = [];
            foreach ($positiveSeeds as $seed) {
                $shared = array_intersect($candidate['genreIds'], $seed['genreIds']);
                if (!array_filter($shared, static fn (int $genreId): bool => ($profile['genreWeights'][$genreId] ?? 0.0) > 0)) {
                    continue;
                }
                $similarity = $this->similarity($candidate, $seed, $genreRarity);
                $confidence = 0.7 + 0.3 * sqrt($seed['weight'] / $strongestSeed);
                $evidence[] = ['seed' => $seed, 'score' => $similarity * $confidence];
            }
            usort($evidence, static fn (array $first, array $second): int =>
                ($second['score'] <=> $first['score']) ?: ($second['seed']['weight'] <=> $first['seed']['weight'])
                ?: ($first['seed']['animeId'] <=> $second['seed']['animeId']));
            $negativeSimilarity = 0.0;
            foreach ($negativeSeeds as $seed) {
                $confidence = min(1.0, abs($seed['weight']) / $strongestSeed);
                $negativeSimilarity = max($negativeSimilarity, $confidence * $this->similarity($candidate, $seed, $genreRarity));
            }
            $quality = $candidate['score'] === null ? 0.12 : (max(0.0, min(10.0, $candidate['score'])) / 10) ** 2;
            $typeAffinity = max(0.0, $profile['typeWeights'][$this->normalizeType($candidate['type'])] ?? 0.0);
            $candidate['relevance'] = $personalized
                ? 0.42 * ($evidence[0]['score'] ?? 0.0) + 0.30 * $quality + 0.22 * $affinity
                    + 0.06 * $typeAffinity - 0.12 * $negativeSimilarity
                : $quality;
            $candidate['evidence'] = $evidence;
            // Imported announcements often have neither a known score nor a release date.
            $candidate['unverified'] = $candidate['score'] === null && ($candidate['year'] === null || !$candidate['episodes']);
            $ranked[$id] = $candidate;
        }

        $selected = [];
        $result = [];
        $usedSeeds = [];
        $usedFranchises = [];
        while ($ranked !== [] && count($result) < $limit) {
            $verified = array_filter($ranked, static fn (array $item): bool => !$item['unverified']);
            $eligible = $verified !== [] ? $verified : $ranked;
            $best = null;
            $bestObjective = -INF;
            $bestEvidence = null;
            foreach ($eligible as $item) {
                $evidence = $this->chooseEvidence($item['evidence'], $usedSeeds);
                $overlap = 0.0;
                foreach ($selected as $previous) {
                    $overlap = max($overlap, $this->similarity($item, $previous, $genreRarity));
                }
                $reuse = $evidence === null ? 0 : ($usedSeeds[$evidence['seed']['animeId']] ?? 0);
                $sameSeries = isset($usedFranchises[$item['franchise']]) ? 0.12 : 0.0;
                $objective = $item['relevance'] - ($personalized ? 0.20 * $overlap + 0.04 * min(2, $reuse) + $sameSeries : 0.0);
                if ($best === null || $objective > $bestObjective + 0.000000001
                    || (abs($objective - $bestObjective) <= 0.000000001 && $this->compare($item, $best) < 0)) {
                    $best = $item;
                    $bestObjective = $objective;
                    $bestEvidence = $evidence;
                }
            }
            $reason = null;
            $matchedGenres = [];
            if ($personalized && $bestEvidence !== null) {
                $seed = $bestEvidence['seed'];
                $matchedGenres = array_values(array_filter(
                    array_intersect($best['genreIds'], $seed['genreIds']),
                    static fn (int $id): bool => ($profile['genreWeights'][$id] ?? 0.0) > 0,
                ));
                $maximumWeight = max([0.01, ...array_values($profile['genreWeights'])]);
                $importance = static fn (int $id): float => ($genreRarity[$id] ?? 1.0)
                    * (0.5 + ($profile['genreWeights'][$id] ?? 0.0) / $maximumWeight);
                usort($matchedGenres, static fn (int $first, int $second): int =>
                    ($importance($second) <=> $importance($first)) ?: ($first <=> $second));
                $matchedGenres = array_slice($matchedGenres, 0, 2);
                $reason = [
                    'animeId' => $seed['animeId'], 'title' => $seed['title'], 'rating' => $seed['rating'],
                    'status' => $seed['status'], 'sharedGenreIds' => $matchedGenres,
                    'sharedStudio' => array_intersect($best['studioNames'], $seed['studioNames']) !== [],
                ];
                $usedSeeds[$seed['animeId']] = ($usedSeeds[$seed['animeId']] ?? 0) + 1;
            }
            $result[] = ['animeId' => $best['animeId'], 'matchedGenreIds' => $matchedGenres, 'reason' => $reason];
            $selected[] = $best;
            $usedFranchises[$best['franchise']] = true;
            unset($ranked[$best['animeId']]);
        }

        return $result;
    }

    /** Keep strong examples and at least one representative of each distinct interest. */
    private function representativeSeeds(array $signals, bool $positive, int $limit): array
    {
        $signals = array_values(array_filter($signals, static fn (array $signal): bool =>
            ($positive ? $signal['weight'] > 0 : $signal['weight'] < 0) && $signal['genreIds'] !== []));
        usort($signals, static fn (array $first, array $second): int =>
            (abs($second['weight']) <=> abs($first['weight'])) ?: ($first['animeId'] <=> $second['animeId']));
        $chosen = [];
        $covered = [];
        $signatures = [];
        foreach ([true, false] as $coveragePass) {
            foreach ($signals as $signal) {
                if (count($chosen) >= $limit) {
                    break 2;
                }
                if (isset($chosen[$signal['animeId']])) {
                    continue;
                }
                $genreIds = $signal['genreIds'];
                sort($genreIds);
                $signature = implode(',', $genreIds) . '|' . strtolower(trim($signal['studio'])) . '|' . $this->normalizeType($signal['type']);
                $uncovered = array_diff($genreIds, array_keys($covered)) !== [];
                if (($coveragePass && !$uncovered) || (!$coveragePass && isset($signatures[$signature]))) {
                    continue;
                }
                $chosen[$signal['animeId']] = $this->prepare($signal);
                $signatures[$signature] = true;
                $covered += array_fill_keys($genreIds, true);
            }
        }

        return array_values($chosen);
    }

    private function chooseEvidence(array $evidence, array $usedSeeds): ?array
    {
        $best = $evidence[0] ?? null;
        foreach ($evidence as $item) {
            if ($item['score'] < ($evidence[0]['score'] ?? 0) * 0.85) {
                break;
            }
            if (($usedSeeds[$item['seed']['animeId']] ?? 0) < ($usedSeeds[$best['seed']['animeId']] ?? 0)) {
                $best = $item;
            }
        }

        return $best;
    }

    private function prepare(array $item): array
    {
        $item['genreIds'] = array_values(array_unique($item['genreIds']));
        $item['genreSet'] = array_fill_keys($item['genreIds'], true);
        // Remove corporate suffixes before splitting: otherwise unrelated "Co., Ltd."
        // companies would share an artificial studio called "Ltd.".
        $studio = preg_replace('/\b(?:co(?:mpany)?\s*\.?\s*,?\s*(?:ltd|limited)\.?|inc(?:orporated)?\.?|ltd\.?|limited|corp(?:oration)?\.?|llc)\b\.?/iu', '', $item['studio']) ?? $item['studio'];
        $item['studioNames'] = array_values(array_filter(array_map(
            static fn (string $name): string => preg_replace('/[^\p{L}\p{N}]/u', '', strtolower(trim($name))) ?? '',
            preg_split('/[·,|]/u', $studio) ?: [],
        ), static fn (string $name): bool => !in_array($name, ['', 'unknown', 'na', 'none'], true)));
        $item['franchise'] = $this->franchise($item['title']);

        return $item;
    }

    /** Weighted Jaccard rewards specific shared motifs and counts unmatched motifs too. */
    private function similarity(array $first, array $second, array $rarity): float
    {
        $intersection = 0.0;
        $union = 0.0;
        foreach ($first['genreSet'] + $second['genreSet'] as $id => $_) {
            $weight = $rarity[$id] ?? 1.0;
            $union += $weight;
            if (isset($first['genreSet'][$id], $second['genreSet'][$id])) {
                $intersection += $weight;
            }
        }
        if ($intersection <= 0 || $union <= 0) {
            return 0.0;
        }
        $sameStudio = array_intersect($first['studioNames'], $second['studioNames']) !== [];
        $length = $first['episodes'] > 0 && $second['episodes'] > 0
            ? min($first['episodes'], $second['episodes']) / max($first['episodes'], $second['episodes']) : 0.0;

        return 0.85 * $intersection / $union + 0.10 * (int) $sameStudio + 0.05 * $length;
    }

    private function normalizeType(string $type): string
    {
        $type = strtoupper(trim($type));

        return $type === 'TV SPECIAL' ? 'SPECIAL' : $type;
    }

    /** Only recognizable season suffixes are removed; colons in names stay intact. */
    private function franchise(string $title): string
    {
        $title = strtolower(trim($title));
        $stem = preg_replace('/\s+(?:\d+(?:st|nd|rd|th)\s+season|season\s*\d+|\d+)(?:\s+part\s*\d+)?(?:\s*[:\-].*)?$/u', '', $title) ?? $title;

        return strlen($stem) >= 3 ? $stem : $title;
    }

    private function compare(array $first, array $second): int
    {
        return (($second['score'] ?? -1) <=> ($first['score'] ?? -1))
            ?: strnatcasecmp($first['title'], $second['title']) ?: ($first['animeId'] <=> $second['animeId']);
    }
}
