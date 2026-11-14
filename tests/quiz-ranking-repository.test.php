<?php

declare(strict_types=1);

use App\Database;
use App\Repository\QuizRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$questions = [];
for ($id = 1; $id <= 5; $id++) {
    $questions[] = ['id' => $id, 'question' => 'Pytanie ' . $id, 'options' => ['A', 'B', 'C', 'D'],
        'correctOption' => 0, 'explanation' => 'Objaśnienie.'];
}

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach (['users', 'daily_quizzes', 'quiz_attempts'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $verify = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $verify->execute(['table' => $table]);
        $assert((int) $verify->fetchColumn() === 1, 'Test może zapisywać tylko własne tabele tymczasowe.');
    }
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $connection->exec(
        'ALTER TABLE pg_temp.quiz_attempts
             ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (quiz_id) REFERENCES pg_temp.daily_quizzes (id)'
    );
    $connection->exec(
        "INSERT INTO pg_temp.users (id, name, email, password_hash, avatar_version, public_statistics)
         VALUES (101, 'Quiz <svg onload=alert(1)>', 'quiz-ranking-a@example.test', 'hash', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', TRUE),
                (202, 'Quiz drugi', 'quiz-ranking-b@example.test', 'hash', 'invalid', TRUE),
                (303, 'Quiz trzeci', 'quiz-ranking-c@example.test', 'hash', NULL, FALSE),
                (404, 'Bez udziału', 'quiz-ranking-empty@example.test', 'hash', NULL, TRUE)"
    );
    $quizzes = new QuizRepository($connection);
    $timestamp = new DateTimeImmutable('2026-11-14T12:00:00Z');
    $answers = array_fill(1, 5, 0);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT quiz_ranking_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT quiz_ranking_test');
            $connection->exec('RELEASE SAVEPOINT quiz_ranking_test');
        }
    };

    $run('Konto bez ukończeń ma 0/0 i nie zajmuje miejsca w rankingu', static function () use ($quizzes, $assert): void {
        $assert($quizzes->totalsForUser(101) === ['correct' => 0, 'total' => 0, 'completedQuizzes' => 0]);
        $assert($quizzes->rankingForUser(101) === ['leaders' => [], 'ownRanking' => null]);
        $assert($quizzes->rankingForUser(999999) === ['leaders' => [], 'ownRanking' => null]);
    });

    $run('Łączny wynik sumuje poprawne odpowiedzi i wszystkie pytania tylko danego konta', static function () use ($quizzes, $questions, $timestamp, $answers, $assert): void {
        $first = $quizzes->createForDate('2026-11-13', $questions);
        $second = $quizzes->createForDate('2026-11-14', $questions);
        $quizzes->saveResult(101, $first['id'], 3, $answers, $timestamp);
        $quizzes->saveResult(101, $second['id'], 4, $answers, $timestamp);
        $quizzes->saveResult(202, $first['id'], 5, $answers, $timestamp);
        $assert($quizzes->totalsForUser(101) === ['correct' => 7, 'total' => 10, 'completedQuizzes' => 2]);
        $assert($quizzes->totalsForUser(202) === ['correct' => 5, 'total' => 5, 'completedQuizzes' => 1]);
        $assert($quizzes->totalsForUser(404) === ['correct' => 0, 'total' => 0, 'completedQuizzes' => 0]);
    });

    $run('Powtórny zapis nie zwiększa licznika poprawnych odpowiedzi ani udziałów', static function () use ($quizzes, $questions, $timestamp, $answers, $assert): void {
        $quiz = $quizzes->createForDate('2026-11-14', $questions);
        $quizzes->saveResult(101, $quiz['id'], 5, $answers, $timestamp);
        $quizzes->saveResult(101, $quiz['id'], 0, array_fill(1, 5, 1), $timestamp);
        $assert($quizzes->totalsForUser(101) === ['correct' => 5, 'total' => 5, 'completedQuizzes' => 1]);
        $assert($quizzes->rankingForUser(101)['ownRanking']['correct'] === 5);
    });

    $run('Ranking sortuje sumę poprawnych odpowiedzi, dzieli remisy i uwzględnia 0/5', static function () use ($quizzes, $questions, $timestamp, $answers, $assert): void {
        $first = $quizzes->createForDate('2026-11-13', $questions);
        $second = $quizzes->createForDate('2026-11-14', $questions);
        $quizzes->saveResult(101, $first['id'], 5, $answers, $timestamp);
        $quizzes->saveResult(202, $first['id'], 3, $answers, $timestamp);
        $quizzes->saveResult(202, $second['id'], 2, $answers, $timestamp);
        $quizzes->saveResult(303, $first['id'], 0, $answers, $timestamp);
        $ranking = $quizzes->rankingForUser(202);
        $assert(array_column($ranking['leaders'], 'userId') === [101, 202, 303]);
        $assert(array_column($ranking['leaders'], 'rank') === [1, 1, 3]);
        $assert(array_column($ranking['leaders'], 'total') === [5, 10, 5]);
        $assert($ranking['ownRanking'] === $ranking['leaders'][1]);
        $assert($quizzes->rankingForUser(404)['ownRanking'] === null);
    });

    $run('Ranking udostępnia tylko dane publiczne i waliduje wersję avatara', static function () use ($quizzes, $questions, $timestamp, $answers, $assert): void {
        $quiz = $quizzes->createForDate('2026-11-14', $questions);
        foreach ([101, 202, 303] as $userId) {
            $quizzes->saveResult($userId, $quiz['id'], 1, $answers, $timestamp);
        }
        $leaders = $quizzes->rankingForUser(101)['leaders'];
        $assert($leaders[0]['avatarUrl'] === '/avatars/101?v=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $assert($leaders[1]['avatarUrl'] === null && $leaders[2]['avatarUrl'] === null);
        $assert($leaders[0]['name'] === 'Quiz <svg onload=alert(1)>', 'Warstwa widoku odpowiada za escaping nazwy.');
        $assert(array_keys($leaders[0]) === ['userId', 'name', 'avatarUrl', 'correct', 'total', 'completedQuizzes', 'rank']);
        $assert(count($leaders) === 3, 'Ustawienie profilu public_statistics nie wyłącza publicznego rankingu.');
    });

    $run('Miesiąc filtruje datę quizu, uwzględnia początek i wyklucza koniec okresu', static function () use ($quizzes, $questions, $answers, $assert): void {
        $october = $quizzes->createForDate('2026-10-31', $questions);
        $november = $quizzes->createForDate('2026-11-01', $questions);
        $lastNovember = $quizzes->createForDate('2026-11-30', $questions);
        $december = $quizzes->createForDate('2026-12-01', $questions);
        $quizzes->saveResult(101, $october['id'], 5, $answers, new DateTimeImmutable('2026-11-01T00:01:00+01:00'));
        $quizzes->saveResult(101, $november['id'], 2, $answers, new DateTimeImmutable('2026-10-31T23:05:00Z'));
        $quizzes->saveResult(101, $lastNovember['id'], 3, $answers, new DateTimeImmutable('2026-11-30T22:50:00Z'));
        $quizzes->saveResult(101, $december['id'], 5, $answers, new DateTimeImmutable('2026-11-30T23:02:00Z'));
        $quizzes->saveResult(202, $october['id'], 5, $answers, new DateTimeImmutable('2026-10-31T12:00:00Z'));
        $monthly = $quizzes->rankingForUser(101, '2026-11-01', '2026-12-01');
        $assert(count($monthly['leaders']) === 1);
        $assert($monthly['ownRanking']['correct'] === 5 && $monthly['ownRanking']['total'] === 10
            && $monthly['ownRanking']['completedQuizzes'] === 2);
        $assert($quizzes->rankingForUser(202, '2026-11-01', '2026-12-01')['ownRanking'] === null);
        $assert($quizzes->rankingForUser(101)['ownRanking']['correct'] === 15);
    });

    $run('Top 100 pozostaje ograniczony przy remisach, a własna pozycja poza nim jest dostępna', static function () use ($quizzes, $questions, $timestamp, $answers, $connection, $assert): void {
        $connection->exec(
            "INSERT INTO pg_temp.users (id, name, email, password_hash)
             SELECT id, 'Quiz ranking ' || id, 'quiz-ranking-' || id || '@example.test', 'hash'
             FROM generate_series(1001, 1110) AS id"
        );
        $quiz = $quizzes->createForDate('2026-11-14', $questions);
        $insert = $connection->prepare(
            "INSERT INTO quiz_attempts (user_id, quiz_id, score, answers)
             SELECT id, :quiz_id, 1, '{}'::jsonb FROM users WHERE id BETWEEN 1001 AND 1110"
        );
        $insert->execute(['quiz_id' => $quiz['id']]);
        $quizzes->saveResult(303, $quiz['id'], 0, $answers, $timestamp);
        $ranking = $quizzes->rankingForUser(303);
        $assert(count($ranking['leaders']) === 100);
        $assert($ranking['leaders'][0]['userId'] === 1001 && $ranking['leaders'][99]['userId'] === 1100);
        $assert(array_unique(array_column($ranking['leaders'], 'rank')) === [1]);
        $assert($ranking['ownRanking']['userId'] === 303 && $ranking['ownRanking']['rank'] === 111);
        $assert(!in_array(303, array_column($ranking['leaders'], 'userId'), true));
        $assert($quizzes->rankingForUser(1110)['ownRanking']['rank'] === 1, 'Remis poza limitem zachowuje wspólne miejsce.');
    });

    $run('Niepełne, niepoprawne i odwrócone granice okresu są odrzucane', static function () use ($quizzes, $assert): void {
        foreach ([['2026-11-01', null], [null, '2026-12-01'], ['2026-02-30', '2026-12-01'],
            ['2026-12-01', '2026-11-01'], ['2026-11-01', '2026-11-01'], ["2026-11-01' OR TRUE --", '2026-12-01']] as [$start, $end]) {
            $rejected = false;
            try {
                $quizzes->rankingForUser(101, $start, $end);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $assert($rejected);
        }
    });

    $run('Historia użycia pytań zwraca ostatni wcześniejszy dzień i pomija dzisiejsze oraz przyszłe zestawy', static function () use ($quizzes, $questions, $assert): void {
        $assert($quizzes->questionUsageBeforeDate('2026-11-14') === []);
        $quizzes->createForDate('2026-11-10', $questions);
        $later = $questions;
        $later[4]['id'] = 6;
        $quizzes->createForDate('2026-11-12', $later);
        $today = $questions;
        $today[0]['id'] = 7;
        $quizzes->createForDate('2026-11-14', $today);
        $future = $questions;
        $future[0]['id'] = 8;
        $quizzes->createForDate('2026-11-15', $future);
        $assert($quizzes->questionUsageBeforeDate('2026-11-14') === [
            1 => '2026-11-12', 2 => '2026-11-12', 3 => '2026-11-12', 4 => '2026-11-12',
            5 => '2026-11-10', 6 => '2026-11-12',
        ]);
        $assert($quizzes->questionUsageBeforeDate('2026-11-11') === array_fill(1, 5, '2026-11-10'));
        $rejected = false;
        try {
            $quizzes->questionUsageBeforeDate('2026-02-30');
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie testów rankingu quizu: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection !== null && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
