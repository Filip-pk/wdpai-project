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
    id BIGINT PRIMARY KEY, title TEXT NOT NULL, media_type TEXT NOT NULL DEFAULT 'UNKNOWN', score NUMERIC NULL
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
INSERT INTO pg_temp.users (id) VALUES (101), (102), (103), (104), (105);
INSERT INTO pg_temp.genres (id, name) VALUES (1, 'Action'), (2, 'Romance'), (3, 'Fantasy'), (4, 'Slice of Life');
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
        $assert($repository->signalsForUser(101) === [[
            'animeId' => 201, 'status' => 'completed', 'rating' => 10, 'genreIds' => [1], 'type' => 'TV',
        ]], 'Another account rating must not join the signal.');
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
        $result = $ids($repository->findCandidatesForUser(103, [], [], 12));
        $assert(array_intersect($result, [211, 212, 213, 214, 215]) === [], 'No own watchlist status is recommended again.');
        $assert(in_array(201, $result, true) && in_array(202, $result, true), 'Other users entries do not exclude a candidate.');
    });

    $run('genre overlap outranks imported score and format only refines matching candidates', static function () use ($repository, $ids, $assert): void {
        $result = $ids($repository->findCandidatesForUser(103, [1 => 1.0], ['TV' => 1.0], 12));
        $assert(array_search(211, $result, true) < array_search(212, $result, true), 'Preferred TV wins even with a lower source score.');
        $assert(array_search(212, $result, true) < array_search(218, $result, true), 'A matching genre beats a weak overlap despite the other preferred format.');
        $assert(!in_array(216, $result, true), 'Preferred TV alone cannot qualify unrelated genres.');
        $assert(!in_array(217, $result, true), 'Unknown genres do not fabricate a personalized match.');
    });

    $run('shared genres add up and negative tastes lower ranking without rewarding excessive tags', static function () use ($repository, $ids, $assert): void {
        $result = $ids($repository->findCandidatesForUser(103, [1 => 0.4, 2 => 0.35, 3 => -0.25], [], 12));
        $assert($result[0] === 214, 'Two strong preferred genres beat a single preference.');
        $assert(array_search(211, $result, true) < array_search(215, $result, true), 'A disliked genre reduces otherwise positive overlap.');
        $assert(array_search(211, $result, true) < array_search(218, $result, true), 'Many tags do not create an automatic advantage.');
        $assert(!in_array(216, $result, true), 'A purely negative genre is excluded.');
        $neutral = $ids($repository->findCandidatesForUser(103, [1 => 0.5, 3 => -0.5], [], 12));
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

    $run('formats are normalized consistently and exact ranking ties resolve by source score title and ID', static function () use ($repository, $connection, $ids, $assert): void {
        $connection->exec("UPDATE anime SET media_type = ' tv special ' WHERE id = 211");
        $result = $ids($repository->findCandidatesForUser(103, [1 => 1.0], ['SPECIAL' => 1.0], 12));
        $assert($result[0] === 211, 'TV SPECIAL is matched to SPECIAL after whitespace and case normalization.');
        $ties = $ids($repository->findCandidatesForUser(103, [1 => 1.0], [], 12));
        $assert(array_search(219, $ties, true) + 1 === array_search(220, $ties, true), 'Case-insensitive title ties resolve by ascending ID.');
        $assert(array_search(211, $ties, true) < array_search(221, $ties, true), 'Unknown source scores come after known scores in an otherwise equal ranking.');
        $assert($repository->findCandidatesForUser(103, [1 => 1.0], [], 12) === $repository->findCandidatesForUser(103, [1 => 1.0], [], 12), 'Repeated reads keep an unchanged deterministic order.');
    });

    $run('bounded result counts and an exhausted matching catalog produce safe empty results', static function () use ($repository, $service, $connection, $assert): void {
        $assert(count($repository->findCandidatesForUser(103, [], [], 0)) === 1, 'A zero limit is bounded to one.');
        $assert(count($repository->findCandidatesForUser(103, [], [], 1000000)) === 12, 'An excessive limit is bounded to twelve.');
        $connection->exec("INSERT INTO user_watchlist (user_id, anime_id, status)
            SELECT 101, id, 'planned' FROM anime WHERE id <> 201");
        $result = $service->recommendForUser(101, 12);
        $assert($result['entries'] === [], 'An account with the whole catalog on its list receives no duplicate suggestions.');
        $assert($repository->findCandidatesForUser(103, [999 => 1.0], [], 12) === [], 'Unavailable preferred genres give no fabricated matches.');
    });

    $run('invalid account IDs and malformed weight maps are rejected before SQL', static function () use ($repository, $assert): void {
        $invalid = [
            static fn () => $repository->signalsForUser(0),
            static fn () => $repository->findCandidatesForUser(-1, [], []),
            static fn () => $repository->findCandidatesForUser(103, [0 => 1.0], []),
            static fn () => $repository->findCandidatesForUser(103, ['1 OR 1=1' => 1.0], []),
            static fn () => $repository->findCandidatesForUser(103, [1 => INF], []),
            static fn () => $repository->findCandidatesForUser(103, [1 => NAN], []),
            static fn () => $repository->findCandidatesForUser(103, [1 => '1'], []),
            static fn () => $repository->findCandidatesForUser(103, [1 => 1.01], []),
            static fn () => $repository->findCandidatesForUser(103, [], ['' => 0.5]),
            static fn () => $repository->findCandidatesForUser(103, [], [str_repeat('X', 21) => 0.5]),
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
