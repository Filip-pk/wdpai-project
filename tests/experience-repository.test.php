<?php

declare(strict_types=1);

use App\Database;
use App\Repository\ExperienceRepository;
use App\Repository\WatchlistRepository;

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
    foreach (['users', 'anime', 'user_watchlist', 'user_anime_experience'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND oid <> to_regclass(:public_table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Każda tabela testu musi być tymczasowa.');
    }
    // BIGSERIAL może kopiować publiczny DEFAULT; test używa wyłącznie jawnych ID kont.
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $assert((int) $connection->query(
        "SELECT count(*) FROM pg_class
         WHERE oid = pg_get_serial_sequence('anime', 'id')::regclass
         AND oid <> pg_get_serial_sequence('public.anime', 'id')::regclass
         AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
    )->fetchColumn() === 1, 'Sekwencja anime musi być niezależna od katalogu aplikacji.');
    $connection->exec(
        'ALTER TABLE pg_temp.user_watchlist
             ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id);
         ALTER TABLE pg_temp.user_anime_experience
             ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id) ON DELETE CASCADE'
    );
    $connection->exec(
        "INSERT INTO pg_temp.users (id, name, email, password_hash)
         VALUES (101, 'Pierwszy <script>imię</script>', 'exp-first@example.test', 'hash'),
                (202, 'Drugi', 'exp-second@example.test', 'hash');
         INSERT INTO pg_temp.anime (id, title, episodes)
         VALUES (1, 'Sezon', 12), (2, 'Film', 1), (3, 'Nieznana długość', NULL), (4, 'Zero odcinków', 0)"
    );
    $experience = new ExperienceRepository($connection);
    $watchlists = new WatchlistRepository($connection);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT experience_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT experience_repository_test');
            $connection->exec('RELEASE SAVEPOINT experience_repository_test');
        }
    };

    $run('Konta bez postępu mają zero EXP i współdzielą miejsce w rankingu', static function () use ($experience, $assert): void {
        $assert($experience->totalForUser(101) === 0 && $experience->totalForUser(202) === 0);
        $rows = $experience->topUsers();
        $assert(array_column($rows, 'userId') === [101, 202]);
        $assert(array_column($rows, 'rank') === [1, 1] && array_column($rows, 'totalExp') === [0, 0]);
        $assert($experience->rankForUser(PHP_INT_MAX) === null);
        $assert($experience->totalForUser(PHP_INT_MAX) === 0);
        $combined = $experience->rankingForUser(101);
        $assert($combined['leaders'] === $rows && $combined['ownRanking'] === $rows[0]);
        $assert($experience->rankingForUser(PHP_INT_MAX)['ownRanking'] === null);
    });

    $run('Odcinki dają 5 EXP, a ukończenie sezonu i filmu oddzielny bonus 100 EXP', static function () use (
        $experience, $watchlists, $assert,
    ): void {
        $watchlists->addForUser(101, 1);
        $assert($experience->totalForUser(101) === 0);
        $watchlists->saveForUser(101, 1, 'watching', 4);
        $assert($experience->totalForUser(101) === 20);
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $assert($experience->totalForUser(101) === 160);
        $watchlists->saveForUser(101, 2, 'completed', 1);
        $assert($experience->totalForUser(101) === 265 && $experience->totalForUser(202) === 0);
        $assert($experience->rankForUser(101)['rank'] === 1 && $experience->rankForUser(202)['rank'] === 2);
    });

    $run('Ponowne zapisy, usunięcie i ponowne dodanie nie powielają zdobytego EXP', static function () use (
        $experience, $watchlists, $connection, $assert,
    ): void {
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $watchlists->addForUser(101, 1);
        $assert($experience->totalForUser(101) === 160);
        $watchlists->removeForUser(101, 1);
        $assert($watchlists->findForUser(101) === [] && $experience->totalForUser(101) === 160);
        $watchlists->addForUser(101, 1);
        $assert($watchlists->findForUser(101) === [['animeId' => 1, 'status' => 'planned', 'watched' => 0]]);
        $assert($experience->totalForUser(101) === 160);
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $assert($experience->totalForUser(101) === 160);
        $assert((int) $connection->query('SELECT count(*) FROM user_anime_experience')->fetchColumn() === 1);
    });

    $run('Cofnięcie postępu i ukończenia koryguje EXP zamiast umożliwiać nabijanie punktów', static function () use (
        $experience, $watchlists, $assert,
    ): void {
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $watchlists->saveForUser(101, 1, 'watching', 3);
        $assert($experience->totalForUser(101) === 15);
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $assert($experience->totalForUser(101) === 160);
        $watchlists->saveForUser(101, 1, 'planned', 0);
        $assert($experience->totalForUser(101) === 0);
        $watchlists->saveForUser(101, 1, 'watching', 4);
        $assert($experience->totalForUser(101) === 20);
    });

    $run('Nieznana liczba odcinków nie blokuje punktów za wpisany postęp i ukończenie', static function () use (
        $experience, $watchlists, $assert,
    ): void {
        $watchlists->saveForUser(101, 3, 'watching', 7);
        $assert($experience->totalForUser(101) === 35);
        $watchlists->saveForUser(101, 3, 'completed', 7);
        $assert($experience->totalForUser(101) === 135);
        $watchlists->saveForUser(101, 4, 'completed', 0);
        $assert($experience->totalForUser(101) === 235);
    });

    $run('Mnożenie liczby odcinków nie przepełnia 32-bitowego INTEGER w PostgreSQL', static function () use (
        $experience, $watchlists, $assert,
    ): void {
        $watchlists->saveForUser(101, 3, 'completed', 2_147_483_647);
        $assert($experience->totalForUser(101) === 10_737_418_335);
        $assert($experience->topUsers()[0]['totalExp'] === 10_737_418_335);
    });

    $run('Zapis watchlisty zostaje wycofany, gdy nie powiedzie się zapis EXP', static function () use (
        $experience, $watchlists, $connection, $assert,
    ): void {
        $watchlists->saveForUser(101, 1, 'watching', 4);
        $connection->exec('ALTER TABLE pg_temp.user_anime_experience ADD CHECK (watched < 5)');
        $connection->exec('SAVEPOINT expected_experience_failure');
        $rejected = false;
        try {
            $watchlists->saveForUser(101, 1, 'watching', 8);
        } catch (PDOException $exception) {
            $rejected = $exception->getCode() === '23514';
        }
        $connection->exec('ROLLBACK TO SAVEPOINT expected_experience_failure');
        $connection->exec('RELEASE SAVEPOINT expected_experience_failure');
        $assert($rejected);
        $assert($watchlists->findForUser(101) === [['animeId' => 1, 'status' => 'watching', 'watched' => 4]]);
        $assert($experience->totalForUser(101) === 20);
    });

    $run('Migracja nalicza EXP za wcześniejsze zapisane watchlisty', static function () use (
        $experience, $connection, $assert,
    ): void {
        $connection->exec(
            "INSERT INTO pg_temp.user_watchlist (user_id, anime_id, status, watched)
             VALUES (101, 1, 'watching', 4), (101, 2, 'completed', 1),
                    (101, 3, 'planned', 0), (202, 3, 'completed', 7)"
        );
        $migration = file_get_contents(dirname(__DIR__) . '/database/11-user-experience.sql');
        $assert(is_string($migration) && preg_match('/INSERT INTO public\.user_anime_experience[^;]+;/s', $migration, $matches) === 1);
        $backfill = str_replace('public.', 'pg_temp.', $matches[0]);
        $assert(str_contains($backfill, 'INSERT INTO pg_temp.user_anime_experience')
            && str_contains($backfill, 'FROM pg_temp.user_watchlist') && !str_contains($backfill, 'public.'));
        $connection->exec($backfill);
        $assert($experience->totalForUser(101) === 125 && $experience->totalForUser(202) === 135);
        $assert((int) $connection->query('SELECT count(*) FROM pg_temp.user_anime_experience')->fetchColumn() === 4);
    });

    $run('Ranking zwraca wyłącznie publiczne pola konta i bieżący avatar', static function () use (
        $experience, $connection, $assert,
    ): void {
        $connection->exec("UPDATE pg_temp.users SET avatar_version = repeat('a', 32) WHERE id = 101");
        $row = $experience->rankForUser(101);
        $assert(array_keys($row) === ['userId', 'name', 'avatarUrl', 'totalExp', 'rank']);
        $assert($row['name'] === 'Pierwszy <script>imię</script>');
        $assert($row['avatarUrl'] === '/avatars/101?v=' . str_repeat('a', 32));
        $assert($experience->rankForUser(202)['avatarUrl'] === null);
    });

    $run('Aktualne TOP 100 jest ograniczone do 100 kont i uwzględnia własną pozycję poza nim', static function () use (
        $experience, $watchlists, $connection, $assert,
    ): void {
        $connection->exec(
            "INSERT INTO pg_temp.users (id, name, email, password_hash)
             SELECT id, 'Gracz ' || id, 'exp-' || id || '@example.test', 'hash'
             FROM generate_series(1000, 1104) id;
             INSERT INTO pg_temp.user_anime_experience (user_id, anime_id, watched)
             SELECT id, 1, (1105 - id) * 10 FROM pg_temp.users WHERE id BETWEEN 1000 AND 1104"
        );
        $rows = $experience->topUsers();
        $assert(count($rows) === 100 && array_column($rows, 'userId') === range(1000, 1099));
        $assert(array_column($rows, 'rank') === range(1, 100));
        $assert($experience->rankForUser(1104)['rank'] === 105);
        $assert($experience->rankForUser(202)['rank'] === 106);
        $combined = $experience->rankingForUser(1104);
        $assert($combined['leaders'] === $rows && count($combined['leaders']) === 100);
        $assert($combined['ownRanking']['userId'] === 1104 && $combined['ownRanking']['rank'] === 105);
        $assert(!in_array(1104, array_column($combined['leaders'], 'userId'), true));
        $watchlists->saveForUser(202, 3, 'watching', 2000);
        $updated = $experience->topUsers();
        $assert(count($updated) === 100 && $updated[0]['userId'] === 202 && $updated[0]['totalExp'] === 10_000);
        $combined = $experience->rankingForUser(202);
        $assert($combined['leaders'] === $updated && $combined['ownRanking'] === $updated[0]);
        $assert($experience->rankForUser(1104)['rank'] === 106);
        $watchlists->saveForUser(202, 3, 'planned', 0);
        $assert(array_column($experience->topUsers(), 'userId') === range(1000, 1099));
    });

    $run('Remisy współdzielą miejsce, lecz granica TOP 100 pozostaje deterministyczna', static function () use (
        $experience, $connection, $assert,
    ): void {
        $connection->exec(
            "INSERT INTO pg_temp.users (id, name, email, password_hash)
             SELECT id, 'Remis ' || id, 'tie-' || id || '@example.test', 'hash'
             FROM generate_series(1000, 1104) id;
             INSERT INTO pg_temp.user_anime_experience (user_id, anime_id, watched)
             SELECT id, 1, 10 FROM pg_temp.users WHERE id BETWEEN 1000 AND 1104"
        );
        $rows = $experience->topUsers();
        $assert(count($rows) === 100 && array_column($rows, 'userId') === range(1000, 1099));
        $assert(array_unique(array_column($rows, 'rank')) === [1]);
        $assert($experience->rankForUser(1104)['rank'] === 1 && $experience->rankForUser(101)['rank'] === 106);
        $combined = $experience->rankingForUser(1104);
        $assert($combined['leaders'] === $rows && count($combined['leaders']) === 100);
        $assert($combined['ownRanking']['userId'] === 1104 && $combined['ownRanking']['rank'] === 1);
        $assert(!in_array(1104, array_column($combined['leaders'], 'userId'), true));
    });

    $run('Usunięcie konta usuwa jego EXP i od razu zmienia ranking', static function () use (
        $experience, $watchlists, $connection, $assert,
    ): void {
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $watchlists->saveForUser(202, 2, 'completed', 1);
        $connection->exec('DELETE FROM pg_temp.users WHERE id = 101');
        $assert($experience->rankForUser(101) === null && $experience->totalForUser(101) === 0);
        $assert($experience->topUsers()[0]['userId'] === 202 && $experience->rankForUser(202)['rank'] === 1);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować izolowanych testów EXP: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
