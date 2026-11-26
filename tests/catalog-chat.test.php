<?php

declare(strict_types=1);

use App\Database;
use App\Model\RecommendationChatCriteria;
use App\Repository\AnimeRepository;
use App\Repository\RecommendationChatRepository;
use App\Service\OllamaClient;
use App\Service\RecommendationChatService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$plan = static fn (string $json): stdClass => json_decode($json, false, 16, JSON_THROW_ON_ERROR);
$tags = ['Fantasy', 'Harem', 'Horror'];
$initial = RecommendationChatCriteria::apply([], $plan(
    '{"action":"search","changes":{"minEpisodes":21,"requireTags":["Harem"],"references":["No Game No Life"]}}'
), $tags);
$followUp = RecommendationChatCriteria::apply($initial['criteria'], $plan(
    '{"action":"search","changes":{"excludeTags":["harem"],"themes":["games","strategy"]}}'
), $tags);
$assert($followUp['criteria'] === [
    'excludeTags' => ['Harem'], 'minEpisodes' => 21, 'references' => ['No Game No Life'], 'themes' => ['games', 'strategy'],
], 'Follow-ups must preserve unchanged constraints and move the tag from required to excluded.');
$cleared = RecommendationChatCriteria::apply($followUp['criteria'], $plan(
    '{"action":"search","changes":{"minEpisodes":null,"forgetTags":["Harem"]}}'
), $tags);
$assert(!isset($cleared['criteria']['minEpisodes']) && !isset($cleared['criteria']['excludeTags']), 'Filters can be cleared explicitly.');
$conflict = RecommendationChatCriteria::apply($initial['criteria'], $plan(
    '{"action":"search","changes":{"maxEpisodes":20}}'
), $tags);
$assert($conflict === ['action' => 'clarify', 'criteria' => $initial['criteria']], 'A conflicting range must keep prior requirements.');
foreach ([
    '{"action":"search","changes":{"minEpisodes":"21"}}',
    '{"action":"search","changes":{"requireTags":"Harem"}}',
    '{"action":"search","changes":{"sql":"SELECT 1"}}',
    '{"action":"more","changes":{"minEpisodes":8}}',
] as $invalid) {
    try {
        RecommendationChatCriteria::apply([], $plan($invalid), $tags);
        throw new RuntimeException('Invalid plan accepted.');
    } catch (InvalidArgumentException) {
        // Model output is subject to PHP validation even when constrained by the schema.
    }
}
echo "[OK] Walidacja schematu i kolejne wiadomości\n";

$database = (new Database())->connect();
$database->beginTransaction();
try {
    foreach (['anime', 'genres', 'anime_genres', 'anime_external_ids'] as $table) {
        $database->exec("CREATE TEMP TABLE $table (LIKE public.$table INCLUDING ALL) ON COMMIT DROP");
    }

    $entry = static fn (int $id, string $title, array $changes = []): array => array_replace([
        'malId' => $id, 'title' => $title, 'alternativeTitles' => [], 'description' => 'Unrelated dolphins migrate.',
        'descriptionLanguage' => 'en', 'coverUrl' => null, 'episodes' => 23, 'score' => 8.0, 'studio' => '',
        'year' => 2023, 'type' => 'TV', 'sourceUrl' => null, 'airingStatus' => 'finished',
        'genres' => [['sourceId' => 1, 'name' => 'Fantasy'], ['sourceId' => 2, 'name' => 'Harem']],
    ], $changes);
    $source = new AnimeRepository($database);
    $source->importBatch([
        $entry(101, 'Reference entry', ['alternativeTitles' => ['No Game No Life'],
            'description' => 'Magical strategy tournaments unite the kingdoms.', 'episodes' => 12]),
        $entry(102, 'Description match', ['description' => 'Teams compete in magical strategy tournaments.', 'episodes' => 21]),
        $entry(103, 'Enough episodes', ['score' => 9.0]),
        $entry(104, 'Exactly twenty', ['episodes' => 20, 'score' => 9.8]),
        $entry(105, 'Unknown episodes', ['episodes' => null, 'score' => 9.8]),
        $entry(106, 'Unknown status', ['airingStatus' => null]),
        $entry(107, 'Missing tag', ['genres' => [['sourceId' => 1, 'name' => 'Fantasy']]]),
        $entry(108, 'Forbidden tag', ['genres' => [['sourceId' => 1, 'name' => 'Fantasy'],
            ['sourceId' => 2, 'name' => 'Harem'], ['sourceId' => 3, 'name' => 'Horror']]]),
        $entry(109, 'Wrong format', ['type' => 'MOVIE']),
        $entry(110, 'Wrong year', ['year' => 2021]),
        $entry(111, 'Wrong score', ['score' => 7.0]),
    ]);

    $repository = new RecommendationChatRepository($database);
    $criteria = $initial['criteria'] + ['excludeTags' => ['Horror'], 'minYear' => 2022,
        'minScore' => 7.99, 'format' => 'TV', 'status' => 'finished'];
    $result = $repository->search($criteria, [], 6);
    $assert(array_column(array_column($result['items'], 'anime'), 'malId') === [102, 103],
        'All strict filters must pass, and description similarity must affect ordering.');
    $assert($result['references'][0]['title'] === 'Reference entry', 'References resolve via catalogue aliases.');
    $assert($result['items'][array_key_first($result['items'])]['similarDescription'], 'Description match must be supported by the index.');
    $assert($repository->search(['requireTags' => ['Unavailable']], [], 6)['issue'] === 'unknown_tag',
        'An unknown hard tag cannot disappear from the search.');
    $assert($repository->search(['references' => ['No Game']], [], 6)['issue'] === 'reference',
        'An unknown reference cannot select an arbitrary title.');

    $shown = array_key_first($result['items']);
    $service = new RecommendationChatService($repository, new OllamaClient('http://127.0.0.1:1'));
    $page = $service->respond('Pokaż inne', true, ['criteria' => $criteria, 'seen' => [$shown], 'searched' => true], static fn () => false);
    $assert(count($page['cards']) === 1 && !isset($page['cards'][$shown]) && $page['criteria'] === $criteria,
        'Paging must keep the current filters and skip all shown titles without interpreting the message.');
    $emptyCriteria = ['maxYear' => 1900];
    $empty = $service->respond('Pokaż inne', true, ['criteria' => $emptyCriteria, 'searched' => true], static fn () => false);
    $assert($empty['cards'] === [] && $empty['criteria'] === $emptyCriteria
        && $empty['turns'][0]['key'] === 'no_matches_filter'
        && $empty['turns'][0]['parameters']['filter'] === 'view.catalog.premiere',
        'An empty search should suggest changing an active filter while keeping its value.');
    echo "[OK] Ścisłe filtry SQL, aliasy, opis i kolejne wyniki\n";

    $assert($source->fillMissingAiringStatuses([
        $entry(106, 'Updated', ['airingStatus' => 'airing']), $entry(106, 'Other source', ['airingStatus' => 'finished']),
    ]) === 0, 'Conflicting sources must leave the airing status unknown.');
    $assert($source->fillMissingAiringStatuses([
        $entry(106, 'Updated', ['airingStatus' => 'finished']), $entry(102, 'Updated', ['airingStatus' => 'airing']),
    ]) === 1, 'Backfill should update only the one existing entry with missing information.');
    $withBackfill = $repository->search($criteria, [], 6);
    $assert(array_column(array_column($withBackfill['items'], 'anime'), 'malId') === [102, 103, 106],
        'A backfilled matching title should be included and the already known status preserved.');
    echo "[OK] Uzupełnianie wyłącznie brakujących i jednoznacznych statusów emisji\n";
} finally {
    $database->rollBack();
}
