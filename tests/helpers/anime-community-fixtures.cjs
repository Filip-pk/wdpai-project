const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const projectRoot = path.join(__dirname, '..', '..');
const phpBootstrap = "declare(strict_types=1); require 'vendor/autoload.php'; ";

function runFixturePhp(source, input) {
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r', phpBootstrap + source, JSON.stringify(input)], {
        cwd: projectRoot, windowsHide: true, encoding: 'utf8', timeout: 30000, maxBuffer: 1024 * 1024,
    }));
}

function createAnimeCommunityFixtures({ count = 1 } = {}) {
    assert.ok(Number.isSafeInteger(count) && count >= 1 && count <= 200, 'Fixture count must be between 1 and 200');
    return runFixturePhp(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$count = $input['count'] ?? null;
$marker = $input['marker'] ?? '';
if (!is_int($count) || $count < 1 || $count > 200 || !is_string($marker)
    || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $marker) !== 1) {
    throw new RuntimeException('Invalid fixture request.');
}
$database = (new App\Database())->connect();
$database->beginTransaction();
try {
    $prefix = 'AniVibe HTTP fixture ' . $marker . ' - ';
    $genreName = 'AniVibe HTTP genre ' . $marker;
    $genre = $database->prepare('INSERT INTO genres (source_id, name) VALUES (:source_id, :name) ON CONFLICT (source_id) DO NOTHING RETURNING id');
    $genreId = false;
    for ($attempt = 0; $attempt < 20 && $genreId === false; $attempt++) {
        $sourceId = random_int(1500000000, 2100000000);
        $genre->execute(['source_id' => $sourceId, 'name' => $genreName]);
        $genreId = $genre->fetchColumn();
    }
    if ($genreId === false) {
        throw new RuntimeException('Cannot reserve an isolated genre.');
    }
    $insert = $database->prepare('INSERT INTO anime (title, description, description_language, episodes, score, studio, release_year, media_type, source_url)
        VALUES (:title, :description, :language, :episodes, :score, :studio, :year, :type, :source) RETURNING id');
    $link = $database->prepare('INSERT INTO anime_genres (anime_id, genre_id) VALUES (:anime_id, :genre_id)');
    $animes = [];
    for ($index = 0; $index < $count; $index++) {
        $title = $prefix . sprintf('%03d', $index + 1);
        $year = 2198 + ($index % 2);
        $insert->execute(['title' => $title, 'description' => 'Controlled AniVibe HTTP test fixture.', 'language' => 'pl',
            'episodes' => 12, 'score' => 9.25, 'studio' => 'AniVibe QA', 'year' => $year, 'type' => 'TV',
            'source' => 'https://example.test/anivibe-fixture/' . $marker . '/' . ($index + 1)]);
        $id = (int) $insert->fetchColumn();
        $link->execute(['anime_id' => $id, 'genre_id' => (int) $genreId]);
        $animes[] = ['id' => $id, 'title' => $title, 'year' => $year, 'sourceScore' => 9.25, 'episodes' => 12];
    }
    $database->commit();
    echo json_encode(['marker' => $marker, 'titlePrefix' => $prefix, 'genreId' => (int) $genreId,
        'genreName' => $genreName, 'genreSourceId' => $sourceId, 'animes' => $animes], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    throw $exception;
}
`, { count, marker: randomUUID() });
}

function cleanupAnimeCommunityFixtures(fixture) {
    assert.ok(fixture && Array.isArray(fixture.animes) && fixture.animes.length > 0, 'Only a recorded fixture can be cleaned up');
    return runFixturePhp(String.raw`
$fixture = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$marker = $fixture['marker'] ?? '';
if (!is_string($marker) || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $marker) !== 1
    || !is_array($fixture['animes'] ?? null) || $fixture['animes'] === [] || count($fixture['animes']) > 200) {
    throw new RuntimeException('Invalid fixture cleanup request.');
}
$prefix = 'AniVibe HTTP fixture ' . $marker . ' - ';
$genreName = 'AniVibe HTTP genre ' . $marker;
if (($fixture['titlePrefix'] ?? null) !== $prefix || ($fixture['genreName'] ?? null) !== $genreName
    || !is_int($fixture['genreId'] ?? null) || $fixture['genreId'] <= 0
    || !is_int($fixture['genreSourceId'] ?? null) || $fixture['genreSourceId'] <= 0) {
    throw new RuntimeException('Fixture ownership metadata does not match.');
}
$database = (new App\Database())->connect();
$database->beginTransaction();
try {
    $lookup = $database->prepare('SELECT title FROM anime WHERE id = :id FOR UPDATE');
    $present = [];
    foreach ($fixture['animes'] as $index => $anime) {
        $expectedTitle = $prefix . sprintf('%03d', $index + 1);
        if (!is_array($anime) || !is_int($anime['id'] ?? null) || $anime['id'] <= 0 || ($anime['title'] ?? null) !== $expectedTitle) {
            throw new RuntimeException('Cleanup is limited to recorded fixture anime.');
        }
        $lookup->execute(['id' => $anime['id']]);
        $actualTitle = $lookup->fetchColumn();
        if ($actualTitle !== false && $actualTitle !== $expectedTitle) {
            throw new RuntimeException('Refusing to remove an anime whose identity changed.');
        }
        if ($actualTitle !== false) {
            $present[] = $anime;
        }
    }
    $genreLookup = $database->prepare('SELECT name, source_id FROM genres WHERE id = :id FOR UPDATE');
    $genreLookup->execute(['id' => $fixture['genreId']]);
    $actualGenre = $genreLookup->fetch(PDO::FETCH_ASSOC);
    if ($actualGenre !== false && ($actualGenre['name'] !== $genreName || (int) $actualGenre['source_id'] !== $fixture['genreSourceId'])) {
        throw new RuntimeException('Refusing to remove a genre whose identity changed.');
    }
    // The legacy watchlist foreign key has no cascade; its rows are limited to verified new fixture anime.
    $watchlist = $database->prepare('DELETE FROM user_watchlist WHERE anime_id = :anime_id');
    $remove = $database->prepare('DELETE FROM anime WHERE id = :id AND title = :title');
    $removed = 0;
    foreach ($present as $anime) {
        $watchlist->execute(['anime_id' => $anime['id']]);
        $remove->execute(['id' => $anime['id'], 'title' => $anime['title']]);
        $removed += $remove->rowCount();
    }
    $removeGenre = $database->prepare('DELETE FROM genres WHERE id = :id AND name = :name AND source_id = :source_id
        AND NOT EXISTS (SELECT 1 FROM anime_genres WHERE genre_id = :referenced_id)');
    $removeGenre->execute(['id' => $fixture['genreId'], 'name' => $genreName, 'source_id' => $fixture['genreSourceId'], 'referenced_id' => $fixture['genreId']]);
    $removedGenres = $removeGenre->rowCount();
    $database->commit();
    echo json_encode(['removedAnimeCount' => $removed, 'removedGenreCount' => $removedGenres], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    throw $exception;
}
`, fixture);
}

module.exports = { createAnimeCommunityFixtures, cleanupAnimeCommunityFixtures };
