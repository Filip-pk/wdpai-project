<?php

declare(strict_types=1);

use App\Database;
use App\Repository\RecommendationRepository;
use App\Service\RecommendationService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $connection->exec(<<<'SQL'
CREATE TEMP TABLE users (id BIGINT PRIMARY KEY) ON COMMIT DROP;
CREATE TEMP TABLE anime (
    id BIGINT PRIMARY KEY, title TEXT NOT NULL, media_type TEXT NOT NULL DEFAULT 'UNKNOWN', score NUMERIC NULL,
    studio TEXT NOT NULL DEFAULT '', episodes INTEGER NULL DEFAULT 12, release_year SMALLINT NULL DEFAULT 2020
) ON COMMIT DROP;
CREATE TEMP TABLE genres (id BIGINT PRIMARY KEY, name TEXT NOT NULL) ON COMMIT DROP;
CREATE TEMP TABLE anime_genres (
    anime_id BIGINT NOT NULL REFERENCES pg_temp.anime(id) ON DELETE CASCADE,
    genre_id BIGINT NOT NULL REFERENCES pg_temp.genres(id) ON DELETE CASCADE,
    PRIMARY KEY (anime_id, genre_id)
) ON COMMIT DROP;
CREATE TEMP TABLE user_watchlist (
    user_id BIGINT NOT NULL REFERENCES pg_temp.users(id) ON DELETE CASCADE,
    anime_id BIGINT NOT NULL REFERENCES pg_temp.anime(id) ON DELETE CASCADE,
    status TEXT NOT NULL,
    PRIMARY KEY (user_id, anime_id)
) ON COMMIT DROP;
CREATE TEMP TABLE user_anime_ratings (
    user_id BIGINT NOT NULL REFERENCES pg_temp.users(id) ON DELETE CASCADE,
    anime_id BIGINT NOT NULL REFERENCES pg_temp.anime(id) ON DELETE CASCADE,
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 10),
    PRIMARY KEY (user_id, anime_id)
) ON COMMIT DROP;
SQL);
    foreach (['users', 'anime', 'genres', 'anime_genres', 'user_watchlist', 'user_anime_ratings'] as $table) {
        $statement = $connection->prepare("SELECT relnamespace = pg_my_temp_schema() AND relpersistence = 't' FROM pg_class WHERE oid = to_regclass(:table)");
        $statement->execute(['table' => $table]);
        $assert(in_array($statement->fetchColumn(), [true, 1, '1', 't'], true), 'Fixture writes require session-local tables.');
    }
    $connection->exec(<<<'SQL'
INSERT INTO pg_temp.users (id) VALUES (101), (102), (103), (104), (105), (106);
INSERT INTO pg_temp.genres (id, name) VALUES (1, 'Action'), (2, 'Romance'), (3, 'Fantasy'), (4, 'Slice of Life'),
    (5, 'Suspense'), (6, 'Mystery');
INSERT INTO pg_temp.anime (id, title, media_type, score) VALUES
    (201, 'Action seed', 'TV', 7), (202, 'Romance seed', 'MOVIE', 7),
    (203, 'No metadata seed', 'UNKNOWN', NULL), (204, 'Dropped fantasy seed', 'TV', 9),
    (211, 'Low source action', 'TV', 5), (212, 'High source action movie', 'MOVIE', 10),
    (213, 'Romance candidate', 'MOVIE', 9), (214, 'Action romance', 'TV', 10),
    (215, 'Action fantasy', 'TV', 10), (216, 'Fantasy candidate', 'TV', 10),
    (217, 'No metadata candidate', 'UNKNOWN', 9.5), (218, 'Many genres', 'TV', 10),
    (219, 'Same', 'TV', 8), (220, 'same', 'TV', 8), (221, 'Unrated action', 'TV', NULL);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id) VALUES
    (201, 1), (202, 2), (204, 3), (211, 1), (212, 1), (213, 2),
    (214, 1), (214, 2), (215, 1), (215, 3), (216, 3),
    (218, 1), (218, 2), (218, 3), (218, 4), (219, 1), (220, 1), (221, 1);
INSERT INTO pg_temp.user_watchlist (user_id, anime_id, status) VALUES
    (101, 201, 'completed'), (102, 202, 'watching'),
    (104, 203, 'planned'), (105, 204, 'dropped');
INSERT INTO pg_temp.user_anime_ratings (user_id, anime_id, rating) VALUES
    (101, 201, 10), (102, 201, 1), (102, 202, 8);
SQL);
    $repository = new RecommendationRepository($connection);
    $service = new RecommendationService($repository);
    $ids = static fn (array $entries): array => array_column($entries, 'animeId');
    $run = static function (string $name, Closure $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT recommendation_test');
        try {
            $test();
            $passed++;
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT recommendation_test');
            $connection->exec('RELEASE SAVEPOINT recommendation_test');
        }
    };

    $run('watchlist signals include only the account own rating and metadata', static function () use ($repository, $assert): void {
        $signals = $repository->signalsForUser(101);
        $assert(count($signals) === 1, 'Only the own watchlist contributes signals.');
        $assert($signals[0]['animeId'] === 201 && $signals[0]['rating'] === 10 && $signals[0]['status'] === 'completed', 'Another account rating must not join the signal.');
        $assert($signals[0]['genreIds'] === [1] && $signals[0]['type'] === 'TV' && $signals[0]['title'] === 'Action seed', 'Signals retain anime identity and genres for truthful explanations.');
        $assert($signals[0]['studio'] === '' && $signals[0]['episodes'] === 12 && $signals[0]['year'] === 2020, 'Similarity metadata is typed consistently.');
        $assert($repository->signalsForUser(103) === [], 'An empty account has no inferred signals.');
        $assert($repository->signalsForUser(104)[0]['genreIds'] === [], 'Unknown genre metadata is preserved as empty.');
    });

    $run('two users receive different genre-based results and foreign private data cannot change the profile', static function () use ($service, $connection, $ids, $assert): void {
        $first = $service->recommendForUser(101, 12);
        $second = $service->recommendForUser(102, 12);
        $assert($first['mode'] === 'personalized' && $first['preferredGenreIds'] === [1] && $first['sourceCount'] === 1, 'Action preference comes from one own entry.');
        $assert($second['preferredGenreIds'] === [2], 'Another account prefers romance.');
        $assert(in_array(211, $ids($first['entries']), true) && !in_array(213, $ids($first['entries']), true), 'Only action-overlapping anime qualify for the first account.');
        $assert(in_array(213, $ids($second['entries']), true) && !in_array(211, $ids($second['entries']), true), 'Only romance-overlapping anime qualify for the second account.');
        $connection->exec("INSERT INTO user_watchlist (user_id, anime_id, status) VALUES (102, 216, 'completed')");
        $connection->exec('UPDATE user_anime_ratings SET rating = 10 WHERE user_id = 102 AND anime_id = 201');
        $assert($service->recommendForUser(101, 12) === $first, 'Foreign watchlists and private ratings do not change an existing account result.');
    });

    $run('all own watchlist statuses are excluded while another user membership remains eligible', static function () use ($repository, $connection, $ids, $assert): void {
        $connection->exec("INSERT INTO user_watchlist (user_id, anime_id, status) VALUES
            (103, 211, 'watching'), (103, 212, 'completed'), (103, 213, 'planned'),
            (103, 214, 'on_hold'), (103, 215, 'dropped')");
        $result = $ids($repository->candidatePoolForUser(103, [], 300));
        $assert(array_intersect($result, [211, 212, 213, 214, 215]) === [], 'No own watchlist status is recommended again.');
        $assert(in_array(201, $result, true) && in_array(202, $result, true), 'Other users entries do not exclude a candidate.');
    });

    $run('candidate pools preserve useful metadata and require positive genre evidence', static function () use ($repository, $ids, $assert): void {
        $pool = $repository->candidatePoolForUser(103, [1 => 1.0], 300);
        $result = $ids($pool);
        $assert(in_array(211, $result, true) && in_array(212, $result, true), 'Source quality influences ranking without a hard cutoff that hides matching lower-rated anime.');
        $assert(!in_array(216, $result, true), 'Preferred TV alone cannot qualify unrelated genres.');
        $assert(!in_array(217, $result, true), 'Unknown genres do not fabricate a personalized match.');
        $candidate = $pool[array_search(212, $result, true)];
        $assert($candidate['score'] === 10.0 && $candidate['genreIds'] === [1] && $candidate['type'] === 'MOVIE', 'Candidate scores and genres remain available to the final ranker.');
        $assert($candidate['title'] === 'High source action movie' && $candidate['episodes'] === 12 && $candidate['year'] === 2020, 'The pool exposes bounded metadata rather than complete descriptions.');
    });

    $run('negative and neutral overlap do not become personalized candidates', static function () use ($repository, $ids, $assert): void {
        $result = $ids($repository->candidatePoolForUser(103, [1 => 0.4, 2 => 0.35, 3 => -0.25], 300));
        $assert(in_array(214, $result, true) && in_array(211, $result, true), 'Several tastes remain eligible for the final diverse selection.');
        $assert(!in_array(216, $result, true), 'A purely negative genre is excluded.');
        $neutral = $ids($repository->candidatePoolForUser(103, [1 => 0.5, 3 => -0.5], 300));
        $assert(!in_array(215, $neutral, true), 'Zero net overlap is not described as a match.');
    });

    $run('starter mode handles empty and metadata-free watchlists without inventing preferences', static function () use ($service, $ids, $assert): void {
        $empty = $service->recommendForUser(103, 12);
        $unknown = $service->recommendForUser(104, 12);
        $assert($empty['mode'] === 'starter' && $empty['sourceCount'] === 0 && $empty['preferredGenreIds'] === [], 'Empty accounts get labeled starter suggestions.');
        $assert($unknown['mode'] === 'starter' && $unknown['sourceCount'] === 1 && $unknown['preferredGenreIds'] === [], 'A format alone is insufficient for genre personalization.');
        $assert(!in_array(203, $ids($unknown['entries']), true), 'Metadata-free own entries remain excluded.');
        $assert($empty['entries'][0]['matchedGenreIds'] === [], 'Starter cards do not claim matched preferences.');
    });

    $run('negative-only starter profile avoids rejected genres even when their source scores are high', static function () use ($service, $ids, $assert): void {
        $result = $service->recommendForUser(105, 12);
        $assert($result['mode'] === 'starter' && $result['preferredGenreIds'] === [], 'Dropped-only watchlists provide no positive tastes.');
        $assert(array_intersect($ids($result['entries']), [204, 215, 216, 218]) === [], 'Every candidate containing the negative genre is excluded in starter mode.');
    });

    $run('ratings status changes and removals affect the next calculation without stale state', static function () use ($service, $connection, $ids, $assert): void {
        $assert($service->recommendForUser(101)['mode'] === 'personalized', 'Baseline has a positive profile.');
        $connection->exec('UPDATE user_anime_ratings SET rating = 1 WHERE user_id = 101 AND anime_id = 201');
        $low = $service->recommendForUser(101, 12);
        $assert($low['mode'] === 'starter' && !in_array(211, $ids($low['entries']), true), 'A low own rating becomes a negative taste.');
        $connection->exec('UPDATE user_anime_ratings SET rating = 10 WHERE user_id = 101 AND anime_id = 201');
        $connection->exec("UPDATE user_watchlist SET status = 'dropped' WHERE user_id = 101 AND anime_id = 201");
        $assert($service->recommendForUser(101)['mode'] === 'starter', 'Dropped remains negative despite a previously high rating.');
        $connection->exec('DELETE FROM user_watchlist WHERE user_id = 101 AND anime_id = 201');
        $removed = $service->recommendForUser(101, 12);
        $assert($removed['sourceCount'] === 0 && $removed['mode'] === 'starter', 'Removing a watchlist entry also removes its preference signal.');
        $assert(in_array(201, $ids($removed['entries']), true), 'A removed entry can become a starter candidate again.');
    });

    $run('format metadata is preserved and pool reads are deterministic', static function () use ($repository, $connection, $ids, $assert): void {
        $connection->exec("UPDATE anime SET media_type = ' tv special ' WHERE id = 211");
        $pool = $repository->candidatePoolForUser(103, [1 => 1.0], 300);
        $ties = $ids($pool);
        $assert($pool[array_search(211, $ties, true)]['type'] === ' tv special ', 'The ranker receives source metadata for consistent format normalization.');
        $assert(array_search(219, $ties, true) + 1 === array_search(220, $ties, true), 'Case-insensitive title ties resolve by ascending ID.');
        $assert(array_search(211, $ties, true) < array_search(221, $ties, true), 'Unknown source scores come after known scores in an otherwise equal ranking.');
        $assert($pool === $repository->candidatePoolForUser(103, [1 => 1.0], 300), 'Repeated reads keep an unchanged deterministic order.');
    });

    $run('bounded result counts and an exhausted matching catalog produce safe empty results', static function () use ($repository, $service, $connection, $assert): void {
        $assert(count($repository->candidatePoolForUser(103, [], 0)) === 1, 'A zero pool limit is bounded to one.');
        $connection->exec("INSERT INTO anime (id, title, score) SELECT 3000 + number, 'Bounded pool item ' || number, 7 FROM generate_series(1, 650) AS fixture(number)");
        $assert(count($repository->candidatePoolForUser(103, [], 1000000)) === 600, 'Candidate retrieval is bounded even before the visible result limit.');
        $assert(count($service->recommendForUser(103, 1000000)['entries']) <= 12, 'The final visible limit remains bounded to twelve.');
        $connection->exec("INSERT INTO user_watchlist (user_id, anime_id, status)
            SELECT 101, id, 'planned' FROM anime WHERE id <> 201");
        $result = $service->recommendForUser(101, 12);
        $assert($result['entries'] === [], 'An account with the whole catalog on its list receives no duplicate suggestions.');
        $assert($repository->candidatePoolForUser(103, [999 => 1.0], 300) === [], 'Unavailable preferred genres give no fabricated matches.');
    });

    $run('catalog statistics describe anime frequencies rather than users private activity', static function () use ($repository, $connection, $assert): void {
        $statistics = $repository->catalogStatistics();
        $assert($statistics['total'] === 15 && $statistics['genreCounts'] === [1 => 9, 2 => 4, 3 => 4, 4 => 1], 'Each anime contributes once to its metadata frequencies.');
        $connection->exec("INSERT INTO user_watchlist (user_id, anime_id, status) VALUES (103, 218, 'completed')");
        $connection->exec('INSERT INTO user_anime_ratings (user_id, anime_id, rating) VALUES (103, 218, 10)');
        $assert($repository->catalogStatistics() === $statistics, 'Watchlist sizes and individual ratings are not global preference evidence.');
    });

    $run('retrieval retains a minority taste even when the overall leaders all share the dominant genre', static function () use ($repository, $connection, $ids, $assert): void {
        $connection->exec(<<<'SQL'
INSERT INTO pg_temp.anime (id, title, media_type, score)
SELECT 5000 + number, 'Dominant action pool item ' || number, 'TV', 10
FROM generate_series(1, 200) AS fixture(number);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id)
SELECT 5000 + number, 1 FROM generate_series(1, 200) AS fixture(number);
INSERT INTO pg_temp.anime (id, title, media_type, score) VALUES (6001, 'Minority romance candidate', 'TV', 8.5);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id) VALUES (6001, 2);
SQL);
        $pool = $ids($repository->candidatePoolForUser(103, [1 => 0.99, 2 => 0.01]));
        $assert(count($pool) <= 300 && count($pool) === count(array_unique($pool)), 'Overall and per-genre retrieval stay bounded without duplicate anime.');
        $assert(in_array(6001, $pool, true), 'A weak but genuine preference gets candidate recall before diversity selection.');
    });

    $run('a four hundred title history yields quality diverse matches with real individual explanations', static function () use ($repository, $service, $connection, $assert): void {
        $connection->exec(<<<'SQL'
INSERT INTO pg_temp.anime (id, title, media_type, score, studio, episodes, release_year)
SELECT 1000 + number, 'History title ' || number, 'TV', 8.5,
       CASE WHEN number <= 300 THEN 'Action Studio' WHEN number <= 365 THEN 'Romance Studio' ELSE 'Mystery Studio' END,
       12, 2020
FROM generate_series(1, 400) AS fixture(number);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id)
SELECT 1000 + number, genre_id
FROM generate_series(1, 400) AS fixture(number)
CROSS JOIN LATERAL unnest(CASE WHEN number <= 300 THEN ARRAY[1, 3]
    WHEN number <= 365 THEN ARRAY[2, 4] ELSE ARRAY[5, 6] END) AS genre_id;
INSERT INTO pg_temp.user_watchlist (user_id, anime_id, status)
SELECT 106, 1000 + number, 'completed' FROM generate_series(1, 400) AS fixture(number);
INSERT INTO pg_temp.user_anime_ratings (user_id, anime_id, rating)
SELECT 106, 1000 + number, CASE WHEN number <= 150 THEN 9 ELSE 10 END
FROM generate_series(1, 400) AS fixture(number);
INSERT INTO pg_temp.anime (id, title, media_type, score, studio, episodes, release_year)
SELECT 2000 + number, 'Strong action pick ' || CHR(64 + number), 'TV', 8.8 + number * 0.02, 'Action Studio', 12, 2021
FROM generate_series(1, 10) AS fixture(number);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id)
SELECT 2000 + number, genre_id FROM generate_series(1, 10) AS fixture(number) CROSS JOIN unnest(ARRAY[1, 3]) AS genre_id;
INSERT INTO pg_temp.anime (id, title, media_type, score, studio, episodes, release_year)
SELECT 2100 + number, 'Strong romance pick ' || CHR(64 + number), 'TV', 9, 'Romance Studio', 12, 2021
FROM generate_series(1, 6) AS fixture(number);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id)
SELECT 2100 + number, genre_id FROM generate_series(1, 6) AS fixture(number) CROSS JOIN unnest(ARRAY[2, 4]) AS genre_id;
INSERT INTO pg_temp.anime (id, title, media_type, score, studio, episodes, release_year)
SELECT 2200 + number, 'Strong mystery pick ' || CHR(64 + number), 'TV', 9, 'Mystery Studio', 12, 2021
FROM generate_series(1, 6) AS fixture(number);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id)
SELECT 2200 + number, genre_id FROM generate_series(1, 6) AS fixture(number) CROSS JOIN unnest(ARRAY[5, 6]) AS genre_id;
INSERT INTO pg_temp.anime (id, title, media_type, score, studio, episodes, release_year)
SELECT 2300 + number, 'Unscored announcement ' || number, 'TV', NULL, 'Action Studio', NULL, NULL
FROM generate_series(1, 6) AS fixture(number);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id)
SELECT 2300 + number, genre_id FROM generate_series(1, 6) AS fixture(number) CROSS JOIN unnest(ARRAY[1, 3]) AS genre_id;
INSERT INTO pg_temp.anime (id, title, media_type, score, studio, episodes, release_year)
SELECT 2400 + number, 'Weak generic action ' || number, 'TV', 6.3, 'Action Studio', 12, 2021
FROM generate_series(1, 6) AS fixture(number);
INSERT INTO pg_temp.anime_genres (anime_id, genre_id)
SELECT 2400 + number, genre_id FROM generate_series(1, 6) AS fixture(number) CROSS JOIN unnest(ARRAY[1, 3]) AS genre_id;
SQL);
        $result = $service->recommendForUser(106);
        $assert($result['mode'] === 'personalized' && $result['sourceCount'] === 400, 'The complete large history builds the user profile.');
        $assert(count($result['entries']) === 6, 'The dashboard still shows six useful suggestions.');
        $metadata = [];
        foreach ($repository->candidatePoolForUser(106, $service->buildProfile($repository->signalsForUser(106))['genreWeights']) as $candidate) {
            $metadata[$candidate['animeId']] = $candidate;
        }
        $profiles = [];
        $sourceIds = [];
        foreach ($result['entries'] as $entry) {
            $candidate = $metadata[$entry['animeId']] ?? null;
            $assert($candidate !== null && $candidate['score'] !== null && $candidate['score'] >= 8, 'Unknown announcements and weak generic matches cannot displace strong rated alternatives.');
            $genres = $candidate['genreIds'];
            sort($genres);
            $profiles[implode(',', $genres)] = true;
            $reason = $entry['reason'];
            $assert($reason !== null && $reason['animeId'] >= 1001 && $reason['animeId'] <= 1400, 'Every explanation cites an actual anime from this account history.');
            $assert($reason['title'] === 'History title ' . ($reason['animeId'] - 1000) && $reason['rating'] >= 9, 'The cited title and private rating correspond to their real source.');
            $assert($reason['status'] === 'completed' && $reason['sharedGenreIds'] !== [], 'The explanation is supported by shared metadata and viewing status.');
            $assert(array_diff($reason['sharedGenreIds'], $candidate['genreIds']) === [], 'Reasons never invent a shared candidate genre.');
            $sourceIds[$reason['animeId']] = true;
        }
        $assert(count($profiles) >= 3, 'Six slots cover at least three supported sides of the user tastes instead of repeating Action and Fantasy.');
        $assert(count($sourceIds) >= 3, 'Individual explanations reference more than one repeatedly reused history title.');
        $assert($service->recommendForUser(106) === $result, 'Diversity is deterministic rather than random rotation.');
    });

    $run('invalid account IDs and malformed weight maps are rejected before SQL', static function () use ($repository, $assert): void {
        $invalid = [
            static fn () => $repository->signalsForUser(0),
            static fn () => $repository->candidatePoolForUser(-1, []),
            static fn () => $repository->candidatePoolForUser(103, [0 => 1.0]),
            static fn () => $repository->candidatePoolForUser(103, ['1 OR 1=1' => 1.0]),
            static fn () => $repository->candidatePoolForUser(103, [1 => INF]),
            static fn () => $repository->candidatePoolForUser(103, [1 => NAN]),
            static fn () => $repository->candidatePoolForUser(103, [1 => '1']),
            static fn () => $repository->candidatePoolForUser(103, [1 => 1.01]),
        ];
        foreach ($invalid as $attempt) {
            $rejected = false;
            try {
                $attempt();
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $assert($rejected, 'Malformed query input must be rejected.');
        }
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, 'FAIL setup: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo $passed . ' passed, ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
