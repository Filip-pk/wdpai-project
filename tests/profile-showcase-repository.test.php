<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AchievementRepository;
use App\Repository\AnimeRepository;
use App\Repository\ProfileShowcaseRepository;
use App\Service\AchievementService;
use App\Service\ProfileShowcaseService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $connection->exec('CREATE TEMP TABLE users (id BIGINT PRIMARY KEY) ON COMMIT DROP');
    $connection->exec('CREATE TEMP TABLE anime (LIKE public.anime INCLUDING ALL) ON COMMIT DROP');
    $connection->exec('CREATE TEMP TABLE genres (id BIGINT PRIMARY KEY, name TEXT NOT NULL) ON COMMIT DROP');
    $connection->exec('CREATE TEMP TABLE anime_genres (anime_id BIGINT, genre_id BIGINT) ON COMMIT DROP');
    $connection->exec('CREATE TEMP TABLE user_achievements (
        user_id BIGINT REFERENCES pg_temp.users(id) ON DELETE CASCADE,
        achievement_code VARCHAR(64), unlocked_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
        PRIMARY KEY (user_id, achievement_code)
    ) ON COMMIT DROP');
    $migration = file_get_contents(dirname(__DIR__) . '/database/28-profile-showcase.sql');
    $assert(is_string($migration));
    $connection->exec(str_replace(
        ['BEGIN;', 'COMMIT;', 'CREATE TABLE public.', 'REFERENCES public.'],
        ['', '', 'CREATE TEMP TABLE ', 'REFERENCES pg_temp.'],
        $migration,
    ));
    foreach (['users', 'anime', 'genres', 'anime_genres', 'user_achievements', 'user_showcase_anime', 'user_showcase_badges'] as $table) {
        $verify = $connection->prepare("SELECT relnamespace = pg_my_temp_schema() AND relpersistence = 't'
            FROM pg_class WHERE oid = to_regclass(:table)");
        $verify->execute(['table' => $table]);
        $assert($verify->fetchColumn() === true, 'Testy zapisują wyłącznie własne tabele tymczasowe.');
    }
    $connection->exec('INSERT INTO pg_temp.users (id) VALUES (101), (202)');
    $connection->exec("INSERT INTO pg_temp.anime (id, title) VALUES (1,'Anime A'), (2,'Anime B'), (3,'Anime C'), (4,'Anime D')");
    $connection->exec("INSERT INTO pg_temp.user_achievements (user_id, achievement_code) VALUES
        (101,'anime_first'), (101,'quiz_first'), (101,'quiz_perfect'), (202,'quiz_streak_7')");
    $repository = new ProfileShowcaseRepository($connection);
    $service = new ProfileShowcaseService($repository, new AnimeRepository($connection), new AchievementService(new AchievementRepository($connection)));
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT showcase_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT showcase_repository_test');
            $connection->exec('RELEASE SAVEPOINT showcase_repository_test');
        }
    };

    $run('Nowa gablotka jest pusta, a zapis i odczyt zachowują kolejność', static function () use ($repository, $assert): void {
        $assert($repository->findForUser(101) === ['animeIds' => [], 'badgeCodes' => []]);
        $repository->saveForUser(101, [3, 1, 2], ['quiz_perfect', 'anime_first', 'quiz_first']);
        $assert($repository->findForUser(101) === ['animeIds' => [3, 1, 2], 'badgeCodes' => ['quiz_perfect', 'anime_first', 'quiz_first']]);
        $assert($repository->findForUser(202) === ['animeIds' => [], 'badgeCodes' => []]);
    });

    $run('Zastąpienie wyboru i wyczyszczenie gablotki dotyczą jedynie właściciela', static function () use ($repository, $assert): void {
        $repository->saveForUser(101, [1, 2], ['anime_first']);
        $repository->saveForUser(202, [4], ['quiz_streak_7']);
        $repository->saveForUser(101, [3], ['quiz_first']);
        $assert($repository->findForUser(101) === ['animeIds' => [3], 'badgeCodes' => ['quiz_first']]);
        $repository->saveForUser(101, [], []);
        $assert($repository->findForUser(101) === ['animeIds' => [], 'badgeCodes' => []]);
        $assert($repository->findForUser(202) === ['animeIds' => [4], 'badgeCodes' => ['quiz_streak_7']]);
    });

    $run('Obca odznaka wycofuje cały zapis i zachowuje otwartą transakcję', static function () use ($repository, $connection, $assert): void {
        $repository->saveForUser(101, [1], ['anime_first']);
        try {
            $repository->saveForUser(101, [2, 3], ['quiz_streak_7']);
            $assert(false, 'Klucz obcy musi odrzucić niezdobytą odznakę.');
        } catch (PDOException) {
            $assert($connection->inTransaction());
        }
        $assert($repository->findForUser(101) === ['animeIds' => [1], 'badgeCodes' => ['anime_first']]);
        $repository->saveForUser(101, [2], ['quiz_first']);
        $assert($repository->findForUser(101)['animeIds'] === [2]);
    });

    $run('Usunięte anime nie pozostaje w gablotce', static function () use ($repository, $connection, $assert): void {
        $repository->saveForUser(101, [1, 2], ['anime_first']);
        $connection->exec('DELETE FROM pg_temp.anime WHERE id = 1');
        $assert($repository->findForUser(101) === ['animeIds' => [2], 'badgeCodes' => ['anime_first']]);
    });

    $run('Baza wymusza trzy sloty, unikalność anime i własność odznak', static function () use ($connection, $assert): void {
        foreach ([
            'INSERT INTO user_showcase_anime VALUES (101,4,1)',
            'INSERT INTO user_showcase_anime VALUES (101,0,1)',
            'INSERT INTO user_showcase_anime VALUES (101,1,1),(101,2,1)',
            "INSERT INTO user_showcase_badges VALUES (101,1,'quiz_streak_7')",
            "INSERT INTO user_showcase_badges VALUES (101,4,'anime_first')",
        ] as $sql) {
            $connection->exec('SAVEPOINT invalid_showcase_constraint');
            $rejected = false;
            try { $connection->exec($sql); } catch (PDOException) { $rejected = true; }
            $connection->exec('ROLLBACK TO SAVEPOINT invalid_showcase_constraint');
            $connection->exec('RELEASE SAVEPOINT invalid_showcase_constraint');
            $assert($rejected, $sql);
        }
    });

    $run('Serwis sprawdza katalog oraz zdobyte odznaki przed zmianą gablotki', static function () use ($service, $repository, $assert): void {
        $assert($service->update(101, ['3', '', '1'], ['quiz_perfect']) === []);
        $before = $repository->findForUser(101);
        foreach ([[['999999'], []], [['2'], ['quiz_streak_7']], [['2'], ['forged_badge']], [['2', '2'], []], [['2'], 'quiz_first']] as [$animeIds, $badges]) {
            $assert($service->update(101, $animeIds, $badges) !== []);
            $assert($repository->findForUser(101) === $before, 'Niepoprawny wybór musi zachować gablotkę.');
        }
        $assert($service->update(101, [], []) === []);
        $assert($repository->findForUser(101) === ['animeIds' => [], 'badgeCodes' => []]);
    });

    $run('Niepoprawne konta i wybory są odrzucane przed zapisem', static function () use ($repository, $assert): void {
        foreach ([
            static fn () => $repository->findForUser(0),
            static fn () => $repository->saveForUser(-1, [], []),
            static fn () => $repository->saveForUser(999999, [], []),
            static fn () => $repository->saveForUser(101, [1, 2, 3, 4], []),
            static fn () => $repository->saveForUser(101, [1, 1], []),
            static fn () => $repository->saveForUser(101, ['1'], []),
            static fn () => $repository->saveForUser(101, [], [['anime_first']]),
        ] as $operation) {
            $rejected = false;
            try { $operation(); } catch (InvalidArgumentException) { $rejected = true; }
            $assert($rejected);
        }
        $assert($repository->findForUser(101) === ['animeIds' => [], 'badgeCodes' => []]);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie testów gablotki: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
