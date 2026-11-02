<?php

declare(strict_types=1);

namespace App\Service;

use Generator;
use JsonException;
use RuntimeException;

final class FullAnimeImportService
{
    private const GENRE_IDS = [
        'Action' => 1, 'Adventure' => 2, 'Racing' => 3, 'Comedy' => 4,
        'Avant Garde' => 5, 'Mythology' => 6, 'Mystery' => 7, 'Drama' => 8,
        'Ecchi' => 9, 'Fantasy' => 10, 'Strategy Game' => 11, 'Hentai' => 12,
        'Historical' => 13, 'Horror' => 14, 'Kids' => 15, 'Martial Arts' => 17,
        'Mecha' => 18, 'Music' => 19, 'Parody' => 20, 'Samurai' => 21,
        'Romance' => 22, 'School' => 23, 'Sci-Fi' => 24, 'Shoujo' => 25,
        'Girls Love' => 26, 'Shounen' => 27, 'Boys Love' => 28, 'Space' => 29,
        'Sports' => 30, 'Super Power' => 31, 'Vampire' => 32, 'Harem' => 35,
        'Slice of Life' => 36, 'Supernatural' => 37, 'Military' => 38,
        'Detective' => 39, 'Psychological' => 40, 'Suspense' => 41,
        'Seinen' => 42, 'Josei' => 43, 'Award Winning' => 46,
        'Gourmet' => 47, 'Workplace' => 48, 'Erotica' => 49,
    ];

    private const THEMES = [
        'Adult Cast', 'Anthropomorphic', 'CGDCT', 'Childcare', 'Combat Sports',
        'Crossdressing', 'Delinquents', 'Educational', 'Gag Humor', 'Gore',
        'High Stakes Game', 'Idols (Female)', 'Idols (Male)', 'Isekai', 'Iyashikei',
        'Love Polygon', 'Magical Sex Shift', 'Mahou Shoujo', 'Medical',
        'Organized Crime', 'Otaku Culture', 'Performing Arts', 'Pets',
        'Reincarnation', 'Reverse Harem', 'Romantic Subtext', 'Showbiz',
        'Survival', 'Team Sports', 'Time Travel', 'Urban Fantasy',
        'Video Game', 'Villainess', 'Visual Arts', 'Love Status Quo',
    ];

    private const COVER_HOSTS = [
        'cdn.myanimelist.net', 'api-cdn.myanimelist.net',
        'media.kitsu.app', 'media.kitsu.io', 's4.anilist.co',
        'cdn.anime-planet.com', 'static.anime-planet.com',
        'cdn.anisearch.com', 'cdn.anisearch.de',
        'cdn.animenewsnetwork.com', 'cdn.anidb.net', 'img.anidb.net',
        'simkl.in', 'u.livechart.me', 'static.livechart.me',
    ];

    private array $statistics = [];
    private array $genreNames = [];
    private array $genreKeys = [];

    public function records(string $manifestPath): Generator
    {
        $this->statistics = [
            'aodRows' => 0, 'metadataRows' => 0, 'metadataUnique' => 0,
            'duplicateMetadata' => 0, 'recordsEmitted' => 0,
            'withDescription' => 0, 'withCover' => 0, 'withoutMal' => 0,
            'unmatchedHF' => 0, 'invalidCovers' => 0, 'placeholderCovers' => 0,
            'invalidSources' => 0, 'withoutIdentity' => 0, 'multipleMalIds' => 0,
        ];
        $this->genreNames = [];
        $this->genreKeys = [];
        foreach (array_merge(array_keys(self::GENRE_IDS), self::THEMES) as $name) {
            $this->genre($name);
        }

        $files = $this->manifestFiles($manifestPath);
        $metadata = $this->loadMetadata($files['metadata']);
        $this->statistics['metadataUnique'] = count($metadata);
        $handle = fopen($files['aod'], 'rb');
        if ($handle === false) {
            throw new RuntimeException('Nie można odczytać pliku AOD.');
        }

        $lineNumber = 0;
        $hasMetadata = false;
        try {
            while (($line = fgets($handle, 5 * 1024 * 1024 + 1)) !== false) {
                $lineNumber++;
                if (trim($line) === '') {
                    continue;
                }
                if (strlen($line) >= 5 * 1024 * 1024 && !str_ends_with($line, "\n")) {
                    throw new RuntimeException('Rekord AOD jest zbyt duży, linia ' . $lineNumber . '.');
                }
                try {
                    $entry = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new RuntimeException('Nieprawidłowy JSON AOD, linia ' . $lineNumber . '.');
                }
                if (!is_array($entry)) {
                    throw new RuntimeException('Nieprawidłowa struktura AOD, linia ' . $lineNumber . '.');
                }
                if (!$hasMetadata) {
                    if (isset($entry['sources']) || isset($entry['title'])) {
                        throw new RuntimeException('Plik AOD nie zawiera początkowej linii metadanych.');
                    }
                    $hasMetadata = true;
                    $this->statistics['aodLastUpdate'] = $entry['lastUpdate'] ?? null;
                    continue;
                }

                $this->statistics['aodRows']++;
                $identity = $this->externalIds($entry['sources'] ?? null);
                if ($identity['externalIds'] === []) {
                    $this->statistics['withoutIdentity']++;
                    continue;
                }
                $rich = null;
                foreach ($identity['malIds'] as $id) {
                    if (isset($metadata[$id])) {
                        $rich = $rich === null ? $metadata[$id] : $this->mergeMetadata($rich, $metadata[$id]);
                        unset($metadata[$id]);
                    }
                }
                if (count($identity['malIds']) > 1) {
                    $this->statistics['multipleMalIds']++;
                }

                $anime = $this->aodAnime($entry, $identity, $rich);
                $this->countEmitted($anime);
                yield $anime;
            }
            if (!feof($handle) || !$hasMetadata) {
                throw new RuntimeException('Nie udało się odczytać kompletnego pliku AOD.');
            }
        } finally {
            fclose($handle);
        }

        $this->statistics['unmatchedHF'] = count($metadata);
        foreach ($metadata as $anime) {
            $this->countEmitted($anime);
            yield $anime;
        }
    }

    public function report(): array
    {
        return $this->statistics;
    }

    private function manifestFiles(string $path): array
    {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)
            || basename($resolved) === '.env' || filesize($resolved) > 1024 * 1024) {
            throw new RuntimeException('Manifest nie istnieje, jest nieczytelny lub zbyt duży.');
        }
        try {
            $manifest = json_decode((string) file_get_contents($resolved), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Manifest nie zawiera poprawnego JSON.');
        }
        if (!is_array($manifest) || ($manifest['version'] ?? null) !== 1
            || !is_array($manifest['sources']['aod'] ?? null)
            || !is_array($manifest['sources']['metadata']['files'] ?? null)
            || $manifest['sources']['metadata']['files'] === []
            || count($manifest['sources']['metadata']['files']) > 16) {
            throw new RuntimeException('Nieprawidłowa struktura manifestu pełnego katalogu.');
        }

        $directory = dirname($resolved);
        $aod = $this->verifiedFile($directory, $manifest['sources']['aod'], 512 * 1024 * 1024);
        $metadata = [];
        foreach ($manifest['sources']['metadata']['files'] as $file) {
            if (!is_array($file)) {
                throw new RuntimeException('Nieprawidłowy opis pliku metadanych.');
            }
            $metadata[] = $this->verifiedFile($directory, $file, 100 * 1024 * 1024);
        }
        if (count(array_unique(array_merge([$aod], $metadata))) !== count($metadata) + 1) {
            throw new RuntimeException('Manifest zawiera powtórzone pliki.');
        }

        return ['aod' => $aod, 'metadata' => $metadata];
    }

    private function verifiedFile(string $directory, array $entry, int $maximum): string
    {
        $relative = $entry['path'] ?? null;
        $expected = $entry['sha256'] ?? null;
        if (!is_string($relative) || $relative === '' || str_contains($relative, "\0")
            || preg_match('~\A(?:[a-zA-Z]:|[\\\\/])|(?:\A|[\\\\/])\.\.?([\\\\/]|\z)~', $relative) === 1
            || str_starts_with(basename($relative), '.')
            || !is_string($expected) || preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1) {
            throw new RuntimeException('Nieprawidłowa ścieżka lub suma kontrolna w manifeście.');
        }

        $root = realpath($directory) . DIRECTORY_SEPARATOR;
        $resolved = realpath($root . $relative);
        $checkRoot = PHP_OS_FAMILY === 'Windows' ? strtolower($root) : $root;
        $checkPath = $resolved !== false && PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved;
        if ($resolved === false || !is_string($checkPath) || !str_starts_with($checkPath, $checkRoot)
            || !is_file($resolved) || !is_readable($resolved) || filesize($resolved) > $maximum) {
            throw new RuntimeException('Plik źródłowy jest poza katalogiem manifestu, niedostępny lub zbyt duży.');
        }
        $actual = hash_file('sha256', $resolved);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Suma kontrolna pliku źródłowego nie zgadza się z manifestem.');
        }

        return $resolved;
    }

    private function loadMetadata(array $files): array
    {
        $metadata = [];
        foreach ($files as $path) {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Nie można odczytać CSV metadanych anime.');
            }
            try {
                $header = fgetcsv($handle, null, ',', '"', '');
                if (!is_array($header)) {
                    throw new RuntimeException('CSV nie zawiera nagłówka.');
                }
                $header = array_map(static fn (mixed $name): string => trim((string) $name), $header);
                $header[0] = preg_replace('/\A\xEF\xBB\xBF/', '', $header[0]);
                if (!in_array('mal_id', $header, true) || !in_array('title', $header, true)
                    || count(array_unique($header)) !== count($header)) {
                    throw new RuntimeException('CSV nie zawiera wymaganych lub unikalnych nazw kolumn.');
                }
                while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
                    if ($values === [null]) {
                        continue;
                    }
                    if (count($values) !== count($header)) {
                        throw new RuntimeException('CSV zawiera rekord o nieprawidłowej liczbie kolumn.');
                    }
                    $this->statistics['metadataRows']++;
                    $anime = $this->metadataAnime(array_combine($header, $values));
                    $id = $anime['malId'];
                    if (isset($metadata[$id])) {
                        $this->statistics['duplicateMetadata']++;
                        $metadata[$id] = $this->mergeMetadata($metadata[$id], $anime);
                    } else {
                        $metadata[$id] = $anime;
                    }
                }
                if (!feof($handle)) {
                    throw new RuntimeException('Nie udało się odczytać kompletnego CSV.');
                }
            } finally {
                fclose($handle);
            }
        }

        return $metadata;
    }

    private function metadataAnime(array $row): array
    {
        $id = $this->positiveInteger($row['mal_id'] ?? null);
        $title = $this->text($row['title'] ?? null, 255, false);
        if ($id === null || $title === '') {
            throw new RuntimeException('Metadane anime nie zawierają poprawnego MAL ID i tytułu.');
        }
        $aliases = [$title, $this->text($row['title_english'] ?? null, 1000, false), $this->text($row['title_japanese'] ?? null, 1000, false)];
        $synonyms = $this->text($row['title_synonyms'] ?? null, 50000, false);
        if (str_starts_with($synonyms, '[')) {
            $decoded = json_decode($synonyms, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                foreach ($decoded as $alias) {
                    $aliases[] = $this->text($alias, 1000, false);
                }
            } else {
                $aliases[] = $this->text($synonyms, 1000, false);
            }
        } else {
            $aliases[] = $this->text($synonyms, 1000, false);
        }
        $genres = [];
        foreach (['genres', 'themes'] as $field) {
            foreach (explode(',', $this->text($row[$field] ?? null, 10000)) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $genre = $this->genre($name);
                    $genres[$genre['sourceId']] = $genre;
                }
            }
        }
        $year = $this->year($row['year'] ?? null);
        if ($year === null && preg_match('/\A(\d{4})-/', $this->text($row['aired_from'] ?? null, 100), $match) === 1) {
            $year = $this->year($match[1]);
        }
        $description = $this->text($row['synopsis'] ?? null, 50000);
        if (str_starts_with($description, 'No synopsis information has been added to this title.')) {
            $description = '';
        }
        $cover = $this->cover($row['image_jpg_large_url'] ?? null);
        if ($cover === null) {
            $cover = $this->cover($row['image_jpg_url'] ?? null);
        }

        return [
            'malId' => $id, 'title' => $title, 'alternativeTitles' => $this->aliases($aliases, $title),
            'description' => $description, 'descriptionLanguage' => 'en',
            'coverUrl' => $cover,
            'episodes' => $this->positiveInteger($row['episodes'] ?? null),
            'score' => $this->score($row['score'] ?? null),
            'studio' => $this->text($row['studios'] ?? null, 1000), 'year' => $year,
            'type' => $this->text($row['type'] ?? null, 20) ?: 'UNKNOWN',
            'sourceUrl' => 'https://myanimelist.net/anime/' . $id,
            'genres' => array_values($genres), 'externalIds' => [['provider' => 'mal', 'externalId' => (string) $id]],
        ];
    }

    private function mergeMetadata(array $first, array $second): array
    {
        $aliases = array_merge($first['alternativeTitles'], $second['alternativeTitles'], [$second['title']]);
        $genres = [];
        foreach (array_merge($first['genres'], $second['genres']) as $genre) {
            $genres[$genre['sourceId']] = $genre;
        }
        foreach (['description', 'coverUrl', 'episodes', 'score', 'studio', 'year', 'type'] as $field) {
            if (($first[$field] === null || $first[$field] === '' || ($field === 'type' && $first[$field] === 'UNKNOWN'))
                && $second[$field] !== null && $second[$field] !== '') {
                $first[$field] = $second[$field];
            }
        }
        $first['alternativeTitles'] = $this->aliases($aliases, $first['title']);
        $first['genres'] = array_values($genres);

        return $first;
    }

    private function aodAnime(array $entry, array $identity, ?array $rich): array
    {
        $title = $this->text($entry['title'] ?? null, 255, false);
        if ($title === '') {
            throw new RuntimeException('Rekord AOD nie zawiera tytułu.');
        }
        if (!is_array($entry['synonyms'] ?? null) || !array_is_list($entry['synonyms'])
            || !is_array($entry['studios'] ?? null) || !array_is_list($entry['studios'])
            || !is_array($entry['tags'] ?? null) || !array_is_list($entry['tags'])
            || !is_array($entry['animeSeason'] ?? null)) {
            throw new RuntimeException('Rekord AOD nie zawiera poprawnych aliasów, studiów, tagów lub sezonu.');
        }
        $aliases = [];
        foreach ($entry['synonyms'] as $alias) {
            $aliases[] = $this->text($alias, 1000, false);
        }
        $studios = [];
        foreach ($entry['studios'] as $studio) {
            $studio = $this->text($studio, 1000);
            if ($studio !== '') {
                $studios[] = $studio;
            }
        }
        $studio = $this->text(implode(' · ', array_unique($studios)), 1000);
        $fallbackGenres = [];
        foreach ($entry['tags'] as $tag) {
            $tag = strtolower($this->text($tag, 1000));
            $tag = $tag === 'science fiction' ? 'sci-fi' : $tag;
            if (isset($this->genreNames[$tag])) {
                $genre = $this->genre($this->genreNames[$tag]);
                $fallbackGenres[$genre['sourceId']] = $genre;
            }
        }
        $cover = $this->cover($entry['picture'] ?? $entry['thumbnail'] ?? null);
        $malId = $identity['malIds'][0] ?? null;

        return [
            'malId' => $malId, 'title' => $title,
            'alternativeTitles' => $this->aliases(array_merge($aliases, $rich['alternativeTitles'] ?? [], [$rich['title'] ?? '']), $title),
            'description' => $rich['description'] ?? '', 'descriptionLanguage' => 'en',
            'coverUrl' => $cover ?? $rich['coverUrl'] ?? null,
            'episodes' => $this->positiveInteger($entry['episodes'] ?? null) ?? $rich['episodes'] ?? null,
            'score' => $rich['score'] ?? null, 'studio' => $studio !== '' ? $studio : ($rich['studio'] ?? ''),
            'year' => $this->year($entry['animeSeason']['year'] ?? null) ?? $rich['year'] ?? null,
            'type' => $this->text($entry['type'] ?? null, 20) ?: ($rich['type'] ?? 'UNKNOWN'),
            'sourceUrl' => $malId !== null ? 'https://myanimelist.net/anime/' . $malId : $identity['sourceUrl'],
            'genres' => !empty($rich['genres']) ? $rich['genres'] : array_values($fallbackGenres),
            'externalIds' => $identity['externalIds'],
        ];
    }

    private function externalIds(mixed $sources): array
    {
        if (!is_array($sources) || !array_is_list($sources)) {
            throw new RuntimeException('Rekord AOD nie zawiera poprawnej listy źródeł.');
        }
        $ids = [];
        $malIds = [];
        $sourceUrl = null;
        foreach ($sources as $source) {
            $url = is_string($source) ? trim($source) : '';
            $parts = $url !== '' ? parse_url($url) : false;
            if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || preg_match('/[\x00-\x20\x7F]/', $url) === 1 || strlen($url) > 4096) {
                $this->statistics['invalidSources']++;
                continue;
            }
            $host = strtolower(preg_replace('/\Awww\./i', '', $parts['host']));
            $scheme = strtolower($parts['scheme']);
            $canonical = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '')
                . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            if ($sourceUrl === null && $scheme === 'https') {
                $sourceUrl = $canonical;
            }
            $provider = null;
            $externalId = null;
            $pathname = $parts['path'] ?? '/';
            foreach ([
                'myanimelist.net' => ['mal', '~\A/anime/(\d+)(?:/|\z)~'],
                'anilist.co' => ['anilist', '~\A/anime/(\d+)(?:/|\z)~'],
                'anidb.net' => ['anidb', '~\A/(?:anime/|a)(\d+)(?:/|\z)~'],
                'kitsu.app' => ['kitsu', '~\A/anime/([^/]+)~'],
                'kitsu.io' => ['kitsu', '~\A/anime/([^/]+)~'],
                'anime-planet.com' => ['animeplanet', '~\A/anime/([^/]+)~'],
                'anisearch.com' => ['anisearch', '~\A/anime/(\d+)(?:[,/]|\z)~'],
                'anisearch.de' => ['anisearch', '~\A/anime/(\d+)(?:[,/]|\z)~'],
                'simkl.com' => ['simkl', '~\A/anime/(\d+)(?:/|\z)~'],
                'livechart.me' => ['livechart', '~\A/anime/(\d+)(?:/|\z)~'],
                'animecountdown.com' => ['animecountdown', '~\A/(\d+)(?:/|\z)~'],
            ] as $knownHost => [$knownProvider, $pattern]) {
                if ($host === $knownHost && preg_match($pattern, $pathname, $match) === 1) {
                    $provider = $knownProvider;
                    $externalId = $match[1];
                    break;
                }
            }
            if ($provider === null && in_array($host, ['animenewsnetwork.com', 'anidb.net'], true)) {
                parse_str($parts['query'] ?? '', $query);
                $queryId = $query[$host === 'anidb.net' ? 'aid' : 'id'] ?? null;
                if (is_string($queryId) && ctype_digit($queryId)) {
                    $provider = $host === 'anidb.net' ? 'anidb' : 'animenewsnetwork';
                    $externalId = $queryId;
                }
            }
            if ($provider === null || !is_string($externalId) || $externalId === '' || strlen($externalId) > 255) {
                $provider = 'url';
                $externalId = hash('sha256', $canonical);
            } elseif (ctype_digit($externalId)) {
                $externalId = ltrim($externalId, '0') ?: '0';
            }
            if ($provider === 'mal') {
                $malId = $this->positiveInteger($externalId);
                if ($malId !== null) {
                    $malIds[$malId] = $malId;
                }
            }
            $ids[$provider . ':' . $externalId] = ['provider' => $provider, 'externalId' => $externalId];
        }

        return ['externalIds' => array_values($ids), 'malIds' => array_values($malIds), 'sourceUrl' => $sourceUrl];
    }

    private function genre(string $name): array
    {
        $name = $this->text($name, 100);
        $key = strtolower($name);
        $canonical = $this->genreNames[$key] ?? $name;
        $id = self::GENRE_IDS[$canonical] ?? 100000 + (int) hexdec(substr(hash('sha256', $key), 0, 7));
        if (isset($this->genreKeys[$id]) && $this->genreKeys[$id] !== $key) {
            throw new RuntimeException('Kolizja identyfikatorów słownika gatunków.');
        }
        $this->genreKeys[$id] = $key;
        $this->genreNames[$key] = $canonical;

        return ['sourceId' => $id, 'name' => $canonical];
    }

    private function aliases(array $values, string $title): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = $this->text($value, 1000, false);
            if ($value !== '' && $value !== $title && !isset($seen['alias:' . $value])) {
                $seen['alias:' . $value] = true;
                $result[] = $value;
            }
        }
        if (count($result) > 1000) {
            throw new RuntimeException('Anime ma więcej niż 1000 tytułów alternatywnych.');
        }

        return $result;
    }

    private function cover(mixed $value): ?string
    {
        if ($value === null || $value === '' || (is_string($value) && $this->isMissing($value))) {
            return null;
        }
        $url = is_string($value) ? trim($value) : '';
        $parts = $url !== '' ? parse_url($url) : false;
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        if ($host === 'raw.githubusercontent.com') {
            $this->statistics['placeholderCovers']++;
        }
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !in_array($host, self::COVER_HOSTS, true) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            $this->statistics['invalidCovers']++;
            return null;
        }

        return $url;
    }

    private function text(mixed $value, int $maximum, bool $missingValues = true): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || str_contains($value, "\0") || strlen($value) > $maximum * 8
            || preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Nieprawidłowa wartość tekstowa w pełnym katalogu.');
        }
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = (string) preg_replace('/\h+/u', ' ', $value);
        if (preg_match_all('/./us', $value) > $maximum) {
            throw new RuntimeException('Tekst źródłowy przekracza limit ' . $maximum . ' znaków.');
        }

        return $missingValues && $this->isMissing($value) ? '' : $value;
    }

    private function isMissing(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['', 'unknown', 'n/a', 'null', 'none', 'nan', 'not available'], true);
    }

    private function positiveInteger(mixed $value): ?int
    {
        if ($value === null || (is_string($value) && $this->isMissing($value))) {
            return null;
        }
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 1 && $number <= 2147483647 && floor($number) === $number
            ? (int) $number : null;
    }

    private function year(mixed $value): ?int
    {
        $year = $this->positiveInteger($value);

        return $year !== null && $year >= 1900 && $year <= 2200 ? $year : null;
    }

    private function score(mixed $value): ?float
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value)) {
            return null;
        }
        $score = (float) $value;

        return is_finite($score) && $score >= 0 && $score <= 10 ? $score : null;
    }

    private function countEmitted(array $anime): void
    {
        $this->statistics['recordsEmitted']++;
        $this->statistics['withDescription'] += $anime['description'] !== '' ? 1 : 0;
        $this->statistics['withCover'] += $anime['coverUrl'] !== null ? 1 : 0;
        $this->statistics['withoutMal'] += $anime['malId'] === null ? 1 : 0;
    }
}
