const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const projectRoot = path.join(__dirname, '..', '..');
const bootstrap = "declare(strict_types=1); require 'vendor/autoload.php'; ";

function php(source, input) {
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r', bootstrap + source, JSON.stringify(input)], {
        cwd: projectRoot, windowsHide: true, encoding: 'utf8', timeout: 30000, maxBuffer: 2 * 1024 * 1024,
    }));
}

const verifyAccounts = String.raw`
$marker = $input['marker'] ?? '';
if (!is_string($marker) || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $marker) !== 1) {
    throw new RuntimeException('Invalid fixture marker.');
}
$verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email FOR UPDATE');
foreach (['owner', 'other'] as $role) {
    $user = $input[$role] ?? [];
    if (!is_int($user['id'] ?? null) || $user['id'] <= 0
        || ($user['email'] ?? null) !== 'achievement-fixture-' . $role . '-' . $marker . '@example.test') {
        throw new RuntimeException('Fixture account ownership metadata mismatch.');
    }
    $verify->execute($user);
    if ($verify->fetchColumn() === false) throw new RuntimeException('Fixture account identity changed.');
}
`;

function createAchievementFixture() {
    const password = 'Achievement-Fixture-' + randomUUID() + '!';
    const fixture = php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$marker = $input['marker'];
if (preg_match('/\A[a-f0-9-]{36}\z/', $marker) !== 1) throw new RuntimeException('Invalid fixture marker.');
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $result = ['marker' => $marker, 'animes' => [], 'genres' => [], 'history' => []];
    $users = new App\Repository\UserRepository($db);
    foreach (['owner', 'other'] as $role) {
        $email = 'achievement-fixture-' . $role . '-' . $marker . '@example.test';
        $id = $users->create('Achievement ' . $role . ' <svg onload=alert(1)>', $email, password_hash($input['password'], PASSWORD_DEFAULT));
        $result[$role] = ['id' => $id, 'email' => $email];
    }
    $insertGenre = $db->prepare('INSERT INTO genres (source_id, name) VALUES (:source_id, :name) ON CONFLICT (source_id) DO NOTHING RETURNING id');
    for ($index = 0; $index < 5; $index++) {
        $id = false;
        for ($attempt = 0; $attempt < 20 && $id === false; $attempt++) {
            $sourceId = random_int(1500000000, 2100000000);
            $name = 'Achievement genre ' . $marker . ' - ' . $index;
            $insertGenre->execute(['source_id' => $sourceId, 'name' => $name]);
            $id = $insertGenre->fetchColumn();
        }
        if ($id === false) throw new RuntimeException('Cannot reserve fixture genre.');
        $result['genres'][] = ['id' => (int) $id, 'name' => $name, 'sourceId' => $sourceId];
    }
    $insertAnime = $db->prepare('INSERT INTO anime (mal_id, title, description, description_language, episodes, score, studio, release_year, media_type, source_url)
        VALUES (:mal_id, :title, :description, :language, :episodes, :score, :studio, :year, :type, :source)
        ON CONFLICT (mal_id) DO NOTHING RETURNING id');
    $link = $db->prepare('INSERT INTO anime_genres (anime_id, genre_id) VALUES (:anime_id, :genre_id)');
    for ($index = 0; $index < 10; $index++) {
        $title = 'Achievement anime ' . $marker . ' - ' . $index;
        $id = false;
        for ($attempt = 0; $attempt < 20 && $id === false; $attempt++) {
            $malId = random_int(1500000000, 2100000000);
            $insertAnime->execute(['mal_id' => $malId, 'title' => $title, 'description' => 'Controlled achievement test fixture.', 'language' => 'pl',
                'episodes' => 12, 'score' => 7.25, 'studio' => 'AniVibe QA', 'year' => 2199, 'type' => 'TV',
                'source' => 'https://example.test/achievement-fixture/' . $marker . '/anime/' . $index]);
            $id = $insertAnime->fetchColumn();
        }
        if ($id === false) throw new RuntimeException('Cannot reserve fixture MAL identity.');
        $id = (int) $id;
        $genre = $result['genres'][$index % 5]['id'];
        $link->execute(['anime_id' => $id, 'genre_id' => $genre]);
        $result['animes'][] = ['id' => $id, 'title' => $title, 'episodes' => 12, 'genreId' => $genre, 'malId' => $malId];
    }
    // Reserve an unused historical range. These synthetic IDs cannot affect selection
    // of actual questions (IDs 1–200), and no existing daily quiz is overwritten.
    $range = $db->prepare('SELECT count(*) FROM daily_quizzes WHERE quiz_date >= :start AND quiz_date < :finish');
    $start = null;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = (new DateTimeImmutable('1200-01-01'))->modify('+' . random_int(0, 200000) . ' days');
        $range->execute(['start' => $candidate->format('Y-m-d'), 'finish' => $candidate->modify('+30 days')->format('Y-m-d')]);
        if ((int) $range->fetchColumn() === 0) { $start = $candidate; break; }
    }
    if ($start === null) throw new RuntimeException('Cannot reserve historical quiz range.');
    $createQuiz = $db->prepare('INSERT INTO daily_quizzes (quiz_date, questions) VALUES (:date, CAST(:questions AS jsonb)) ON CONFLICT (quiz_date) DO NOTHING RETURNING id');
    for ($day = 0; $day < 30; $day++) {
        $date = $start->modify('+' . $day . ' days')->format('Y-m-d');
        $questions = [];
        for ($index = 1; $index <= 5; $index++) {
            $questions[] = ['id' => 1500000000 + $index, 'question' => 'Achievement fixture ' . $marker . ' question ' . $index,
                'options' => ['A', 'B', 'C', 'D'], 'correctOption' => 0, 'explanation' => 'Controlled historical quiz fixture.',
                'sourceUrl' => 'https://example.test/achievement-fixture/' . $marker . '/quiz/' . $day];
        }
        $createQuiz->execute(['date' => $date, 'questions' => json_encode($questions, JSON_THROW_ON_ERROR)]);
        $id = $createQuiz->fetchColumn();
        if ($id === false) throw new RuntimeException('Historical fixture date collision; no existing quiz was changed.');
        $result['history'][] = ['id' => (int) $id, 'date' => $date];
    }
    $result['quiz'] = (new App\Service\QuizService(new App\Repository\QuizRepository($db)))->dailyQuiz();
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: randomUUID(), password });
    return { ...fixture, password };
}

function seedAchievementProgress(fixture, { quizDays = 7, score = 5, completedAnime = 10, watched = 12 } = {}) {
    assert.ok(Number.isInteger(quizDays) && quizDays >= 0 && quizDays <= 30);
    assert.ok(Number.isInteger(score) && score >= 0 && score <= 5);
    assert.ok(Number.isInteger(completedAnime) && completedAnime >= 0 && completedAnime <= 10);
    assert.ok(Number.isInteger(watched) && watched >= 0 && watched <= 12);
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
` + verifyAccounts + String.raw`
    $settings = $input['settings'];
    $quizzes = new App\Repository\QuizRepository($db);
    $checkQuiz = $db->prepare('SELECT questions -> 0 ->> \'sourceUrl\' FROM daily_quizzes WHERE id = :id AND quiz_date = :date');
    foreach (array_slice($input['history'], 0, $settings['quizDays']) as $index => $quiz) {
        $checkQuiz->execute($quiz);
        if ($checkQuiz->fetchColumn() !== 'https://example.test/achievement-fixture/' . $marker . '/quiz/' . $index) {
            throw new RuntimeException('Historical fixture ownership mismatch.');
        }
        $answers = [];
        for ($question = 1; $question <= 5; $question++) $answers[1500000000 + $question] = $question <= $settings['score'] ? 0 : 1;
        $quizzes->saveResult($input['owner']['id'], $quiz['id'], $settings['score'], $answers, new DateTimeImmutable($quiz['date'] . 'T12:00:00Z'));
    }
    $checkAnime = $db->prepare('SELECT title FROM anime WHERE id = :id');
    $watchlist = new App\Repository\WatchlistRepository($db);
    foreach (array_slice($input['animes'], 0, $settings['completedAnime']) as $index => $anime) {
        $checkAnime->execute(['id' => $anime['id']]);
        if ($checkAnime->fetchColumn() !== 'Achievement anime ' . $marker . ' - ' . $index) {
            throw new RuntimeException('Anime fixture ownership mismatch.');
        }
        $watchlist->saveForUser($input['owner']['id'], $anime['id'], 'completed', $settings['watched']);
    }
    $db->commit();
    echo json_encode($settings, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { ...fixture, settings: { quizDays, score, completedAnime, watched } });
}

function ownedAchievementRecords(fixture, role = 'owner') {
    assert.ok(['owner', 'other'].includes(role));
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
` + verifyAccounts + String.raw`
    $read = $db->prepare('SELECT achievement_code AS code, unlocked_at AS "unlockedAt" FROM user_achievements WHERE user_id = :id ORDER BY achievement_code');
    $read->execute(['id' => $input[$input['role']]['id']]);
    $result = $read->fetchAll(PDO::FETCH_ASSOC);
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: fixture.marker, owner: fixture.owner, other: fixture.other, role });
}

function setAchievementPrivacy(fixture, publicStatistics) {
    assert.equal(typeof publicStatistics, 'boolean');
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
` + verifyAccounts + String.raw`
    (new App\Repository\PrivacySettingsRepository($db))->saveForUser($input['owner']['id'], 'everyone', false, $input['publicStatistics']);
    $db->commit();
    echo json_encode(['publicStatistics' => $input['publicStatistics']], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: fixture.marker, owner: fixture.owner, other: fixture.other, publicStatistics });
}

function cleanupAchievementFixture(fixture) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
` + verifyAccounts + String.raw`
    $verifyAnime = $db->prepare('SELECT title FROM anime WHERE id = :id FOR UPDATE');
    foreach ($input['animes'] as $index => $anime) {
        if (!is_int($anime['id']) || $anime['title'] !== 'Achievement anime ' . $marker . ' - ' . $index) throw new RuntimeException('Invalid anime ownership.');
        $verifyAnime->execute(['id' => $anime['id']]);
        if ($verifyAnime->fetchColumn() !== $anime['title']) throw new RuntimeException('Anime identity changed.');
    }
    $verifyGenre = $db->prepare('SELECT name, source_id FROM genres WHERE id = :id FOR UPDATE');
    foreach ($input['genres'] as $index => $genre) {
        if (!is_int($genre['id']) || $genre['name'] !== 'Achievement genre ' . $marker . ' - ' . $index) throw new RuntimeException('Invalid genre ownership.');
        $verifyGenre->execute(['id' => $genre['id']]);
        $actual = $verifyGenre->fetch(PDO::FETCH_ASSOC);
        if (!$actual || $actual['name'] !== $genre['name'] || (int) $actual['source_id'] !== $genre['sourceId']) throw new RuntimeException('Genre identity changed.');
    }
    $verifyQuiz = $db->prepare('SELECT questions -> 0 ->> \'sourceUrl\' FROM daily_quizzes WHERE id = :id AND quiz_date = :date FOR UPDATE');
    foreach ($input['history'] as $index => $quiz) {
        $verifyQuiz->execute($quiz);
        if ($verifyQuiz->fetchColumn() !== 'https://example.test/achievement-fixture/' . $marker . '/quiz/' . $index) throw new RuntimeException('Historical quiz identity changed.');
    }
    // Cascades remove only these two verified test accounts' attempts and awards.
    $removeUser = $db->prepare('DELETE FROM users WHERE id = :id AND email = :email');
    foreach (['owner', 'other'] as $role) $removeUser->execute($input[$role]);
    $removeQuiz = $db->prepare('DELETE FROM daily_quizzes WHERE id = :id AND quiz_date = :date AND NOT EXISTS (SELECT 1 FROM quiz_attempts WHERE quiz_id = :referenced_id)');
    $removedQuizzes = 0;
    foreach ($input['history'] as $quiz) {
        $removeQuiz->execute($quiz + ['referenced_id' => $quiz['id']]);
        $removedQuizzes += $removeQuiz->rowCount();
    }
    // The legacy watchlist foreign key has no cascade. Rows are limited to our marked anime.
    $removeWatchlist = $db->prepare('DELETE FROM user_watchlist WHERE anime_id = :id');
    $removeAnime = $db->prepare('DELETE FROM anime WHERE id = :id AND title = :title');
    foreach ($input['animes'] as $anime) {
        $removeWatchlist->execute(['id' => $anime['id']]);
        $removeAnime->execute(['id' => $anime['id'], 'title' => $anime['title']]);
    }
    $removeGenre = $db->prepare('DELETE FROM genres WHERE id = :id AND name = :name AND source_id = :source_id AND NOT EXISTS (SELECT 1 FROM anime_genres WHERE genre_id = :referenced_id)');
    foreach ($input['genres'] as $genre) $removeGenre->execute(['id' => $genre['id'], 'name' => $genre['name'], 'source_id' => $genre['sourceId'], 'referenced_id' => $genre['id']]);
    $db->commit();
    echo json_encode(['removedUsers' => 2, 'removedAnime' => count($input['animes']), 'removedHistoricalQuizzes' => $removedQuizzes], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: fixture.marker, owner: fixture.owner, other: fixture.other, animes: fixture.animes, genres: fixture.genres, history: fixture.history });
}

module.exports = { createAchievementFixture, seedAchievementProgress, cleanupAchievementFixture, ownedAchievementRecords, setAchievementPrivacy };
