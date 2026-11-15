<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AchievementRepository;
use App\Repository\AnimeClueRepository;
use App\Service\AchievementService;
use App\Service\AnimeClueService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$db = (new Database())->connect();
$db->beginTransaction();
try {
    foreach (['users', 'anime', 'anime_genres', 'user_anime_experience', 'daily_quizzes', 'quiz_attempts',
        'user_achievements', 'daily_anime_clues', 'anime_clue_attempts', 'anime_clue_answers'] as $table) {
        $db->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $verify = $db->prepare("SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
            AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'");
        $verify->execute(['table' => $table]);
        $assert((int) $verify->fetchColumn() === 1, 'Test wymaga tabel tymczasowych.');
    }
    $db->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $db->exec("INSERT INTO pg_temp.users (id, name, email, password_hash)
        VALUES (101, 'Clue achievement A', 'clue-achievement-a@example.test', 'hash'),
               (202, 'Clue achievement B', 'clue-achievement-b@example.test', 'hash')");
    $entries = [];
    for ($index = 0; $index < 5; $index++) {
        $entries[] = ['id' => $index + 1, 'title' => 'Fixture ' . $index, 'aliases' => [],
            'franchise' => 'fixture-' . $index, 'clues' => ['pl' => ['Pierwsza', 'Druga', 'Trzecia']],
            'sourceUrl' => 'https://example.test/clue-achievements'];
    }
    $gameNow = new DateTimeImmutable('2026-10-01T12:00:00+02:00');
    $repository = new AnimeClueRepository($db);
    $game = new AnimeClueService($repository, static function () use (&$gameNow): DateTimeImmutable { return $gameNow; });
    $awards = new AchievementService(new AchievementRepository($db), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-15T12:00:00+01:00'));
    $first = $repository->createForDate('2026-10-01', $entries);
    $game->answer(101, $first['id'], 0, 'Fixture 0', 1);
    $assert($awards->synchronize(101) === ['guess_sharp'], 'Pierwsza poprawna odpowiedź odblokowuje tylko Sokole oko.');
    $items = array_column($awards->overviewForUser(101)['items'], null, 'code');
    $assert(!$items['guess_first']['unlocked'] && $items['guess_correct_50']['progress'] === 1, 'Częściowy dzień nie jest ukończonym zestawem.');
    echo '[OK] Pierwsza wskazówka daje odznakę, a częściowy dzień nie zalicza zestawu.' . PHP_EOL;

    for ($round = 1; $round < 5; $round++) {
        $game->answer(101, $first['id'], $round, 'Fixture ' . $round, 1);
    }
    $assert($awards->synchronize(101) === ['guess_first', 'guess_perfect'], 'Pełne 15/15 daje pierwszy zestaw i perfekcyjny wynik.');
    echo '[OK] Pięć odpowiedzi po jednej wskazówce odblokowuje zestaw i 15/15.' . PHP_EOL;

    for ($day = 2; $day <= 7; $day++) {
        $date = '2026-10-' . sprintf('%02d', $day);
        $gameNow = new DateTimeImmutable($date . 'T12:00:00+02:00');
        $daily = $repository->createForDate($date, $entries);
        for ($round = 0; $round < 5; $round++) {
            $game->answer(101, $daily['id'], $round, 'Fixture ' . $round, 1);
        }
    }
    $assert($awards->synchronize(101) === ['guess_streak_7'], 'Siedem ukończonych dni daje odznakę serii.');
    $items = array_column($awards->overviewForUser(101)['items'], null, 'code');
    $assert($items['guess_correct_50']['progress'] === 35 && !$items['guess_correct_50']['unlocked'], '35 trafień nie spełnia progu 50.');
    echo '[OK] Seria siedmiu pełnych dni działa niezależnie od progu 50 trafień.' . PHP_EOL;

    for ($day = 8; $day <= 10; $day++) {
        $date = '2026-10-' . sprintf('%02d', $day);
        $gameNow = new DateTimeImmutable($date . 'T12:00:00+02:00');
        $daily = $repository->createForDate($date, $entries);
        for ($round = 0; $round < 5; $round++) {
            $game->answer(101, $daily['id'], $round, 'Fixture ' . $round, 1);
        }
    }
    $assert($awards->synchronize(101) === ['guess_correct_50'], 'Pięćdziesiąte trafienie odblokowuje próg 50.');
    $before = $awards->earnedForUser(101);
    $assert(count($before) === 5 && $awards->synchronize(101) === [] && $awards->earnedForUser(101) === $before,
        'Wszystkie pięć odznak pozostaje niezmienne po ponownej synchronizacji.');
    echo '[OK] Dokładnie 50 trafień daje piątą odznakę, bez powielania dat.' . PHP_EOL;

    $gameNow = new DateTimeImmutable('2026-11-16T12:00:00+01:00');
    $future = $repository->createForDate('2026-11-16', $entries);
    for ($round = 0; $round < 5; $round++) {
        $game->answer(202, $future['id'], $round, 'Fixture ' . $round, 1);
    }
    $assert($awards->synchronize(202) === [] && $awards->earnedForUser(202) === [], 'Przyszłe wyniki nie przyznają odznak innemu kontu.');
    $assert($db->inTransaction(), 'Usługi nie mogą zatwierdzić zewnętrznej transakcji.');
    echo '[OK] Przyszłe wyniki, konta i transakcje pozostają odizolowane.' . PHP_EOL;
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}
