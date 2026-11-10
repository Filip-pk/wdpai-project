<?php

declare(strict_types=1);

use App\Database;
use App\Model\AnimeRankingCriteria;
use App\Repository\AnimeRankingRepository;

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
CREATE TEMP TABLE anime (id BIGINT PRIMARY KEY, title TEXT NOT NULL, release_year SMALLINT NULL, score NUMERIC NULL) ON COMMIT DROP;
CREATE TEMP TABLE genres (id BIGINT PRIMARY KEY, name TEXT NOT NULL) ON COMMIT DROP;
CREATE TEMP TABLE anime_genres (
    anime_id BIGINT NOT NULL REFERENCES pg_temp.anime(id) ON DELETE CASCADE,
    genre_id BIGINT NOT NULL REFERENCES pg_temp.genres(id) ON DELETE CASCADE,
    PRIMARY KEY (anime_id, genre_id)
) ON COMMIT DROP;
CREATE TEMP TABLE user_anime_ratings (
    user_id BIGINT NOT NULL REFERENCES pg_temp.users(id) ON DELETE CASCADE,
    anime_id BIGINT NOT NULL REFERENCES pg_temp.anime(id) ON DELETE CASCADE,
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 10),
    PRIMARY KEY (user_id, anime_id)
) ON COMMIT DROP;
CREATE TEMP TABLE user_watchlist (
    user_id BIGINT NOT NULL REFERENCES pg_temp.users(id) ON DELETE CASCADE,
    anime_id BIGINT NOT NULL REFERENCES pg_temp.anime(id) ON DELETE CASCADE,
    status TEXT NOT NULL,
    PRIMARY KEY (user_id, anime_id)
) ON COMMIT DROP;
SQL);
    foreach (['users', 'anime', 'genres', 'anime_genres', 'user_anime_ratings', 'user_watchlist'] as $table) {
        $statement = $connection->prepare("SELECT relnamespace = pg_my_temp_schema() AND relpersistence = 't' FROM pg_class WHERE oid = to_regclass(:table)");
        $statement->execute(['table' => $table]);
        $assert(in_array($statement->fetchColumn(), [true, 1, '1', 't'], true), 'Fixture writes require session-local tables.');
    }
    $connection->exec(<<<'SQL'
INSERT INTO pg_temp.users (id) SELECT generate_series(101, 500);
INSERT INTO pg_temp.anime (id, title, release_year, score) VALUES
    (201, 'Alpha', 2020, 1), (202, 'Beta', 2020, 10), (203, 'Gamma', 2021, 10),
    (204, 'Delta', NULL, 9), (205, 'Unrated popular', 2020, 10),
    (206, 'Planned only', 2020, 10), (207, 'Unused', 2022, 10),
    (208, 'Same', 2020, 1), (209, 'same', 2020, 10);
INSERT INTO pg_temp.genres (id, name) VALUES (1, 'Action'), (2, 'Fantasy');
INSERT INTO pg_temp.anime_genres (anime_id, genre_id) VALUES
    (201, 1), (202, 1), (202, 2), (203, 2), (204, 2), (205, 1), (208, 1), (209, 1);
INSERT INTO pg_temp.user_anime_ratings (user_id, anime_id, rating) VALUES
    (101, 201, 10), (102, 201, 8),
    (101, 202, 9), (102, 202, 9), (103, 202, 9),
    (101, 203, 10), (101, 204, 7), (102, 204, 7), (103, 204, 7), (104, 204, 7), (105, 204, 7),
    (101, 208, 8), (101, 209, 8);
INSERT INTO pg_temp.user_watchlist (user_id, anime_id, status) VALUES
    (101, 201, 'watching'), (102, 201, 'completed'), (103, 201, 'planned'), (104, 201, 'on_hold'), (105, 201, 'dropped'),
    (101, 202, 'watching'), (102, 202, 'completed'), (101, 203, 'planned'), (101, 204, 'watching'),
    (101, 205, 'watching'), (102, 205, 'completed'), (103, 205, 'watching'),
    (101, 206, 'planned'), (102, 206, 'on_hold'), (103, 206, 'dropped'),
    (101, 208, 'watching'), (101, 209, 'watching');
SQL);
    $repository = new AnimeRankingRepository($connection);
    $criteria = static fn (array $query = []): AnimeRankingCriteria => AnimeRankingCriteria::fromQuery($query);
    $ids = static fn (array $page): array => array_column($page['entries'], 'id');
    $run = static function (string $name, Closure $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT anime_ranking_test');
        try {
            $test();
            $passed++;
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT anime_ranking_test');
            $connection->exec('RELEASE SAVEPOINT anime_ranking_test');
        }
    };

    $run('default vote threshold excludes one-vote leaders and imported scores never affect community order', static function () use ($repository, $criteria, $ids, $assert): void {
        $assert($ids($repository->findPage($criteria())) === [204], 'Five votes are required by default.');
        $page = $repository->findPage($criteria(['min_votes' => '1']));
        $assert($ids($page) === [203, 202, 201, 208, 209, 204], 'Average, vote count, title and ID determine the order.');
        $assert($page['total'] === 6 && $page['page'] === 1 && $page['totalPages'] === 1, 'Paging metadata covers only qualified entries.');
    });

    $run('watching counts distinct accounts with watching or completed status without rating join multiplication', static function () use ($repository, $criteria, $ids, $assert): void {
        $page = $repository->findPage($criteria(['mode' => 'watched', 'min_votes' => '1000000']));
        $assert($ids($page) === [205, 202, 201, 208, 209, 204], 'Unrated anime can lead by watchers; plans and paused or dropped entries are excluded.');
        $assert($page['entries'][0]['communityAverage'] === null && $page['entries'][0]['communityCount'] === 0 && $page['entries'][0]['watcherCount'] === 3, 'No score is fabricated for unrated leaders.');
        $assert($page['entries'][1]['communityCount'] === 3 && $page['entries'][1]['watcherCount'] === 2, 'Two watchers and three votes remain separate aggregates.');
    });

    $run('genre and exact release year filters combine without duplicate anime', static function () use ($repository, $criteria, $ids, $assert): void {
        $assert($ids($repository->findPage($criteria(['min_votes' => '1', 'genre' => '2', 'year' => '2020']))) === [202], 'Both filters apply before counting and pagination.');
        $assert($ids($repository->findPage($criteria(['mode' => 'watched', 'genre' => '1', 'year' => '2020']))) === [205, 202, 201, 208, 209], 'Watched mode shares genre and year filters.');
        $empty = $repository->findPage($criteria(['genre' => '999', 'page' => '100']));
        $assert($empty === ['entries' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1], 'Missing genre gives a safe, empty first page.');
        $assert($repository->findPage($criteria(['genre' => (string) PHP_INT_MAX]))['total'] === 0, 'Large valid IDs never overflow the integer genre column.');
    });

    $run('community ordering uses full precision before rounding and resolves exact title ties by ID', static function () use ($repository, $criteria, $connection, $ids, $assert): void {
        $connection->exec("INSERT INTO anime (id, title, release_year) VALUES (210, 'Precision high', 2015), (211, 'Precision low', 2015)");
        $connection->exec('INSERT INTO user_anime_ratings (user_id, anime_id, rating) VALUES (101, 210, 8), (102, 210, 8), (103, 210, 9)');
        $connection->exec('INSERT INTO user_anime_ratings (user_id, anime_id, rating) SELECT id, 211, CASE WHEN id < 201 THEN 9 ELSE 8 END FROM users WHERE id BETWEEN 101 AND 401');
        $page = $repository->findPage($criteria(['year' => '2015', 'min_votes' => '1']));
        $assert($ids($page) === [210, 211], 'Higher exact average beats a lower average with more votes despite identical display rounding.');
        $assert(round($page['entries'][0]['communityAverage'], 2) === round($page['entries'][1]['communityAverage'], 2), 'Both values round to the same two-decimal score.');
        $assert($page['entries'][0]['communityAverage'] > $page['entries'][1]['communityAverage'], 'Repository projection keeps unrounded average.');
    });

    $run('page sizes are bounded and ranks continue across stable pages of twenty', static function () use ($repository, $criteria, $connection, $ids, $assert): void {
        $insert = $connection->prepare('INSERT INTO anime (id, title, release_year) VALUES (:id, :title, 2010)');
        $rating = $connection->prepare('INSERT INTO user_anime_ratings (user_id, anime_id, rating) VALUES (101, :id, 6)');
        for ($i = 1; $i <= 25; $i++) {
            $id = 300 + $i;
            $insert->execute(['id' => $id, 'title' => sprintf('Paged %02d', $i)]);
            $rating->execute(['id' => $id]);
        }
        $first = $repository->findPage($criteria(['year' => '2010', 'min_votes' => '1']));
        $last = $repository->findPage($criteria(['year' => '2010', 'min_votes' => '1', 'page' => '2']));
        $assert($ids($first) === range(301, 320) && $ids($last) === range(321, 325), 'Stable pages contain each anime exactly once.');
        $assert(array_column($first['entries'], 'rank') === range(1, 20) && array_column($last['entries'], 'rank') === range(21, 25), 'Displayed positions do not restart per page.');
        $assert($repository->findPage($criteria(['year' => '2010', 'min_votes' => '1', 'page' => '1000000']))['page'] === 2, 'Excess pages clamp to the last page.');
        $assert(count($repository->findPage($criteria(['year' => '2010', 'min_votes' => '1']), 0)['entries']) === 1, 'A zero page size is safely bounded.');
        $assert(count($repository->findPage($criteria(['year' => '2010', 'min_votes' => '1']), 1000)['entries']) === 25, 'Maximum page size is bounded to one hundred.');
    });

    $run('changing ratings and watchlist status updates the ranking immediately without changing source scores', static function () use ($repository, $criteria, $connection, $ids, $assert): void {
        $connection->exec('DELETE FROM user_anime_ratings WHERE anime_id = 203');
        $assert(!in_array(203, $ids($repository->findPage($criteria(['min_votes' => '1']))), true), 'Removing the last vote removes the anime from best ranking.');
        $connection->exec("UPDATE user_watchlist SET status = 'planned' WHERE anime_id = 205");
        $assert(!in_array(205, $ids($repository->findPage($criteria(['mode' => 'watched']))), true), 'Only current eligible watchlist statuses count.');
        $assert((float) $connection->query('SELECT score FROM anime WHERE id = 203')->fetchColumn() === 10.0, 'Source score stays untouched.');
    });

    $run('public projection contains aggregates and ranking positions without user identifiers or private values', static function () use ($repository, $criteria, $assert): void {
        $entry = $repository->findPage($criteria(['min_votes' => '1']))['entries'][0];
        $assert(array_keys($entry) === ['id', 'rank', 'communityAverage', 'communityCount', 'watcherCount'], 'Projection exposes only anime-level aggregates.');
    });

    $run('criteria normalizes valid scalar input and pagination parameters preserve active filters', static function () use ($criteria, $assert): void {
        $value = $criteria(['mode' => ' best ', 'genre' => '2', 'year' => '2020', 'min_votes' => '10', 'page' => '3']);
        $assert($value->errors === [] && $value->mode === 'best' && $value->genreId === 2 && $value->year === 2020 && $value->minVotes === 10 && $value->page === 3, 'Valid scalar fields are parsed.');
        $assert($value->parameters() === ['mode' => 'best', 'genre' => '2', 'year' => '2020', 'min_votes' => '10'], 'Pagination adds its own page to canonical active parameters.');
        $assert($criteria(['mode' => 'watched', 'min_votes' => '100'])->parameters() === ['mode' => 'watched'], 'Watched links do not apply vote threshold.');
    });

    $run('malformed criteria reports validation errors and is rejected before querying rankings', static function () use ($repository, $criteria, $assert): void {
        foreach ([['mode' => 'best;DROP TABLE anime'], ['mode' => []], ['genre' => ['1']], ['year' => '1899'], ['year' => '2201'], ['year' => '2020.5'], ['min_votes' => '0'], ['min_votes' => '1000001'], ['page' => '-1'], ['page' => '1e3'], ['year' => "2020\0"], ['year' => "\xFF"], ['genre' => str_repeat('1', 65)], ['genre' => (string) PHP_INT_MAX . '0'], ['min_votes' => true], ['page' => ['2']], ['mode' => 'watched', 'min_votes' => ['5']], ['mode' => 'watched', 'min_votes' => 'invalid']] as $input) {
            $value = $criteria($input);
            $assert($value->errors !== [], 'Invalid criteria must report errors: ' . json_encode($input));
            try {
                $repository->findPage($value);
                throw new RuntimeException('Invalid criteria reached query.');
            } catch (InvalidArgumentException) {
                // Only canonical, validated criteria may reach SQL.
            }
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
