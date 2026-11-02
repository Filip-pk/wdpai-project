<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;
use RuntimeException;

final class AnimeImportService
{
    public const LEGACY_MAL_IDS = [20, 1535, 42310, 9253, 16498, 44511];
    public const MAX_LIMIT = 5000;
    private const MAX_SNAPSHOT_BYTES = 64 * 1024 * 1024;

    public function __construct(private readonly AnimeDatasetClient $client = new AnimeDatasetClient())
    {
    }

    public function collect(int $limit): array
    {
        $this->validateLimit($limit);
        $entries = [];

        for ($page = 1; count($entries) < $limit; $page++) {
            $payload = $this->client->page($page);
            if ($payload['data'] === []) {
                throw new RuntimeException('Źródło nie zawiera wymaganej liczby anime.');
            }

            foreach ($payload['data'] as $entry) {
                if (!is_array($entry)) {
                    throw new RuntimeException('Nieprawidłowy rekord źródłowego katalogu.');
                }
                $anime = $this->normalize($entry);
                $entries[$anime['malId']] = $anime;
            }

            if (count($entries) < $limit && ($payload['pagination']['has_next_page'] ?? false) !== true) {
                throw new RuntimeException('Źródło nie zawiera wymaganej liczby anime.');
            }
            if ($page > $limit) {
                throw new RuntimeException('Źródło zwraca powtarzające się pozycje.');
            }
        }

        foreach (self::LEGACY_MAL_IDS as $malId) {
            if (!isset($entries[$malId])) {
                $entry = $this->client->anime($malId)['data'];
                $anime = $this->normalize($entry);
                if ($anime['malId'] !== $malId) {
                    throw new RuntimeException('Źródło zwróciło inne anime niż zamówione.');
                }
                $entries[$malId] = $anime;
            }
        }

        return $this->select($entries, $limit);
    }

    public function fromFile(string $path, int $limit): array
    {
        $this->validateLimit($limit);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Plik katalogu nie istnieje lub jest nieczytelny.');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_SNAPSHOT_BYTES) {
            throw new RuntimeException('Plik katalogu przekracza dopuszczalny rozmiar 64 MB.');
        }
        $contents = file_get_contents($path, false, null, 0, self::MAX_SNAPSHOT_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAX_SNAPSHOT_BYTES) {
            throw new RuntimeException('Nie udało się odczytać pliku katalogu.');
        }

        try {
            $snapshot = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Plik katalogu nie zawiera poprawnego JSON.');
        }
        if (!is_array($snapshot) || !is_array($snapshot['data'] ?? null)
            || count($snapshot['data']) > self::MAX_LIMIT + 25) {
            throw new RuntimeException('Plik katalogu musi zawierać tablicę data z rekordami anime.');
        }

        $entries = [];
        foreach ($snapshot['data'] as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Nieprawidłowy rekord w pliku katalogu.');
            }
            $anime = $this->normalize($entry);
            if (isset($entries[$anime['malId']])) {
                throw new RuntimeException('Plik katalogu zawiera powtórzone identyfikatory MAL.');
            }
            $entries[$anime['malId']] = $anime;
        }

        return $this->select($entries, $limit);
    }

    public function saveFile(string $path, array $animes): void
    {
        $directory = dirname($path);
        if (is_link($path) || (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory))) {
            throw new RuntimeException('Nie można przygotować katalogu docelowego pliku.');
        }

        $json = json_encode([
            'source' => AnimeDatasetClient::SOURCE_URL,
            'attribution' => 'Processed Top 15k Anime by TheRealVigilante; original dataset by Quan Than; metadata from MyAnimeList.',
            'fetchedAt' => gmdate(DATE_ATOM),
            'data' => array_values($animes),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Nie udało się zapisać pliku katalogu.');
        }
    }

    public function normalize(array $entry): array
    {
        if (array_key_exists('anime_id', $entry)) {
            $entry = $this->client->normalize($entry);
        }
        $normalized = array_key_exists('malId', $entry);
        $malId = $this->positiveInteger($entry[$normalized ? 'malId' : 'mal_id'] ?? null);
        $title = $this->text($entry['title'] ?? null, 255);
        if ($malId === null || $title === '') {
            throw new RuntimeException('Anime musi mieć poprawny identyfikator MAL i tytuł.');
        }

        $aliases = [];
        if ($normalized) {
            $titles = $entry['alternativeTitles'] ?? [];
        } else {
            $titles = array_merge(
                is_array($entry['title_synonyms'] ?? null) ? $entry['title_synonyms'] : [],
                [$entry['title_english'] ?? null, $entry['title_japanese'] ?? null],
            );
            foreach (is_array($entry['titles'] ?? null) ? $entry['titles'] : [] as $alternative) {
                if (is_array($alternative)) {
                    $titles[] = $alternative['title'] ?? null;
                }
            }
        }
        if (!is_array($titles) || count($titles) > 100) {
            throw new RuntimeException('Nieprawidłowe tytuły alternatywne anime.');
        }
        foreach ($titles as $alternative) {
            $alternative = $this->text($alternative, 255);
            if ($alternative !== '' && $alternative !== $title) {
                $aliases[$alternative] = true;
            }
        }

        $genres = [];
        $rawGenres = $normalized
            ? ($entry['genres'] ?? [])
            : array_merge(
                is_array($entry['genres'] ?? null) ? $entry['genres'] : [],
                is_array($entry['themes'] ?? null) ? $entry['themes'] : [],
            );
        if (!is_array($rawGenres) || count($rawGenres) > 100) {
            throw new RuntimeException('Nieprawidłowe gatunki anime.');
        }
        foreach ($rawGenres as $genre) {
            if (!is_array($genre)) {
                throw new RuntimeException('Nieprawidłowy gatunek anime.');
            }
            $sourceId = $this->positiveInteger($genre[$normalized ? 'sourceId' : 'mal_id'] ?? null);
            $name = $this->text($genre['name'] ?? null, 100);
            if ($sourceId === null || $name === '') {
                throw new RuntimeException('Gatunek musi mieć identyfikator i nazwę.');
            }
            $genres[$sourceId] = ['sourceId' => $sourceId, 'name' => $name];
        }

        $description = $this->text($entry[$normalized ? 'description' : 'synopsis'] ?? null, 50000);
        $coverUrl = $normalized ? ($entry['coverUrl'] ?? null)
            : ($entry['images']['jpg']['large_image_url'] ?? $entry['images']['jpg']['image_url'] ?? null);
        $studio = '';
        if ($normalized) {
            $studio = $this->text($entry['studio'] ?? null, 255);
        } else {
            $studios = [];
            foreach (is_array($entry['studios'] ?? null) ? $entry['studios'] : [] as $item) {
                if (is_array($item)) {
                    $name = $this->text($item['name'] ?? null, 100);
                    if ($name !== '') {
                        $studios[] = $name;
                    }
                }
            }
            $studio = $this->text(implode(' · ', array_unique($studios)), 255);
        }

        $year = $this->positiveInteger($entry['year'] ?? null);
        if ($year === null && !$normalized && is_string($entry['aired']['from'] ?? null)
            && preg_match('/\A(\d{4})-/', $entry['aired']['from'], $match) === 1) {
            $year = (int) $match[1];
        }
        $year = $year !== null && $year >= 1900 && $year <= 2200 ? $year : null;
        $score = $entry['score'] ?? null;
        $score = (is_int($score) || is_float($score)) && is_finite((float) $score)
            && $score >= 0 && $score <= 10 ? (float) $score : null;

        return [
            'malId' => $malId,
            'title' => $title,
            'alternativeTitles' => array_map('strval', array_keys($aliases)),
            'description' => $description,
            'descriptionLanguage' => $normalized && ($entry['descriptionLanguage'] ?? null) === 'pl' ? 'pl' : 'en',
            'coverUrl' => $this->coverUrl($coverUrl),
            'episodes' => $this->positiveInteger($entry['episodes'] ?? null),
            'score' => $score,
            'studio' => $studio,
            'year' => $year,
            'type' => $this->text($entry['type'] ?? null, 20),
            'sourceUrl' => 'https://myanimelist.net/anime/' . $malId,
            'genres' => array_values($genres),
        ];
    }

    private function select(array $entries, int $limit): array
    {
        if (count($entries) < $limit) {
            throw new RuntimeException('Katalog zawiera mniej unikalnych anime niż wymagany limit.');
        }
        $selected = [];
        foreach (self::LEGACY_MAL_IDS as $malId) {
            if (!isset($entries[$malId])) {
                throw new RuntimeException('Brakuje anime MAL ' . $malId . ' potrzebnego do zachowania obecnej watchlisty.');
            }
            $selected[$malId] = $entries[$malId];
        }
        foreach ($entries as $malId => $anime) {
            if (count($selected) >= $limit) {
                break;
            }
            $selected[$malId] = $anime;
        }

        return array_values($selected);
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < count(self::LEGACY_MAL_IDS) || $limit > self::MAX_LIMIT) {
            throw new RuntimeException('Limit musi mieścić się w zakresie 6–5000 anime.');
        }
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 2_147_483_647 ? $value : null;
    }

    private function text(mixed $value, int $maximum): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || str_contains($value, "\0") || strlen($value) > $maximum * 4) {
            throw new RuntimeException('Pole tekstowe źródłowego anime ma nieprawidłową wartość lub długość.');
        }
        $length = preg_match_all('/./us', $value);
        if ($length === false || $length > $maximum) {
            throw new RuntimeException('Pole tekstowe źródłowego anime ma nieprawidłową wartość lub długość.');
        }

        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function coverUrl(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new RuntimeException('Nieprawidłowy adres okładki anime.');
        }
        $parts = parse_url($value);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'cdn.myanimelist.net'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !str_starts_with($parts['path'] ?? '', '/images/')) {
            throw new RuntimeException('Okładka anime musi pochodzić z zaufanego serwera MyAnimeList.');
        }

        return $value;
    }
}
