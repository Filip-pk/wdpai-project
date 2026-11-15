<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AnimeClueRepository;
use App\Service\AnimeClueService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$db = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $db = (new Database())->connect();
    $db->beginTransaction();
    foreach (['users', 'daily_anime_clues', 'anime_clue_attempts'] as $table) {
        $db->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $check = $db->prepare("SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
            AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'");
        $check->execute(['table' => $table]);
        $assert((int) $check->fetchColumn() === 1, 'Wymagane własne tabele tymczasowe.');
    }
    $db->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $db->exec('ALTER TABLE pg_temp.anime_clue_attempts
        ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users(id),
        ADD FOREIGN KEY (game_id) REFERENCES pg_temp.daily_anime_clues(id)');
    $db->exec("INSERT INTO pg_temp.users (id, name, email, password_hash, avatar_version, public_statistics)
        VALUES (101, 'Ranking <svg onload=alert(1)>', 'clue-ranking-a@example.test', 'hash', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', TRUE),
               (202, 'Ranking B', 'clue-ranking-b@example.test', 'hash', 'invalid', TRUE),
               (303, 'Ranking C', 'clue-ranking-c@example.test', 'hash', NULL, FALSE),
               (404, 'Bez rund', 'clue-ranking-empty@example.test', 'hash', NULL, TRUE)");
    $repository = new AnimeClueRepository($db);
    $entries = array_fill(0, 5, ['id' => 1, 'title' => 'Private fixture title']);
    $add = static function (int $userId, string $date, int $points, int $answered, int $correct, ?string $completedAt = null) use ($db, $repository, $entries): void {
        $game = $repository->createForDate($date, $entries);
        $insert = $db->prepare('INSERT INTO anime_clue_attempts (user_id, game_id, score, round_index, correct_count, completed_at)
            VALUES (:user, :game, :points, :answered, :correct, :completed)');
        $insert->execute(['user' => $userId, 'game' => $game['id'], 'points' => $points, 'answered' => $answered,
            'correct' => $correct, 'completed' => $answered === 5 ? ($completedAt ?? $date . 'T12:00:00Z') : null]);
    };
    $run = static function (string $name, callable $test) use ($db, &$passed, &$failed): void {
        $db->exec('SAVEPOINT clue_ranking_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $db->exec('ROLLBACK TO SAVEPOINT clue_ranking_test');
            $db->exec('RELEASE SAVEPOINT clue_ranking_test');
        }
    };

    $run('Pusty ranking nie tworzy udziału ani rozgrywki', static function () use ($repository, $db, $assert): void {
        $assert($repository->weeklyRankingForUser(101, '2026-11-09', '2026-11-16') === ['leaders' => [], 'ownRanking' => null]);
        $assert((int) $db->query('SELECT count(*) FROM anime_clue_attempts')->fetchColumn() === 0);
        $assert($db->inTransaction(), 'Odczyt nie może zatwierdzić transakcji żądania.');
    });

    $run('Punkty obejmują częściowe i pełne dni, lecz nie samo odkrycie podpowiedzi', static function () use ($repository, $add, $assert): void {
        $add(101, '2026-11-09', 3, 1, 1);
        $add(101, '2026-11-10', 4, 3, 2);
        $add(101, '2026-11-11', 15, 5, 5);
        $add(202, '2026-11-09', 0, 1, 0);
        $add(303, '2026-11-09', 0, 0, 0);
        $ranking = $repository->weeklyRankingForUser(101, '2026-11-09', '2026-11-16');
        $assert(array_column($ranking['leaders'], 'userId') === [101, 202]);
        $own = $ranking['ownRanking'];
        $assert($own['points'] === 22 && $own['correct'] === 8 && $own['answered'] === 9 && $own['completedDays'] === 1);
        $assert($ranking['leaders'][1]['points'] === 0 && $ranking['leaders'][1]['answered'] === 1);
        $assert($repository->weeklyRankingForUser(303, '2026-11-09', '2026-11-16')['ownRanking'] === null);
        $assert($repository->weeklyRankingForUser(404, '2026-11-09', '2026-11-16')['ownRanking'] === null);
    });

    $run('Remisy dzielą miejsce, a kolejność wierszy rozstrzyga identyfikator konta', static function () use ($repository, $add, $assert): void {
        $add(101, '2026-11-09', 3, 1, 1);
        $add(202, '2026-11-09', 1, 1, 1);
        $add(202, '2026-11-10', 2, 1, 1);
        $add(303, '2026-11-09', 0, 1, 0);
        $ranking = $repository->weeklyRankingForUser(202, '2026-11-09', '2026-11-16');
        $assert(array_column($ranking['leaders'], 'userId') === [101, 202, 303]);
        $assert(array_column($ranking['leaders'], 'rank') === [1, 1, 3]);
        $assert($ranking['ownRanking'] === $ranking['leaders'][1]);
    });

    $run('Granice tygodnia filtrują datę zestawu, niezależnie od znacznika ukończenia UTC', static function () use ($repository, $add, $assert): void {
        $add(101, '2026-11-08', 15, 5, 5, '2026-11-09T00:01:00+01:00');
        $add(101, '2026-11-09', 9, 5, 3, '2026-11-08T23:05:00Z');
        $add(101, '2026-11-15', 12, 5, 4, '2026-11-15T22:59:00Z');
        $add(101, '2026-11-16', 15, 5, 5, '2026-11-15T23:05:00Z');
        $row = $repository->weeklyRankingForUser(101, '2026-11-09', '2026-11-16')['ownRanking'];
        $assert($row['points'] === 21 && $row['answered'] === 10 && $row['completedDays'] === 2);
    });

    $run('Bieżący ranking wyklucza przyszłe dni wewnątrz tygodnia', static function () use ($repository, $add, $assert): void {
        $add(101, '2026-11-09', 3, 1, 1);
        $add(101, '2026-11-11', 2, 1, 1);
        $add(101, '2026-11-12', 15, 5, 5);
        $row = $repository->weeklyRankingForUser(101, '2026-11-09', '2026-11-16', '2026-11-11')['ownRanking'];
        $assert($row['points'] === 5 && $row['answered'] === 2 && $row['completedDays'] === 0);
    });

    $run('TOP 100 nie jest rozszerzany przez własną pozycję poza pierwszą setką', static function () use ($db, $repository, $add, $assert): void {
        $db->exec("INSERT INTO pg_temp.users (id, name, email, password_hash)
            SELECT id, 'Ranking fixture ' || id, 'clue-rank-' || id || '@example.test', 'hash'
            FROM generate_series(1000, 1099) AS id");
        $db->exec("INSERT INTO pg_temp.users (id, name, email, password_hash) VALUES (2000, 'Poza TOP', 'clue-outside@example.test', 'hash')");
        for ($id = 1000; $id < 1100; $id++) {
            $add($id, '2026-11-09', 3, 1, 1);
        }
        $add(2000, '2026-11-09', 0, 1, 0);
        $ranking = $repository->weeklyRankingForUser(2000, '2026-11-09', '2026-11-16');
        $assert(count($ranking['leaders']) === 100);
        $assert($ranking['ownRanking']['userId'] === 2000 && $ranking['ownRanking']['rank'] === 101);
        $assert(!in_array(2000, array_column($ranking['leaders'], 'userId'), true));
        $assert(array_column($ranking['leaders'], 'userId') === range(1000, 1099));
        $assert(array_unique(array_column($ranking['leaders'], 'rank')) === [1]);
    });

    $run('Ranking ujawnia tylko publiczne dane i odrzuca niepoprawne wersje avatara', static function () use ($repository, $add, $assert): void {
        foreach ([101, 202, 303] as $userId) {
            $add($userId, '2026-11-09', 1, 1, 1);
        }
        $leaders = $repository->weeklyRankingForUser(101, '2026-11-09', '2026-11-16')['leaders'];
        $assert(array_keys($leaders[0]) === ['userId', 'name', 'avatarUrl', 'points', 'correct', 'answered', 'completedDays', 'rank']);
        $assert($leaders[0]['avatarUrl'] === '/avatars/101?v=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $assert($leaders[1]['avatarUrl'] === null && $leaders[2]['avatarUrl'] === null);
        $assert($leaders[0]['name'] === 'Ranking <svg onload=alert(1)>', 'Escaping należy do warstwy widoku.');
        $assert(count($leaders) === 3, 'Ustawienie statystyk profilu nie wyłącza publicznego rankingu.');
        $assert(!str_contains(json_encode($leaders, JSON_THROW_ON_ERROR), '@example.test'));
        $assert(!str_contains(json_encode($leaders, JSON_THROW_ON_ERROR), 'Private fixture title'));
    });

    $run('Serwis wyznacza poniedziałek i niedzielę w Warszawie przez północ, zmianę czasu i rok', static function () use ($repository, $assert): void {
        foreach ([
            ['2026-11-15T12:00:00Z', '2026-11-09', '2026-11-16'],
            ['2026-11-15T23:30:00Z', '2026-11-16', '2026-11-23'],
            ['2026-03-29T00:30:00Z', '2026-03-23', '2026-03-30'],
            ['2026-03-29T01:30:00Z', '2026-03-23', '2026-03-30'],
            ['2026-03-29T22:30:00Z', '2026-03-30', '2026-04-06'],
            ['2026-10-25T01:30:00Z', '2026-10-19', '2026-10-26'],
            ['2026-12-31T22:30:00Z', '2026-12-28', '2027-01-04'],
            ['2027-01-03T23:00:00Z', '2027-01-04', '2027-01-11'],
        ] as [$instant, $start, $end]) {
            $service = new AnimeClueService($repository, static fn (): DateTimeImmutable => new DateTimeImmutable($instant));
            $ranking = $service->weeklyRanking(101);
            $assert($ranking['weekStart'] === $start && $ranking['weekEnd'] === $end, $instant);
            $assert($ranking['weekLastDay'] === (new DateTimeImmutable($end))->modify('-1 day')->format('Y-m-d'));
        }
    });

    $run('Serwis stosuje dzisiejszą datę Warszawy jako granicę przyszłych wyników', static function () use ($repository, $add, $assert): void {
        $add(101, '2026-11-09', 3, 1, 1);
        $add(101, '2026-11-11', 15, 5, 5);
        $service = new AnimeClueService($repository, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-09T23:30:00Z'));
        $row = $service->weeklyRanking(101)['ownRanking'];
        $assert($row['points'] === 3 && $row['answered'] === 1);
    });

    $run('Niepoprawne konta i okresy są odrzucane przed zapytaniem', static function () use ($repository, $assert): void {
        foreach ([
            [0, '2026-11-09', '2026-11-16', null],
            [-1, '2026-11-09', '2026-11-16', null],
            [101, '', '2026-11-16', null],
            [101, '2026-02-30', '2026-11-16', null],
            [101, '2026-11-09', '2026-11-09', null],
            [101, '2026-11-16', '2026-11-09', null],
            [101, '2026-11-09', '2026-11-16', '2026-02-30'],
        ] as $arguments) {
            $rejected = false;
            try {
                $repository->weeklyRankingForUser(...$arguments);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $assert($rejected);
        }
    });
} finally {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
