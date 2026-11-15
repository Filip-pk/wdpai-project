const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const projectRoot = path.join(__dirname, '..', '..');

function php(source, input) {
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r',
        "declare(strict_types=1); require 'vendor/autoload.php'; " + source, JSON.stringify(input)], {
        cwd: projectRoot, windowsHide: true, encoding: 'utf8', timeout: 30000, maxBuffer: 2 * 1024 * 1024,
    }));
}

const verify = String.raw`
$marker = $input['marker'] ?? '';
if (!is_string($marker) || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $marker) !== 1) throw new RuntimeException('Invalid fixture marker.');
$verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email FOR UPDATE');
foreach (['owner', 'other'] as $role) {
    $user = $input[$role] ?? [];
    if (!is_int($user['id'] ?? null) || $user['id'] <= 0 || ($user['email'] ?? '') !== 'anime-clue-fixture-' . $role . '-' . $marker . '@example.test') {
        throw new RuntimeException('Fixture ownership metadata mismatch.');
    }
    $verify->execute($user);
    if ($verify->fetchColumn() === false) throw new RuntimeException('Fixture account identity changed.');
}
`;

function createAnimeClueFixture() {
    const password = 'Clue-Fixture-' + randomUUID() + '!';
    const fixture = php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$marker = $input['marker'];
if (preg_match('/\A[a-f0-9-]{36}\z/', $marker) !== 1) throw new RuntimeException('Invalid marker.');
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $users = new App\Repository\UserRepository($db);
    $result = ['marker' => $marker, 'history' => []];
    foreach (['owner', 'other'] as $role) {
        $email = 'anime-clue-fixture-' . $role . '-' . $marker . '@example.test';
        $id = $users->create('Clue ' . $role . ' <svg onload=alert(1)>', $email, password_hash($input['password'], PASSWORD_DEFAULT));
        $result[$role] = ['id' => $id, 'email' => $email];
    }
    $repo = new App\Repository\AnimeClueRepository($db);
    $service = new App\Service\AnimeClueService($repo);
    $state = $service->stateForUser($result['owner']['id']);
    $result['game'] = $repo->findById($state['id']);
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: randomUUID(), password });
    return { ...fixture, password };
}

function clueRecords(fixture, role = 'owner') {
    assert.ok(['owner', 'other'].includes(role));
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
` + verify + String.raw`
    $id = $input[$input['role']]['id'];
    $repo = new App\Repository\AnimeClueRepository($db);
    $awards = $db->prepare('SELECT achievement_code AS code, unlocked_at AS "unlockedAt" FROM user_achievements WHERE user_id = :id ORDER BY achievement_code');
    $awards->execute(['id' => $id]);
    $result = ['attempt' => $repo->attemptForUser($id, $input['gameId']),
        'answers' => $repo->answersForUser($id, $input['gameId']), 'awards' => $awards->fetchAll(PDO::FETCH_ASSOC)];
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: fixture.marker, owner: fixture.owner, other: fixture.other, gameId: fixture.game.id, role });
}

function seedHistoricalClueProgress(fixture, { days = 10, role = 'other' } = {}) {
    assert.ok(Number.isInteger(days) && days >= 1 && days <= 10);
    assert.ok(['owner', 'other'].includes(role));
    const history = php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
` + verify + String.raw`
    $days = $input['days'];
    if (!is_int($days) || $days < 1 || $days > 10) throw new RuntimeException('Invalid fixture days.');
    $range = $db->prepare('SELECT count(*) FROM daily_anime_clues WHERE game_date >= :start AND game_date < :finish');
    $start = null;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = (new DateTimeImmutable('1200-01-01'))->modify('+' . random_int(0, 200000) . ' days');
        $range->execute(['start' => $candidate->format('Y-m-d'), 'finish' => $candidate->modify('+' . $days . ' days')->format('Y-m-d')]);
        if ((int) $range->fetchColumn() === 0) { $start = $candidate; break; }
    }
    if ($start === null) throw new RuntimeException('Cannot reserve fixture dates.');
    $insert = $db->prepare('INSERT INTO daily_anime_clues (game_date, entries) VALUES (:date, CAST(:entries AS jsonb)) ON CONFLICT (game_date) DO NOTHING RETURNING id');
    $repo = new App\Repository\AnimeClueRepository($db);
    $history = [];
    for ($day = 0; $day < $days; $day++) {
        $date = $start->modify('+' . $day . ' days')->format('Y-m-d');
        $entries = [];
        for ($round = 0; $round < 5; $round++) {
            $clues = [];
            foreach (array_keys(App\Service\Translator::LANGUAGES) as $locale) $clues[$locale] = ['Fixture first', 'Fixture second', 'Fixture third'];
            $entries[] = ['id' => 1500000000 + $round, 'title' => 'Fixture title ' . $round, 'aliases' => [],
                'franchise' => 'fixture-' . $round, 'clues' => $clues,
                'sourceUrl' => 'https://example.test/anime-clue-fixture/' . $marker . '/' . $day];
        }
        $insert->execute(['date' => $date, 'entries' => json_encode($entries, JSON_THROW_ON_ERROR)]);
        $gameId = $insert->fetchColumn();
        if ($gameId === false) throw new RuntimeException('Fixture date collision; no existing game was overwritten.');
        $gameId = (int) $gameId;
        $repo->lockAttempt($input[$input['role']]['id'], $gameId);
        foreach ($entries as $round => $entry) {
            $repo->finalizeRound($input[$input['role']]['id'], $gameId, $round, $entry['title'], true, 3, 1, false, new DateTimeImmutable($date . 'T12:00:00Z'));
        }
        $history[] = ['id' => $gameId, 'date' => $date, 'source' => 'https://example.test/anime-clue-fixture/' . $marker . '/' . $day];
    }
    $db->commit();
    echo json_encode($history, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: fixture.marker, owner: fixture.owner, other: fixture.other, days, role });
    fixture.history.push(...history);
    return history;
}

function cleanupAnimeClueFixture(fixture) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
` + verify + String.raw`
    $check = $db->prepare('SELECT entries -> 0 ->> \'sourceUrl\' FROM daily_anime_clues WHERE id = :id AND game_date = :date FOR UPDATE');
    foreach ($input['history'] as $day) {
        if (!is_int($day['id']) || !str_starts_with($day['source'], 'https://example.test/anime-clue-fixture/' . $marker . '/')) throw new RuntimeException('Invalid historical fixture metadata.');
        $check->execute(['id' => $day['id'], 'date' => $day['date']]);
        if ($check->fetchColumn() !== $day['source']) throw new RuntimeException('Historical fixture identity changed.');
    }
    $removeUser = $db->prepare('DELETE FROM users WHERE id = :id AND email = :email');
    $removed = 0;
    foreach (['owner', 'other'] as $role) {
        $removeUser->execute($input[$role]);
        $removed += $removeUser->rowCount();
    }
    $removeDay = $db->prepare('DELETE FROM daily_anime_clues WHERE id = :id AND game_date = :date AND NOT EXISTS (SELECT 1 FROM anime_clue_attempts WHERE game_id = :referenced_id)');
    $removedDays = 0;
    foreach ($input['history'] as $day) {
        $removeDay->execute(['id' => $day['id'], 'date' => $day['date'], 'referenced_id' => $day['id']]);
        $removedDays += $removeDay->rowCount();
    }
    $db->commit();
    echo json_encode(['removedUsers' => $removed, 'removedDays' => $removedDays], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: fixture.marker, owner: fixture.owner, other: fixture.other, history: fixture.history });
}

function guessFields(fixture, token, roundIndex = 0, extra = {}) {
    return { csrfToken: token, gameId: String(fixture.game.id), roundIndex: String(roundIndex), expectedHints: '1', ...extra };
}

module.exports = { createAnimeClueFixture, cleanupAnimeClueFixture, clueRecords, seedHistoricalClueProgress, guessFields };
