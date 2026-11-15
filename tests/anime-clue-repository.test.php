<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AnimeClueRepository;
use App\Service\AnimeClueService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) throw new RuntimeException($message);
};
$fixture = [];
for ($index = 1; $index <= 5; $index++) {
    $fixture[] = ['id' => $index, 'title' => 'Anime ' . $index, 'aliases' => ['Alias ' . $index], 'franchise' => 'anime-' . $index,
        'sourceUrl' => 'https://example.test/' . $index,
        'clues' => ['pl' => ['Pierwsza ' . $index, 'Druga ' . $index, 'Trzecia ' . $index],
            'en' => ['First ' . $index, 'Second ' . $index, 'Third ' . $index]]];
}

if (($argv[1] ?? '') === 'worker') {
    try {
        $schema = $argv[2] ?? '';
        $operation = $argv[3] ?? '';
        $gameId = (int) ($argv[4] ?? 0);
        $assert(preg_match('/\Aanime_clue_race_[a-f0-9]{16}\z/', $schema) === 1);
        $db = (new Database())->connect();
        $owner = $db->prepare("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = :schema");
        $owner->execute(['schema' => $schema]);
        $assert($owner->fetchColumn() === 'AniVibe isolated anime clue test');
        $db->exec('SET search_path TO "' . $schema . '", public');
        $db->exec("SET lock_timeout = '5s'; SET statement_timeout = '8s'");
        $repo = new AnimeClueRepository($db);
        $service = new AnimeClueService($repo, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-15T12:00:00Z'));
        echo json_encode(['pid' => (int) $db->query('SELECT pg_backend_pid()')->fetchColumn()], JSON_THROW_ON_ERROR) . PHP_EOL;
        flush();
        $assert(trim((string) fgets(STDIN)) === 'start');
        if ($operation === 'daily') {
            $fixture[0]['title'] = 'Conflicting title';
            $result = $repo->createForDate('2026-11-15', $fixture);
        } elseif ($operation === 'reveal') {
            $service->reveal(101, $gameId, 0, 1);
            $result = $service->stateForUser(101);
        } elseif ($operation === 'answer') {
            $service->answer(101, $gameId, 0, 'wrong anime', 2);
            $result = $service->stateForUser(101);
        } elseif ($operation === 'wrong') {
            $service->answer(101, $gameId, 1, 'second concurrent wrong', 1);
            $result = $service->stateForUser(101);
        } else {
            throw new RuntimeException('Unknown worker operation.');
        }
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

$db = null;
try {
    $db = (new Database())->connect();
    $db->beginTransaction();
    foreach (['users', 'daily_anime_clues', 'anime_clue_attempts', 'anime_clue_answers'] as $table) {
        $db->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $verify = $db->prepare("SELECT relnamespace = pg_my_temp_schema() FROM pg_class WHERE oid = to_regclass(:table)");
        $verify->execute(['table' => $table]);
        $assert($verify->fetchColumn() === true, 'Test must use temporary tables.');
    }
    $db->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $db->exec('ALTER TABLE pg_temp.anime_clue_attempts ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
        ADD FOREIGN KEY (game_id) REFERENCES pg_temp.daily_anime_clues (id)');
    $db->exec('ALTER TABLE pg_temp.anime_clue_answers ADD FOREIGN KEY (user_id, game_id) REFERENCES pg_temp.anime_clue_attempts (user_id, game_id) ON DELETE CASCADE');
    $db->exec("INSERT INTO users (id,name,email,password_hash) VALUES (101,'First','clue-first@example.test','hash'), (202,'Second','clue-second@example.test','hash')");
    $repo = new AnimeClueRepository($db);
    $now = new DateTimeImmutable('2026-11-15T12:00:00Z');
    $service = new AnimeClueService($repo, static function () use (&$now): DateTimeImmutable { return $now; });
    $run = static function (string $name, callable $operation) use ($db, &$passed, &$failed): void {
        $db->exec('SAVEPOINT clue_test');
        try {
            $operation();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $db->exec('ROLLBACK TO SAVEPOINT clue_test');
            $db->exec('RELEASE SAVEPOINT clue_test');
        }
    };

    $run('Wspólny snapshot jest niezmienny, a GET nie tworzy udziału użytkownika', static function () use ($repo, $service, $fixture, $db, $assert): void {
        $assert($service->stateForUser(101, createDay: false) === null);
        $game = $repo->createForDate('2026-11-15', $fixture);
        $modified = $fixture;
        $modified[0]['title'] = 'Modified';
        $assert($repo->createForDate('2026-11-15', $modified) === $game);
        $first = $service->stateForUser(101);
        $second = $service->stateForUser(202);
        $assert($first === $second && $first['clues'] === ['Pierwsza 1']);
        $assert((int) $db->query('SELECT count(*) FROM anime_clue_attempts')->fetchColumn() === 0);
    });

    $run('Odkrywanie jest trwałe i powtórny formularz nie odkrywa dwóch wskazówek', static function () use ($repo, $service, $fixture, $assert): void {
        $game = $repo->createForDate('2026-11-15', $fixture);
        $service->reveal(101, $game['id'], 0, 1);
        $service->reveal(101, $game['id'], 0, 1);
        $assert($service->stateForUser(101)['hintsUsed'] === 2);
        $assert($service->stateForUser(202)['hintsUsed'] === 1);
        $service->reveal(101, $game['id'], 0, 2);
        $service->reveal(101, $game['id'], 0, 3);
        $assert($service->stateForUser(101)['clues'] === ['Pierwsza 1', 'Druga 1', 'Trzecia 1']);
    });

    $run('Pierwsza odpowiedź wygrywa, a opóźnione reveal i skip nie dotykają następnej rundy', static function () use ($repo, $service, $fixture, $assert): void {
        $game = $repo->createForDate('2026-11-15', $fixture);
        $service->answer(101, $game['id'], 0, '  ALIAS-1! ', 1);
        $service->answer(101, $game['id'], 0, 'wrong', 1);
        $service->reveal(101, $game['id'], 0, 1);
        $service->skip(101, $game['id'], 0);
        $state = $service->stateForUser(101);
        $assert($state['roundIndex'] === 1 && $state['hintsUsed'] === 1 && $state['score'] === 3 && $state['correctCount'] === 1);
        $assert(count($state['results']) === 1 && $state['results'][0]['answer'] === 'ALIAS-1!');
    });

    $run('Pięć rund sumuje 3/2/1, trzy błędne odpowiedzi i pominięcie', static function () use ($repo, $service, $fixture, $assert): void {
        $game = $repo->createForDate('2026-11-15', $fixture);
        $service->answer(101, $game['id'], 0, 'Anime 1', 1);
        $service->reveal(101, $game['id'], 1, 1);
        $service->answer(101, $game['id'], 1, 'Anime 2', 2);
        $service->reveal(101, $game['id'], 2, 1);
        $service->reveal(101, $game['id'], 2, 2);
        $service->answer(101, $game['id'], 2, 'Anime 3', 3);
        $service->answer(101, $game['id'], 3, 'wrong one', 1);
        $service->answer(101, $game['id'], 3, 'wrong two', 2);
        $service->answer(101, $game['id'], 3, 'wrong three', 3);
        $service->answer(101, $game['id'], 3, 'Anime 4', 3);
        $service->skip(101, $game['id'], 4);
        $state = $service->stateForUser(101);
        $assert($state['completed'] && $state['roundIndex'] === 5 && $state['clues'] === [] && $state['score'] === 6 && $state['correctCount'] === 3);
        $assert(array_column($state['results'], 'points') === [3, 2, 1, 0, 0]);
        $assert($state['results'][4]['skipped'] && !$state['results'][3]['correct']);
        $assert($service->statistics(101) === ['points' => 6, 'correct' => 3, 'answered' => 5, 'completedDays' => 1, 'currentStreak' => 1, 'bestStreak' => 1]);
        $assert($service->statistics(202)['answered'] === 0);
    });

    $run('Błędne odpowiedzi odsłaniają wskazówki, zachowują historię i pozwalają zdobyć 2 lub 1 punkt', static function () use ($repo, $service, $fixture, $assert): void {
        $game = $repo->createForDate('2026-11-15', $fixture);
        $service->answer(101, $game['id'], 0, 'Wrong first', 1);
        $state = $service->stateForUser(101);
        $assert($state['roundIndex'] === 0 && $state['hintsUsed'] === 2 && $state['score'] === 0 && $state['results'] === []);
        $assert($state['wrongAnswers'] === [['answer' => 'Wrong first', 'hintsUsed' => 1]]);
        $assert(!str_contains(json_encode($state, JSON_THROW_ON_ERROR), 'Anime 1'), 'Active title remains hidden after a wrong guess.');
        $service->answer(101, $game['id'], 0, 'Another old guess', 1);
        $service->answer(101, $game['id'], 0, [], 1);
        $assert($service->stateForUser(101) === $state, 'Stale guesses neither consume chances nor validate stale fields.');
        $assert($service->statistics(101)['answered'] === 0 && $service->statistics(101)['points'] === 0);
        $assert($service->stateForUser(202)['wrongAnswers'] === []);
        $service->answer(101, $game['id'], 0, 'Alias 1', 2);
        $state = $service->stateForUser(101);
        $assert($state['score'] === 2 && $state['roundIndex'] === 1 && $state['wrongAnswers'] === []);
        $assert($state['results'][0]['wrongAnswers'] === [['answer' => 'Wrong first', 'hintsUsed' => 1]]);
        $service->answer(101, $game['id'], 1, 'Wrong A', 1);
        $service->answer(101, $game['id'], 1, 'Wrong B', 2);
        $service->answer(101, $game['id'], 1, 'Alias 2', 3);
        $state = $service->stateForUser(101);
        $assert($state['score'] === 3 && $state['correctCount'] === 2 && count($state['results'][1]['wrongAnswers']) === 2);
        $assert($service->statistics(101)['answered'] === 2, 'Statistics count resolved rounds, not guesses.');
    });

    $run('Pominięcie po błędzie zamyka rundę i zachowuje podaną odpowiedź', static function () use ($repo, $service, $fixture, $assert): void {
        $game = $repo->createForDate('2026-11-15', $fixture);
        $service->answer(101, $game['id'], 0, 'Wrong before skip', 1);
        $service->skip(101, $game['id'], 0);
        $state = $service->stateForUser(101);
        $assert($state['roundIndex'] === 1 && $state['score'] === 0 && $state['results'][0]['skipped']);
        $assert($state['results'][0]['wrongAnswers'] === [['answer' => 'Wrong before skip', 'hintsUsed' => 1]]);
    });

    $run('Niepoprawna odpowiedź, przyszła runda i fałszywy dzień nie zużywają próby', static function () use ($repo, $service, $fixture, $db, $assert): void {
        $game = $repo->createForDate('2026-11-15', $fixture);
        foreach (['', [], str_repeat('a', 201)] as $answer) {
            $rejected = false;
            try { $service->answer(101, $game['id'], 0, $answer, 1); } catch (InvalidArgumentException $exception) { $rejected = $exception->getMessage() === 'guess.error.answer'; }
            $assert($rejected);
        }
        foreach ([0, 4] as $hints) {
            $rejected = false;
            try { $service->answer(101, $game['id'], 0, 'Anime 1', $hints); } catch (InvalidArgumentException $exception) { $rejected = $exception->getMessage() === 'guess.error.hints'; }
            $assert($rejected);
        }
        $rejected = false;
        try { $service->skip(101, $game['id'], 2); } catch (InvalidArgumentException $exception) { $rejected = $exception->getMessage() === 'guess.error.round'; }
        $assert($rejected);
        $assert((int) $db->query('SELECT count(*) FROM anime_clue_attempts')->fetchColumn() === 0, 'Failed commands roll back even their initial attempt row.');
        $assert((int) $db->query('SELECT count(*) FROM anime_clue_answers')->fetchColumn() === 0);
        foreach ([0, -1, PHP_INT_MAX] as $gameId) {
            $rejected = false;
            try { $service->skip(101, $gameId, 0); } catch (InvalidArgumentException $exception) { $rejected = $exception->getMessage() === 'guess.error.expired'; }
            $assert($rejected);
        }
    });

    $run('Północ warszawska wygasza stary formularz bez tworzenia nowego zestawu', static function () use ($repo, $service, $fixture, &$now, $db, $assert): void {
        $now = new DateTimeImmutable('2026-11-15T22:59:59Z');
        $game = $repo->createForDate('2026-11-15', $fixture);
        $service->answer(101, $game['id'], 0, 'Anime 1', 1);
        $now = new DateTimeImmutable('2026-11-15T23:00:00Z');
        $rejected = false;
        try { $service->answer(101, $game['id'], 1, 'Anime 2', 1); } catch (InvalidArgumentException $exception) { $rejected = $exception->getMessage() === 'guess.error.expired'; }
        $assert($rejected && $service->stateForUser(101, createDay: false) === null);
        $assert((int) $db->query('SELECT count(*) FROM daily_anime_clues')->fetchColumn() === 1);
        $stats = $service->statistics(101);
        $assert($stats['points'] === 3 && $stats['answered'] === 1 && $stats['completedDays'] === 0 && $stats['currentStreak'] === 0);
        $now = new DateTimeImmutable('2026-11-15T12:00:00Z');
    });

    $run('Ukończenia w dniach zmiany czasu podtrzymują serię kalendarzową', static function () use ($repo, $fixture, $db, $assert): void {
        foreach (['2026-10-24', '2026-10-25', '2026-10-26'] as $date) {
            $game = $repo->createForDate($date, $fixture);
            $service = new AnimeClueService($repo, static fn (): DateTimeImmutable => new DateTimeImmutable($date . 'T12:00:00Z'));
            for ($round = 0; $round < 5; $round++) $service->skip(101, $game['id'], $round);
        }
        $service = new AnimeClueService($repo, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-26T12:00:00Z'));
        $assert($service->statistics(101)['currentStreak'] === 3 && $service->statistics(101)['completedDays'] === 3);
        $assert($db->inTransaction(), 'Service never commits the request transaction.');
    });

    $run('Historia zużycia preferuje ostatnią datę tylko z przeszłych zestawów', static function () use ($repo, $fixture, $assert): void {
        $repo->createForDate('2026-11-13', $fixture);
        $repo->createForDate('2026-11-14', $fixture);
        $repo->createForDate('2026-11-16', $fixture);
        $assert($repo->usageBeforeDate('2026-11-15') === array_fill_keys(range(1, 5), '2026-11-14'));
    });

    $run('Zewnętrzny rollback cofa odpowiedzi, punkty i postęp wskazówek razem', static function () use ($repo, $service, $fixture, $db, $assert): void {
        $game = $repo->createForDate('2026-11-15', $fixture);
        $db->exec('SAVEPOINT outer_request');
        $service->reveal(101, $game['id'], 0, 1);
        $service->answer(101, $game['id'], 0, 'Anime 1', 2);
        $assert($repo->attemptForUser(101, $game['id'])['score'] === 2);
        $db->exec('ROLLBACK TO SAVEPOINT outer_request');
        $db->exec('RELEASE SAVEPOINT outer_request');
        $assert($repo->attemptForUser(101, $game['id']) === null && $repo->answersForUser(101, $game['id']) === []);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
}

// Real concurrent connections exercise unique dates and serialized per-account progress.
$schema = 'anime_clue_race_' . bin2hex(random_bytes(8));
$created = false;
$workers = [];
try {
    $db = (new Database())->connect();
    $db->exec('CREATE SCHEMA "' . $schema . '"');
    $created = true;
    $db->exec('COMMENT ON SCHEMA "' . $schema . '" IS \'AniVibe isolated anime clue test\'');
    $db->exec('SET search_path TO "' . $schema . '", public');
    foreach (['users', 'daily_anime_clues', 'anime_clue_attempts', 'anime_clue_answers'] as $table) {
        $db->exec('CREATE TABLE "' . $schema . '".' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL)');
    }
    $db->exec('ALTER TABLE users ALTER COLUMN id DROP DEFAULT');
    $db->exec("INSERT INTO users (id,name,email,password_hash) VALUES (101,'Race','clue-race@example.test','hash')");
    $repo = new AnimeClueRepository($db);
    $service = new AnimeClueService($repo, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-15T12:00:00Z'));
    $launch = static function (string $operation, int $gameId = 0) use ($schema, &$workers, $assert): array {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $schema, $operation, (string) $gameId], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__));
        $assert(is_resource($process));
        stream_set_timeout($pipes[1], 10);
        $worker = ['process' => $process, 'stdin' => $pipes[0], 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
        $workers[] = $worker;
        $ready = fgets($pipes[1]);
        $assert(is_string($ready), 'Worker did not become ready.');
        $worker['pid'] = json_decode($ready, true, 512, JSON_THROW_ON_ERROR)['pid'];
        fwrite($worker['stdin'], "start\n");
        fclose($worker['stdin']);
        return $worker;
    };
    $blocked = static function (array $worker) use ($db, $assert): void {
        $query = $db->prepare("SELECT wait_event_type = 'Lock' FROM pg_stat_activity WHERE pid = :pid");
        $deadline = microtime(true) + 3;
        do {
            $query->execute(['pid' => $worker['pid']]);
            if ($query->fetchColumn() === true) return;
            usleep(20000);
        } while (microtime(true) < $deadline);
        $assert(false, 'Worker must wait on the concurrent transaction.');
    };
    $result = static function (array $worker) use ($assert): array {
        $line = fgets($worker['stdout']);
        $error = stream_get_contents($worker['stderr']);
        $assert(is_string($line) && $error === '', 'Worker failed: ' . $error);
        fclose($worker['stdout']);
        fclose($worker['stderr']);
        $assert(proc_close($worker['process']) === 0);
        return json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    };

    $db->beginTransaction();
    $game = $repo->createForDate('2026-11-15', $fixture);
    $worker = $launch('daily');
    $blocked($worker);
    $db->commit();
    $assert($result($worker) === $game);
    $passed++;
    echo '[OK] Dwa pierwsze wejścia zachowują jeden wspólny snapshot' . PHP_EOL;

    $db->beginTransaction();
    $service->reveal(101, $game['id'], 0, 1);
    $worker = $launch('reveal', $game['id']);
    $blocked($worker);
    $db->commit();
    $assert($result($worker)['hintsUsed'] === 2, 'Duplicate reveal cannot uncover hint three.');
    $passed++;
    echo '[OK] Dwa równoległe odkrycia tego samego formularza odkrywają tylko jedną wskazówkę' . PHP_EOL;

    $db->beginTransaction();
    $service->answer(101, $game['id'], 0, 'Anime 1', 2);
    $worker = $launch('answer', $game['id']);
    $blocked($worker);
    $db->commit();
    $state = $result($worker);
    $assert($state['score'] === 2 && $state['roundIndex'] === 1 && $state['results'][0]['answer'] === 'Anime 1');
    $assert((int) $db->query('SELECT count(*) FROM anime_clue_answers')->fetchColumn() === 1);
    $passed++;
    echo '[OK] Dwie równoległe odpowiedzi zachowują pierwszy wynik i nie zużywają kolejnej rundy' . PHP_EOL;

    $db->beginTransaction();
    $service->answer(101, $game['id'], 1, 'first concurrent wrong', 1);
    $worker = $launch('wrong', $game['id']);
    $blocked($worker);
    $db->commit();
    $state = $result($worker);
    $assert($state['roundIndex'] === 1 && $state['hintsUsed'] === 2 && $state['score'] === 2);
    $assert($state['wrongAnswers'] === [['answer' => 'first concurrent wrong', 'hintsUsed' => 1]]);
    $assert((int) $db->query('SELECT count(*) FROM anime_clue_answers')->fetchColumn() === 1);
    $passed++;
    echo '[OK] Równoległe błędne odpowiedzi zużywają jedną szansę i zapisują jeden błąd' . PHP_EOL;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Współbieżność: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    foreach ($workers as $worker) {
        foreach (['stdin','stdout','stderr'] as $pipe) if (is_resource($worker[$pipe])) fclose($worker[$pipe]);
        if (is_resource($worker['process'])) { proc_terminate($worker['process']); proc_close($worker['process']); }
    }
    if ($created && $db instanceof PDO) {
        $owner = $db->prepare("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = :schema");
        $owner->execute(['schema' => $schema]);
        if ($owner->fetchColumn() === 'AniVibe isolated anime clue test' && preg_match('/\Aanime_clue_race_[a-f0-9]{16}\z/', $schema) === 1) {
            $db->exec('DROP SCHEMA "' . $schema . '" CASCADE');
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
