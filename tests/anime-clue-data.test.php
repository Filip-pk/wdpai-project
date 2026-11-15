<?php

declare(strict_types=1);

use App\Data\AnimeClues;
use App\Service\AnimeClueService;
use App\Service\Translator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$pool = AnimeClues::all();
$ids = array_column($pool, 'id');
sort($ids);
$assert($ids === range(1, 200), 'Wymagane jest dokładnie 200 stabilnych identyfikatorów.');
$titles = [];
$aliases = [];
foreach ($pool as $entry) {
    $assert(is_string($entry['title']) && trim($entry['title']) !== '', 'Brak tytułu.');
    $title = AnimeClueService::normalizeAnswer($entry['title']);
    $assert($title !== '' && !isset($titles[$title]), 'Powtórzony tytuł: ' . $entry['title']);
    $titles[$title] = true;
    $assert(is_string($entry['franchise']) && trim($entry['franchise']) !== '', 'Brak franczyzy.');
    $assert(array_is_list($entry['aliases']), 'Aliasy muszą być listą.');
    foreach ([$entry['title'], ...$entry['aliases']] as $alias) {
        $assert(is_string($alias) && preg_match('//u', $alias) === 1, 'Niepoprawny alias.');
        $normalized = AnimeClueService::normalizeAnswer($alias);
        $assert($normalized !== '' && (!isset($aliases[$normalized]) || $aliases[$normalized] === $entry['id']),
            'Alias pasuje do więcej niż jednego anime: ' . $alias);
        $aliases[$normalized] = $entry['id'];
        $assert(AnimeClueService::matchesAnswer($alias, $entry), 'Serwer musi akceptować alias: ' . $alias);
    }
    foreach (array_keys(Translator::LANGUAGES) as $locale) {
        $clues = $entry['clues'][$locale] ?? null;
        $assert(is_array($clues) && array_is_list($clues) && count($clues) === 3, 'Brak trzech wskazówek: ' . $entry['id'] . '/' . $locale);
        $assert(count(array_unique($clues)) === 3, 'Wskazówki muszą się różnić: ' . $entry['id'] . '/' . $locale);
        foreach ($clues as $clue) {
            $assert(is_string($clue) && trim($clue) !== '' && preg_match('//u', $clue) === 1,
                'Pusta lub niepoprawna wskazówka: ' . $entry['id'] . '/' . $locale);
            $assert(preg_match('/\{[a-zA-Z_]+\}/', $clue) !== 1, 'Nierozwinięty szablon wskazówki.');
            $assert(AnimeClueService::normalizeAnswer($clue) !== $title, 'Wskazówka nie może być samym tytułem.');
        }
    }
    $assert(filter_var($entry['sourceUrl'], FILTER_VALIDATE_URL) !== false
        && parse_url($entry['sourceUrl'], PHP_URL_SCHEME) === 'https', 'Brak źródła HTTPS.');
}
echo '[OK] 200 różnych anime, jednoznaczne aliasy i 3600 wskazówek w sześciu językach.' . PHP_EOL;

$usage = [];
$seen = [];
$day = new DateTimeImmutable('2026-01-01');
for ($index = 0; $index < 45; $index++) {
    $entries = AnimeClueService::selectEntries($pool, $usage);
    $assert(count($entries) === 5 && count(array_unique(array_column($entries, 'franchise'))) === 5, 'Dzień musi zawierać pięć różnych franczyz.');
    foreach ($entries as $entry) {
        $seen[$entry['id']] = true;
        $usage[$entry['id']] = $day->modify('+' . $index . ' days')->format('Y-m-d');
    }
}
$assert(count($seen) === 200, 'Dobór musi korzystać z całej puli i działać po jej wyczerpaniu.');
echo '[OK] Codzienne zestawy wykorzystują całą pulę i dopuszczają późniejsze powtórki.' . PHP_EOL;
