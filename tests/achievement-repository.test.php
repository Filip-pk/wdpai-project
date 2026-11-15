<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AchievementRepository;
use App\Service\AchievementService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$questions = json_encode(array_fill(0, 5, ['id' => 1]), JSON_THROW_ON_ERROR);

if (($argv[1] ?? '') === 'worker') {
    try {
        $schema = $argv[2] ?? '';
        $assert(preg_match('/\Aachievement_concurrency_[a-f0-9]{16}\z/', $schema) === 1, 'Niepoprawny schemat procesu testowego.');
        $workerConnection = (new Database())->connect();
        $owner = $workerConnection->prepare("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = :schema");
        $owner->execute(['schema' => $schema]);
        $assert($owner->fetchColumn() === 'AniVibe isolated achievement concurrency test', 'Nie znaleziono własnego znacznika schematu.');
        $workerConnection->exec('SET search_path TO "' . $schema . '", public');
        $workerConnection->exec("SET lock_timeout = '5s'; SET statement_timeout = '8s'");
        echo json_encode(['pid' => (int) $workerConnection->query('SELECT pg_backend_pid()')->fetchColumn()], JSON_THROW_ON_ERROR) . PHP_EOL;
        flush();
        $assert(trim((string) fgets(STDIN)) === 'start', 'Brak sygnału rozpoczęcia.');
        $repository = new AchievementRepository($workerConnection);
        $result = $repository->unlockForUser(101, ['quiz_first'], new DateTimeImmutable('2026-11-15T12:00:00Z'));
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach (['users', 'anime', 'genres', 'anime_genres', 'user_watchlist', 'user_anime_experience', 'daily_quizzes', 'quiz_attempts', 'user_achievements', 'daily_anime_clues', 'anime_clue_attempts', 'anime_clue_answers'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $verify = $connection->prepare("SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
            AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'");
        $verify->execute(['table' => $table]);
        $assert((int) $verify->fetchColumn() === 1, 'Wymagana własna tabela tymczasowa.');
    }
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $connection->exec('ALTER TABLE pg_temp.user_achievements ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users(id) ON DELETE CASCADE');
    $connection->exec("INSERT INTO pg_temp.users (id, name, email, password_hash)
        VALUES (101, 'Osiągnięcia A', 'achievement-a@example.test', 'hash'),
               (202, 'Osiągnięcia B', 'achievement-b@example.test', 'hash')");
    $connection->exec("INSERT INTO pg_temp.anime (id, title) SELECT id, 'Osiągnięcia anime ' || id FROM generate_series(1, 50) AS id");
    $connection->exec("INSERT INTO pg_temp.genres (id, source_id, name) SELECT id, id, 'Gatunek ' || id FROM generate_series(1, 6) AS id");
    $connection->exec('INSERT INTO pg_temp.anime_genres (anime_id, genre_id) VALUES (1,1),(1,2),(2,2),(2,3),(3,4),(3,5),(50,6)');
    $repository = new AchievementRepository($connection);
    $now = new DateTimeImmutable('2026-11-14T12:00:00Z');
    $service = new AchievementService($repository, static function () use (&$now): DateTimeImmutable { return $now; });
    $insertQuiz = static function (string $date, int $score, int $userId = 101) use ($connection, $questions): int {
        $quiz = $connection->prepare('INSERT INTO daily_quizzes (quiz_date, questions) VALUES (:date, CAST(:questions AS jsonb))
            ON CONFLICT (quiz_date) DO UPDATE SET questions = EXCLUDED.questions RETURNING id');
        $quiz->execute(['date' => $date, 'questions' => $questions]);
        $id = (int) $quiz->fetchColumn();
        $attempt = $connection->prepare("INSERT INTO quiz_attempts (user_id, quiz_id, score, answers) VALUES (:user, :quiz, :score, '{}'::jsonb)");
        $attempt->execute(['user' => $userId, 'quiz' => $id, 'score' => $score]);
        return $id;
    };
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT achievement_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT achievement_repository_test');
            $connection->exec('RELEASE SAVEPOINT achievement_repository_test');
        }
    };

    $run('Puste konto ma zero postępu i odczyt nie przyznaje odznak', static function () use ($service, $repository, $assert): void {
        $overview = $service->overviewForUser(101);
        $assert($overview['unlockedCount'] === 0 && $overview['totalCount'] === 15);
        $assert($service->earnedForUser(101) === [] && $repository->unlockedForUser(101) === []);
        $assert($service->synchronize(101) === []);
    });

    $run('Udział 0/5 odblokowuje pierwszy quiz, a wynik perfekcyjny jest liczony osobno', static function () use ($service, $insertQuiz, $assert): void {
        $insertQuiz('2026-11-13', 0);
        $assert($service->synchronize(101) === ['quiz_first']);
        $insertQuiz('2026-11-14', 5);
        $assert($service->synchronize(101) === ['quiz_perfect']);
        $assert($service->overviewForUser(101)['unlockedCount'] === 2);
        $assert($service->earnedForUser(202) === []);
    });

    $run('Setna poprawna odpowiedź i rekord serii odblokowują się bez wymagania dzisiejszego udziału', static function () use ($service, $insertQuiz, $assert): void {
        for ($day = 1; $day <= 20; $day++) {
            $insertQuiz('2026-10-' . sprintf('%02d', $day), 5);
        }
        $codes = $service->synchronize(101);
        $assert($codes === ['quiz_first', 'quiz_perfect', 'quiz_correct_100', 'quiz_streak_7']);
        $items = array_column($service->overviewForUser(101)['items'], null, 'code');
        $assert($items['quiz_streak_30']['progress'] === 20 && !$items['quiz_streak_30']['unlocked']);
    });

    $run('Rekord 30 dni działa przez zmianę czasu, a przyszłe wyniki nie przyznają odznak', static function () use ($service, $insertQuiz, $assert): void {
        $day = new DateTimeImmutable('2026-10-01');
        for ($index = 0; $index < 30; $index++) {
            $insertQuiz($day->modify('+' . $index . ' days')->format('Y-m-d'), 0);
        }
        $insertQuiz('2026-11-15', 5);
        $assert($service->synchronize(101) === ['quiz_first', 'quiz_streak_7', 'quiz_streak_30']);
        $assert(!$service->overviewForUser(101)['items'][1]['unlocked']);
    });

    $run('Konto jest izolowane, a nieukończone tytuły nie zwiększają liczby gatunków', static function () use ($connection, $repository, $assert): void {
        $connection->exec('INSERT INTO user_anime_experience (user_id, anime_id, watched, completed)
            VALUES (101,1,12,TRUE),(101,2,24,TRUE),(101,3,0,FALSE),(202,3,100,TRUE)');
        $first = $repository->metricsForUser(101, '2026-11-14');
        $second = $repository->metricsForUser(202, '2026-11-14');
        $assert($first['episodes'] === 36 && $first['completedAnime'] === 2 && $first['completedGenres'] === 3);
        $assert($second['episodes'] === 100 && $second['completedAnime'] === 1 && $second['completedGenres'] === 2);
    });

    $run('Progi anime i pięciu gatunków wynikają z ukończonych pozycji i zachowanych odcinków', static function () use ($connection, $service, $assert): void {
        $connection->exec('INSERT INTO user_anime_experience (user_id, anime_id, watched, completed)
            SELECT 101, id, 10, TRUE FROM anime WHERE id BETWEEN 1 AND 10');
        $assert($service->synchronize(101) === ['anime_first', 'anime_completed_10', 'anime_genres_5', 'anime_episodes_100']);
        $connection->exec('INSERT INTO user_anime_experience (user_id, anime_id, watched, completed)
            SELECT 101, id, 0, TRUE FROM anime WHERE id BETWEEN 11 AND 50');
        $assert($service->synchronize(101) === ['anime_completed_50']);
    });

    $run('Usunięcie z watchlisty oraz korekta postępu nie odbierają zdobytych odznak', static function () use ($connection, $service, $repository, $assert): void {
        $connection->exec("INSERT INTO user_watchlist (user_id, anime_id, status, watched) VALUES (101,1,'completed',100)");
        $connection->exec('INSERT INTO user_anime_experience (user_id, anime_id, watched, completed) VALUES (101,1,100,TRUE)');
        $assert($service->synchronize(101) === ['anime_first', 'anime_episodes_100']);
        $before = $repository->unlockedForUser(101);
        $connection->exec('DELETE FROM user_watchlist WHERE user_id = 101');
        $assert($service->synchronize(101) === [] && $repository->metricsForUser(101, '2026-11-14')['episodes'] === 100);
        $connection->exec('UPDATE user_anime_experience SET watched = 0, completed = FALSE WHERE user_id = 101');
        $assert($service->synchronize(101) === [] && $repository->unlockedForUser(101) === $before);
        $items = array_column($service->overviewForUser(101)['items'], null, 'code');
        $assert($items['anime_first']['unlocked'] && $items['anime_first']['progress'] === 1);
    });

    $run('Powtórna synchronizacja i zapis nie zmieniają daty pierwszego odblokowania', static function () use ($service, $repository, $insertQuiz, &$now, $assert): void {
        $insertQuiz('2026-11-14', 5);
        $assert($service->synchronize(101) === ['quiz_first', 'quiz_perfect']);
        $before = $repository->unlockedForUser(101);
        $oldNow = $now;
        try {
            $now = new DateTimeImmutable('2026-11-20T18:00:00Z');
            $assert($service->synchronize(101) === []);
            $assert($repository->unlockForUser(101, ['quiz_first', 'quiz_first'], $now) === []);
            $assert($repository->unlockedForUser(101) === $before);
        } finally {
            $now = $oldNow;
        }
    });

    $run('Błąd synchronizacji cofa własny zapis, ale pozostawia zewnętrzną transakcję otwartą', static function () use ($repository, $connection, $now, $assert): void {
        $rejected = false;
        try {
            $repository->transaction(static function () use ($repository, $now): void {
                $repository->unlockForUser(101, ['quiz_first'], $now);
                throw new RuntimeException('Kontrolowany błąd synchronizacji.');
            });
        } catch (RuntimeException) {
            $rejected = true;
        }
        $assert($rejected && $connection->inTransaction() && $repository->unlockedForUser(101) === []);
        $repository->transaction(static fn (): array => $repository->unlockForUser(101, ['anime_first'], $now));
        $assert($connection->inTransaction() && array_keys($repository->unlockedForUser(101)) === ['anime_first']);
    });

    $run('Niepoprawne konta, daty i nieznane kody nie zapisują danych', static function () use ($service, $repository, $now, $assert): void {
        foreach ([
            static fn () => $service->synchronize(0), static fn () => $service->overviewForUser(-1),
            static fn () => $service->earnedForUser(0), static fn () => $repository->metricsForUser(101, '2026-02-30'),
            static fn () => $repository->unlockForUser(101, ['forged_badge'], $now),
            static fn () => $repository->unlockForUser(101, [['quiz_first']], $now),
        ] as $operation) {
            $rejected = false;
            try { $operation(); } catch (InvalidArgumentException) { $rejected = true; }
            $assert($rejected);
        }
        $assert($repository->unlockedForUser(101) === []);
    });

    $run('Samodzielna transakcja zatwierdza sukces i wycofuje błąd bez wpływu na wcześniejsze odznaki', static function () use ($now, $assert): void {
        $standalone = (new Database())->connect();
        $standalone->exec('CREATE TEMP TABLE user_achievements (LIKE public.user_achievements INCLUDING ALL)');
        $verify = $standalone->query("SELECT relpersistence = 't' FROM pg_class WHERE oid = 'user_achievements'::regclass");
        $assert($verify->fetchColumn() === true, 'Samodzielny test musi zapisywać wyłącznie tabelę tymczasową.');
        $repository = new AchievementRepository($standalone);
        $repository->transaction(static fn (): array => $repository->unlockForUser(101, ['quiz_first'], $now));
        $assert(!$standalone->inTransaction() && array_keys($repository->unlockedForUser(101)) === ['quiz_first']);
        try {
            $repository->transaction(static function () use ($repository, $now): void {
                $repository->unlockForUser(101, ['quiz_perfect'], $now);
                throw new RuntimeException('Kontrolowany błąd samodzielnej transakcji.');
            });
            $assert(false, 'Wyjątek musi opuścić transakcję.');
        } catch (RuntimeException $exception) {
            $assert($exception->getMessage() === 'Kontrolowany błąd samodzielnej transakcji.');
        }
        $assert(!$standalone->inTransaction() && array_keys($repository->unlockedForUser(101)) === ['quiz_first']);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie testów osiągnięć: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection !== null && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

$schema = 'achievement_concurrency_' . bin2hex(random_bytes(8));
$created = false;
$worker = null;
try {
    $connection = (new Database())->connect();
    $connection->exec('CREATE SCHEMA "' . $schema . '"');
    $created = true;
    $connection->exec('COMMENT ON SCHEMA "' . $schema . '" IS \'AniVibe isolated achievement concurrency test\'');
    $connection->exec('SET search_path TO "' . $schema . '", public');
    $connection->exec('CREATE TABLE users (id BIGINT PRIMARY KEY)');
    $connection->exec('CREATE TABLE user_achievements (LIKE public.user_achievements INCLUDING ALL)');
    $connection->exec('ALTER TABLE user_achievements ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    $connection->exec('INSERT INTO users (id) VALUES (101)');
    $repository = new AchievementRepository($connection);
    $connection->beginTransaction();
    $assert($repository->unlockForUser(101, ['quiz_first'], new DateTimeImmutable('2026-11-14T12:00:00Z')) === ['quiz_first']);
    $first = $repository->unlockedForUser(101);
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $schema], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__));
    $assert(is_resource($process), 'Nie udało się uruchomić procesu testowego.');
    $worker = ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    stream_set_timeout($worker['stdout'], 10);
    $line = fgets($worker['stdout']);
    $assert(is_string($line), 'Brak sygnału gotowości procesu.');
    $ready = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    fwrite($worker['stdin'], "start\n");
    fclose($worker['stdin']);
    $lookup = $connection->prepare("SELECT wait_event_type = 'Lock' FROM pg_stat_activity WHERE pid = :pid");
    $deadline = microtime(true) + 3;
    $blocked = false;
    do {
        $lookup->execute(['pid' => $ready['pid']]);
        if ($lookup->fetchColumn() === true) {
            $blocked = true;
            break;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    $assert($blocked, 'Konkurencyjny zapis musi rzeczywiście czekać na unikalny wiersz.');
    $connection->commit();
    $line = fgets($worker['stdout']);
    $error = stream_get_contents($worker['stderr']);
    $assert(is_string($line) && $error === '', 'Niepoprawny wynik procesu: ' . $error);
    $assert(json_decode($line, true, 512, JSON_THROW_ON_ERROR) === [], 'Drugie połączenie nie może ponownie odblokować odznaki.');
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    $assert(proc_close($worker['process']) === 0, 'Proces testowy zakończył się błędem.');
    $assert($repository->unlockedForUser(101) === $first, 'Równoległy zapis nie zmienia daty pierwszego odblokowania.');
    $assert((int) $connection->query('SELECT count(*) FROM user_achievements')->fetchColumn() === 1);
    $passed++;
    echo '[OK] Dwa równoległe połączenia zachowują jedną odznakę i pierwotną datę' . PHP_EOL;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Równoległość osiągnięć: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if (is_array($worker)) {
        foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
            if (is_resource($worker[$pipe])) fclose($worker[$pipe]);
        }
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }
    if ($created && $connection instanceof PDO) {
        $owner = $connection->prepare("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = :schema");
        $owner->execute(['schema' => $schema]);
        if ($owner->fetchColumn() === 'AniVibe isolated achievement concurrency test'
            && preg_match('/\Aachievement_concurrency_[a-f0-9]{16}\z/', $schema) === 1) {
            $connection->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
