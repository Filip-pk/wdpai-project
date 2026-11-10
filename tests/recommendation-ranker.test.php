<?php

declare(strict_types=1);

use App\Service\RecommendationRanker;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$ranker = new RecommendationRanker();
$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$anime = static fn (int $id, string $title, array $genres, ?float $score = 8.0, string $studio = 'Studio A'): array => [
    'animeId' => $id, 'title' => $title, 'genreIds' => $genres, 'score' => $score, 'studio' => $studio,
    'type' => 'TV', 'episodes' => 12, 'year' => 2020,
];
$seed = static fn (array $anime, float $weight = 6.0, ?int $rating = 10, string $status = 'completed'): array =>
    $anime + ['weight' => $weight, 'rating' => $rating, 'status' => $status];
$ids = static fn (array $entries): array => array_column($entries, 'animeId');
$profile = ['genreWeights' => [1 => 0.6, 2 => 0.4], 'typeWeights' => ['TV' => 1.0]];
$run = static function (string $name, Closure $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
};

$run('quality participates in ranking and an imported announcement cannot lead known alternatives', static function () use ($ranker, $anime, $seed, $profile, $ids, $assert): void {
    $signals = [$seed($anime(1, 'Liked adventure', [1, 2]))];
    $announcement = $anime(10, 'Unreleased announcement', [1, 2], null);
    $announcement['year'] = null;
    $announcement['episodes'] = null;
    $candidates = [$announcement, $anime(11, 'Weak exact metadata', [1, 2], 5.0), $anime(12, 'Good exact metadata', [1, 2], 9.0)];
    $result = $ids($ranker->rank($candidates, $signals, $profile, [], 3));
    $assert($result === [12, 11, 10], 'A source score must affect relevance, not only exact genre ties; announcements are last.');
});

$run('a well matched niche anime is eligible without a high quality cutoff', static function () use ($ranker, $anime, $seed, $profile, $ids, $assert): void {
    $result = $ranker->rank([$anime(11, 'Niche favourite', [1, 2], 6.72)], [$seed($anime(1, 'Liked niche', [1, 2]))], $profile, []);
    $assert($ids($result) === [11], 'The score is a prior, not a hard exclusion rule.');
    $assert($result[0]['reason']['animeId'] === 1, 'The explanation identifies its actual evidence.');
});

$run('a broad watchlist produces multiple interest groups rather than six action fantasy copies', static function () use ($ranker, $anime, $seed, $ids, $assert): void {
    $signals = [
        $seed($anime(1, 'Liked fantasy', [1, 2])),
        $seed($anime(2, 'Liked romance', [3, 4], 8.0, 'Studio B'), 5.0, 9),
        $seed($anime(3, 'Liked thriller', [5, 6], 8.0, 'Studio C'), 5.0, 9),
    ];
    $profile = ['genreWeights' => [1 => 0.24, 2 => 0.24, 3 => 0.15, 4 => 0.15, 5 => 0.11, 6 => 0.11], 'typeWeights' => ['TV' => 1.0]];
    $candidates = [];
    for ($index = 0; $index < 8; $index++) {
        $candidates[] = $anime(10 + $index, 'Action candidate ' . chr(65 + $index), [1, 2], 8.7);
    }
    $candidates[] = $anime(30, 'Romance alternative', [3, 4], 8.5, 'Studio B');
    $candidates[] = $anime(31, 'Thriller alternative', [5, 6], 8.5, 'Studio C');
    $result = $ranker->rank($candidates, $signals, $profile, [1 => 1.2, 2 => 1.5, 3 => 2.0, 4 => 2.5, 5 => 2.5, 6 => 3.0]);
    $assert(count($result) === 6 && count(array_unique($ids($result))) === 6, 'Six distinct results remain available.');
    $assert(in_array(30, $ids($result), true) && in_array(31, $ids($result), true), 'Other real interests survive the final selection.');
    $assert(count(array_unique(array_column(array_column($result, 'reason'), 'animeId'))) >= 3, 'Different recommendations use different evidence where justified.');
});

$run('weighted overlap rewards specific motifs and explains only actual positive shared genres', static function () use ($ranker, $anime, $seed, $assert): void {
    $signals = [$seed($anime(1, 'Time travel mystery', [1, 3, 4], 8.0, 'Studio A'))];
    $profile = ['genreWeights' => [1 => 0.3, 2 => -0.1, 3 => 0.3, 4 => 0.3], 'typeWeights' => ['TV' => 1.0]];
    $candidates = [$anime(10, 'Generic action', [1], 8.0, 'Studio B'), $anime(11, 'Specific mystery', [1, 3, 4], 8.0, 'Studio A')];
    $result = $ranker->rank($candidates, $signals, $profile, [1 => 1.0, 3 => 3.0, 4 => 4.0], 2);
    $assert($result[0]['animeId'] === 11 && $result[0]['matchedGenreIds'] === [4, 3], 'Rarer shared motifs are shown before generic action.');
    $assert($result[0]['reason']['sharedGenreIds'] === [4, 3] && $result[0]['reason']['sharedStudio'], 'Every reason is traceable to the selected candidate and seed.');
});

$run('negative personal evidence lowers mixed matches and never becomes a positive explanation', static function () use ($ranker, $anime, $seed, $ids, $assert): void {
    $signals = [$seed($anime(1, 'Liked action', [1])), $seed($anime(2, 'Dropped horror', [2]), -2.0, 3, 'dropped')];
    $profile = ['genreWeights' => [1 => 0.8, 2 => -0.2], 'typeWeights' => ['TV' => 1.0]];
    $candidates = [$anime(10, 'Good action', [1]), $anime(11, 'Mixed horror', [1, 2]), $anime(12, 'Only horror', [2], 10.0)];
    $result = $ranker->rank($candidates, $signals, $profile, [], 3);
    $assert($ids($result) === [10, 11], 'Unwanted genres lower relevance and a wholly negative match is excluded.');
    foreach ($result as $entry) {
        $assert($entry['reason']['animeId'] === 1 && $entry['matchedGenreIds'] === [1], 'A dropped title does not justify a recommendation.');
    }
});

$run('franchise duplicates wait while equally relevant independent titles exist', static function () use ($ranker, $anime, $seed, $profile, $ids, $assert): void {
    $candidates = [
        $anime(10, 'Example', [1, 2], 9.0), $anime(11, 'Example 2nd Season', [1, 2], 8.9),
        $anime(12, 'Example Season 3', [1, 2], 8.8), $anime(13, 'Different adventure', [1, 2], 8.7),
    ];
    $result = $ids($ranker->rank($candidates, [$seed($anime(1, 'Liked story', [1, 2]))], $profile, [], 3));
    $assert($result[0] === 10 && $result[1] === 13 && count($result) === 3, 'A franchise cannot consume the list before other relevant titles, but sparse lists still fill.');
    $colon = [$anime(20, 'Re:Zero', [1, 2]), $anime(21, 'Re:Creators', [1, 2])];
    $assert(count($ranker->rank($colon, [$seed($anime(1, 'Liked story', [1, 2]))], $profile, [], 2)) === 2, 'Colons alone do not imply a shared franchise.');
});

$run('own entries duplicates empty metadata and bounded counts are handled without fabricated reasons', static function () use ($ranker, $anime, $seed, $profile, $ids, $assert): void {
    $signals = [$seed($anime(1, 'Own title', [1]))];
    $candidates = [$anime(1, 'Own title', [1]), $anime(10, 'New title', [1]), $anime(10, 'Duplicate row', [1]), $anime(11, 'Unknown genres', [])];
    $result = $ranker->rank($candidates, $signals, $profile, [], 100);
    $assert($ids($result) === [10], 'Owned IDs, duplicate rows and no-genre personalized candidates are excluded.');
    $assert($ranker->rank([], $signals, $profile, []) === [], 'Empty catalog returns an empty list.');
    $assert(count($ranker->rank($candidates, $signals, $profile, [], 0)) === 1, 'A zero limit is bounded to one.');
    $starter = $ranker->rank([$anime(20, 'Starter', [])], [], ['genreWeights' => [], 'typeWeights' => []], []);
    $assert($starter === [['animeId' => 20, 'matchedGenreIds' => [], 'reason' => null]], 'Starter suggestions do not invent personal evidence.');
});

$run('equally qualified evidence may vary while weaker unrelated examples are never used as decoration', static function () use ($ranker, $anime, $seed, $profile, $assert): void {
    $signals = [
        $seed($anime(1, 'Strong first', [1, 2], 8.0, 'Studio A')),
        $seed($anime(2, 'Strong second', [1, 2], 8.0, 'Studio B')),
        $seed($anime(3, 'Weak unrelated', [4], 8.0, 'Studio C')),
    ];
    $candidates = [$anime(10, 'First suggestion', [1, 2], 8.0, ''), $anime(11, 'Second suggestion', [1, 2], 8.0, '')];
    $result = $ranker->rank($candidates, $signals, $profile, [], 2);
    $assert(array_column(array_column($result, 'reason'), 'animeId') === [1, 2], 'Near-best real evidence is reused less while unrelated seeds are ignored.');
    $assert($ranker->rank(array_reverse($candidates), $signals, $profile, [], 2) === $result, 'Input order does not randomize ties.');
});

$run('representative seeds preserve a niche interest in a large watchlist', static function () use ($ranker, $anime, $seed, $ids, $assert): void {
    $signals = [];
    for ($index = 1; $index <= 400; $index++) {
        $signals[] = $seed($anime($index, 'Common liked ' . $index, [1, 2]), 6.0);
    }
    $signals[] = $seed($anime(500, 'Niche liked', [3, 4], 8.0, 'Studio B'), 4.0, 8);
    $profile = ['genreWeights' => [1 => 0.4, 2 => 0.4, 3 => 0.1, 4 => 0.1], 'typeWeights' => ['TV' => 1.0]];
    $result = $ranker->rank([$anime(1000, 'Niche candidate', [3, 4], 8.5, 'Studio B')], $signals, $profile, [3 => 4.0, 4 => 4.0]);
    $assert($ids($result) === [1000] && $result[0]['reason']['animeId'] === 500, 'Representative sampling must cover the niche as well as hundreds of repeated broad examples.');
});

$run('corporate studio suffixes never fabricate a shared studio while real punctuation aliases match', static function () use ($ranker, $anime, $seed, $profile, $assert): void {
    $signals = [$seed($anime(1, 'Liked pirate story', [1, 2], 8.0, 'Toei Animation Co., Ltd.'))];
    $result = $ranker->rank([$anime(10, 'Unrelated studio', [1, 2], 8.0, 'J.C.Staff Co., Ltd.')], $signals, $profile, []);
    $assert(!$result[0]['reason']['sharedStudio'], 'The corporate Ltd. fragment is not a production studio.');
    $aliases = [$seed($anime(1, 'Liked studio story', [1, 2], 8.0, 'J.C.Staff Co., Ltd.'))];
    foreach (['JCStaff', 'J. C. Staff, Inc.', 'Other Studio · JCStaff Corporation'] as $studio) {
        $result = $ranker->rank([$anime(10, 'Same real studio', [1, 2], 8.0, $studio)], $aliases, $profile, []);
        $assert($result[0]['reason']['sharedStudio'], 'True studio names match through punctuation and corporate suffixes: ' . $studio);
    }
});

$run('diversity does not force a poor independent title ahead of a strongly matching sequel', static function () use ($ranker, $anime, $seed, $profile, $ids, $assert): void {
    $candidates = [
        $anime(10, 'Excellent adventure', [1, 2], 9.5), $anime(11, 'Excellent adventure Season 2', [1, 2], 9.0),
        $anime(12, 'Poor alternative', [1, 4, 5, 6], 1.0, 'Different studio'),
    ];
    $result = $ids($ranker->rank($candidates, [$seed($anime(1, 'Liked story', [1, 2]))], $profile, [], 2));
    $assert($result === [10, 11], 'A bounded diversity penalty must not become a ban on better sequels.');
});

$run('weak negative evidence has less force than an explicitly rejected close match', static function () use ($ranker, $anime, $seed, $ids, $assert): void {
    $positive = $seed($anime(1, 'Liked action', [1]), 6.0);
    $weakNegative = $seed($anime(2, 'Below usual rating', [1, 2]), -0.1, 6);
    $strongNegative = $seed($anime(2, 'Rejected story', [1, 2]), -6.0, 1, 'dropped');
    $profile = ['genreWeights' => [1 => 0.99, 2 => -0.01], 'typeWeights' => ['TV' => 1.0]];
    $candidates = [$anime(10, 'Higher quality but close', [1, 2], 8.8), $anime(11, 'Slightly different', [1, 3], 8.5)];
    $weak = $ids($ranker->rank($candidates, [$positive, $weakNegative], $profile, [], 1));
    $strong = $ids($ranker->rank($candidates, [$positive, $strongNegative], $profile, [], 1));
    $assert($weak === [10] && $strong === [11], 'The strength of dislike, not merely its existence, determines the penalty.');
});

echo $passed . ' passed, ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
