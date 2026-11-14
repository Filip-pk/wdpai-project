<?php

declare(strict_types=1);

use App\Database;
use App\Repository\QuizRepository;
use App\Service\QuizService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$fixture = [];
$answers = [];
for ($index = 1; $index <= 5; $index++) {
    $fixture[] = [
        'id' => $index, 'question' => 'Pytanie ' . $index, 'options' => ['A', 'B', 'C', 'D'],
        'correctOption' => 0, 'explanation' => 'Wyjaśnienie.', 'sourceUrl' => 'https://example.test/',
    ];
    $answers[$index] = 0;
}

// A worker uses a private, marked schema so the race exercises two actual connections.
if (($argv[1] ?? '') === 'worker') {
    try {
        $schema = $argv[2] ?? '';
        $operation = $argv[3] ?? '';
        if (preg_match('/\Aquiz_concurrency_[a-f0-9]{16}\z/', $schema) !== 1 || !in_array($operation, ['daily', 'result'], true)) {
            throw new RuntimeException('Niepoprawne argumenty procesu testowego.');
        }
        $workerConnection = (new Database())->connect();
        $owner = $workerConnection->prepare("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = :schema");
        $owner->execute(['schema' => $schema]);
        $assert($owner->fetchColumn() === 'AniVibe isolated quiz concurrency test', 'Brak znacznika izolowanego schematu.');
        $workerConnection->exec('SET search_path TO "' . $schema . '", public');
        $workerConnection->exec("SET lock_timeout = '5s'; SET statement_timeout = '8s'");
        $repository = new QuizRepository($workerConnection);
        echo json_encode(['ready' => true, 'pid' => (int) $workerConnection->query('SELECT pg_backend_pid()')->fetchColumn()], JSON_THROW_ON_ERROR) . PHP_EOL;
        flush();
        $assert(trim((string) fgets(STDIN)) === 'start', 'Brak sygnału rozpoczęcia.');
        if ($operation === 'daily') {
            $fixture[0]['question'] = 'Drugi konkurencyjny zestaw';
            $value = $repository->createForDate('2026-11-14', $fixture);
        } else {
            $quizId = filter_var($argv[4] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $assert($quizId !== false, 'Niepoprawny identyfikator zestawu.');
            $value = $repository->saveResult(101, $quizId, 0, array_fill(1, 5, 1), new DateTimeImmutable('2026-11-14T16:00:00Z'));
        }
        echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach (['users', 'daily_quizzes', 'quiz_attempts'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Tabela testowa musi być tymczasowa.');
    }
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $connection->exec(
        'ALTER TABLE pg_temp.quiz_attempts
             ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (quiz_id) REFERENCES pg_temp.daily_quizzes (id)'
    );
    $connection->exec(
        "INSERT INTO pg_temp.users (id, name, email, password_hash)
         VALUES (101, 'Quiz pierwszy', 'quiz-first@example.test', 'hash'),
                (202, 'Quiz drugi', 'quiz-second@example.test', 'hash')"
    );
    $quizzes = new QuizRepository($connection);
    $now = new DateTimeImmutable('2026-11-14T12:00:00+00:00');
    $service = new QuizService($quizzes, static function () use (&$now): DateTimeImmutable { return $now; });
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT quiz_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT quiz_repository_test');
            $connection->exec('RELEASE SAVEPOINT quiz_repository_test');
        }
    };

    $run('Odczyt zestawu na potrzeby błędu formularza nie tworzy nowego ID', static function () use ($quizzes, $service, $fixture, $connection, $assert): void {
        $assert($service->availableQuiz() === null);
        $assert((int) $connection->query('SELECT count(*) FROM daily_quizzes')->fetchColumn() === 0);
        $quiz = $quizzes->createForDate('2026-11-14', $fixture);
        $assert($service->availableQuiz() === $quiz);
        $assert((int) $connection->query('SELECT count(*) FROM daily_quizzes')->fetchColumn() === 1);
    });

    $run('Zestaw jest wspólny i nie zmienia się po ponownym utworzeniu daty', static function () use ($quizzes, $fixture, $assert): void {
        $quiz = $quizzes->createForDate('2026-11-14', $fixture);
        $changed = $fixture;
        $changed[0]['question'] = 'Późniejsza edycja puli';
        $assert($quiz === $quizzes->findForDate('2026-11-14') && $quiz === $quizzes->findById($quiz['id']));
        $assert($quizzes->createForDate('2026-11-14', $changed) === $quiz);
        $assert($quizzes->findForDate('2026-11-15') === null && $quizzes->findById(PHP_INT_MAX) === null);
    });

    $run('Zapis jest przypisany do konta i respektuje otwartą transakcję żądania', static function () use ($quizzes, $service, $fixture, $answers, $connection, $assert): void {
        $quiz = $quizzes->createForDate('2026-11-14', $fixture);
        $assert($service->resultForUser(101, $quiz['id']) === null);
        $result = $service->submit(101, $quiz['id'], $answers);
        $assert($result['score'] === 5 && $result['total'] === 5 && $result['answers'] === $answers);
        $assert($service->resultForUser(101, $quiz['id']) === $result);
        $assert($service->resultForUser(202, $quiz['id']) === null);
        $assert($service->statistics(101) === ['currentStreak' => 1, 'bestStreak' => 1, 'completedToday' => true]);
        $assert($service->statistics(202) === ['currentStreak' => 0, 'bestStreak' => 0, 'completedToday' => false]);
        $assert($connection->inTransaction(), 'Repozytorium nie może zamykać transakcji AccountAccessGuard.');
    });

    $run('Powtórny POST zachowuje pierwotny wynik i datę ukończenia', static function () use ($quizzes, $service, $fixture, $answers, $connection, $assert): void {
        $quiz = $quizzes->createForDate('2026-11-14', $fixture);
        $first = $service->submit(101, $quiz['id'], $answers);
        $assert($service->submit(101, $quiz['id'], [999 => 'zmanipulowane']) === $first);
        $assert((int) $connection->query('SELECT count(*) FROM quiz_attempts')->fetchColumn() === 1);
        $second = $quizzes->saveResult(101, $quiz['id'], 0, array_fill(1, 5, 1), new DateTimeImmutable('2026-11-14T16:00:00Z'));
        $assert($second === $first, 'Również konflikt na poziomie repozytorium musi zachować oryginał.');
    });

    $run('Wynik 0/5 podtrzymuje serię, a samo odczytanie pytań nie zalicza udziału', static function () use ($quizzes, $service, $fixture, $assert): void {
        $quiz = $quizzes->createForDate('2026-11-14', $fixture);
        $assert($service->dailyQuiz() === $quiz && !$service->statistics(101)['completedToday']);
        $yesterday = $quizzes->createForDate('2026-11-13', $fixture);
        $quizzes->saveResult(101, $yesterday['id'], 0, array_fill(1, 5, 1), new DateTimeImmutable('2026-11-13T12:00:00Z'));
        $result = $service->submit(101, $quiz['id'], array_fill(1, 5, 1));
        $assert($result['score'] === 0 && $service->statistics(101)['currentStreak'] === 2);
    });

    $run('Niepoprawne odpowiedzi nie zapisują częściowego udziału', static function () use ($quizzes, $service, $fixture, $connection, $assert): void {
        $quiz = $quizzes->createForDate('2026-11-14', $fixture);
        $rejected = false;
        try {
            $service->submit(101, $quiz['id'], [1 => 0]);
        } catch (InvalidArgumentException $exception) {
            $rejected = $exception->getMessage() === 'quiz.error.answers';
        }
        $assert($rejected && (int) $connection->query('SELECT count(*) FROM quiz_attempts')->fetchColumn() === 0);
    });

    $run('Warszawska północ wygasza formularz bez tworzenia zestawu w odpowiedzi błędu', static function () use ($quizzes, $service, $fixture, $answers, $connection, &$now, $assert): void {
        $now = new DateTimeImmutable('2026-11-14T22:59:59Z');
        $quiz = $quizzes->createForDate('2026-11-14', $fixture);
        $assert($service->dailyQuiz()['date'] === '2026-11-14');
        $now = new DateTimeImmutable('2026-11-14T23:00:00Z');
        $assert($service->availableQuiz() === null, 'Po północy odczyt nie może używać wczorajszego zestawu.');
        $rejected = false;
        try {
            $service->submit(101, $quiz['id'], $answers);
        } catch (InvalidArgumentException $exception) {
            $rejected = $exception->getMessage() === 'quiz.error.expired';
        }
        $assert($rejected && $service->availableQuiz() === null);
        $assert((int) $connection->query('SELECT count(*) FROM daily_quizzes')->fetchColumn() === 1,
            'Odpowiedź błędu musi pozostać bez nowego, wycofywanego ID formularza.');
        $next = $service->dailyQuiz();
        $assert($next['date'] === '2026-11-15' && $service->availableQuiz() === $next,
            'Dopiero kolejne GET tworzy trwały zestaw nowego dnia.');
        $now = new DateTimeImmutable('2026-11-14T12:00:00Z');
    });

    $run('Nieistniejący, przyszły i cudzy zestaw daty nie zapisują wyniku', static function () use ($quizzes, $service, $fixture, $answers, $assert): void {
        $future = $quizzes->createForDate('2026-11-15', $fixture);
        foreach ([$future['id'], PHP_INT_MAX, 0, -1] as $quizId) {
            $rejected = false;
            try {
                $service->submit(101, $quizId, $answers);
            } catch (InvalidArgumentException $exception) {
                $rejected = $exception->getMessage() === 'quiz.error.expired';
            }
            $assert($rejected);
        }
    });

    $run('Niepoprawne ID konta jest odrzucane przez usługę', static function () use ($service, $answers, $assert): void {
        foreach ([0, -1] as $userId) {
            $rejected = false;
            try {
                $service->submit($userId, 1, $answers);
            } catch (InvalidArgumentException $exception) {
                $rejected = $exception->getMessage() === 'quiz.error.user';
            }
            $assert($rejected);
        }
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie testów quizu: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

// Concurrent first visits and duplicate submissions must keep the first committed row.
$schema = 'quiz_concurrency_' . bin2hex(random_bytes(8));
$created = false;
$workers = [];
try {
    $connection = (new Database())->connect();
    $connection->exec('CREATE SCHEMA "' . $schema . '"');
    $created = true;
    $connection->exec('COMMENT ON SCHEMA "' . $schema . '" IS \'AniVibe isolated quiz concurrency test\'');
    $connection->exec('SET search_path TO "' . $schema . '", public');
    foreach (['users', 'daily_quizzes', 'quiz_attempts'] as $table) {
        $connection->exec('CREATE TABLE "' . $schema . '".' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL)');
    }
    $connection->exec('ALTER TABLE users ALTER COLUMN id DROP DEFAULT');
    $connection->exec("INSERT INTO users (id, name, email, password_hash) VALUES (101, 'Test wyścigu', 'quiz-race@example.test', 'hash')");
    $repository = new QuizRepository($connection);
    $launch = static function (string $operation, int $quizId = 0) use ($schema, &$workers, $assert): array {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $schema, $operation, (string) $quizId], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__));
        $assert(is_resource($process), 'Nie udało się uruchomić procesu testowego.');
        stream_set_timeout($pipes[1], 10);
        $worker = ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        $workers[] = $worker;
        $line = fgets($pipes[1]);
        $assert(is_string($line), 'Proces testowy nie zgłosił gotowości.');
        $ready = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $worker['pid'] = $ready['pid'];
        fwrite($worker['stdin'], "start\n");
        fclose($worker['stdin']);

        return $worker;
    };
    $awaitBlocked = static function (array $worker) use ($connection, $assert): void {
        $lookup = $connection->prepare("SELECT wait_event_type = 'Lock' FROM pg_stat_activity WHERE pid = :pid");
        $deadline = microtime(true) + 3;
        do {
            $lookup->execute(['pid' => $worker['pid']]);
            if ($lookup->fetchColumn() === true) {
                return;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $assert(false, 'Konkurencyjny zapis powinien czekać na unikalny wiersz pierwszej transakcji.');
    };
    $result = static function (array $worker) use ($assert): array {
        $line = fgets($worker['stdout']);
        $error = stream_get_contents($worker['stderr']);
        $assert(is_string($line) && $error === '', 'Niepoprawny wynik procesu testowego: ' . $error);
        $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $assert(proc_close($worker['process']) === 0, 'Proces testowy zakończył się błędem.');

        return $value;
    };

    $connection->beginTransaction();
    $quiz = $repository->createForDate('2026-11-14', $fixture);
    $worker = $launch('daily');
    $awaitBlocked($worker);
    $connection->commit();
    $assert($result($worker) === $quiz, 'Dwa połączenia muszą zobaczyć dokładnie ten sam zestaw dnia.');
    $assert((int) $connection->query('SELECT count(*) FROM daily_quizzes')->fetchColumn() === 1);
    $passed++;
    echo '[OK] Dwa równoległe połączenia zachowują jeden wspólny zestaw dnia' . PHP_EOL;

    $connection->beginTransaction();
    $first = $repository->saveResult(101, $quiz['id'], 5, $answers, new DateTimeImmutable('2026-11-14T12:00:00Z'));
    $worker = $launch('result', $quiz['id']);
    $awaitBlocked($worker);
    $connection->commit();
    $assert($result($worker) === $first, 'Konkurencyjna odpowiedź nie może nadpisać pierwszego wyniku.');
    $assert((int) $connection->query('SELECT count(*) FROM quiz_attempts')->fetchColumn() === 1);
    $passed++;
    echo '[OK] Dwa równoległe połączenia zapisują tylko jeden niezmienny wynik' . PHP_EOL;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Równoległość quizu: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    foreach ($workers as $worker) {
        foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
            if (is_resource($worker[$pipe])) {
                fclose($worker[$pipe]);
            }
        }
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }
    if ($created && $connection instanceof PDO) {
        $owner = $connection->prepare("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = :schema");
        $owner->execute(['schema' => $schema]);
        if ($owner->fetchColumn() === 'AniVibe isolated quiz concurrency test'
            && preg_match('/\Aquiz_concurrency_[a-f0-9]{16}\z/', $schema) === 1) {
            $connection->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
