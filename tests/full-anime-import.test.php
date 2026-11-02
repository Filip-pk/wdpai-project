<?php

declare(strict_types=1);

use App\Service\FullAnimeImportService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anivibe-full-import-' . bin2hex(random_bytes(8));
$writtenFiles = [];
$columns = [
    'mal_id', 'title', 'title_english', 'title_japanese', 'title_synonyms', 'synopsis',
    'image_jpg_large_url', 'episodes', 'score', 'year', 'aired_from', 'studios', 'genres', 'themes',
];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
};
$writeFile = static function (string $name, string $contents) use ($testDirectory, &$writtenFiles): string {
    $path = $testDirectory . DIRECTORY_SEPARATOR . $name;
    $writtenFiles[] = $path;
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Nie udało się zapisać pliku testowego.');
    }

    return $path;
};
$aodEntry = static fn (string $url, string $title, array $changes = []): array => array_replace([
    'sources' => [$url],
    'title' => $title,
    'type' => 'TV',
    'episodes' => 220,
    'status' => 'FINISHED',
    'animeSeason' => ['season' => 'FALL', 'year' => 2002],
    'picture' => 'https://cdn.myanimelist.net/images/anime/1/1.jpg',
    'thumbnail' => 'https://cdn.myanimelist.net/images/anime/1/1t.jpg',
    'synonyms' => ['Core alias'],
    'studios' => ['Pierrot'],
    'producers' => [],
    'relatedAnime' => [],
    'tags' => ['action', 'adventure'],
], $changes);
$metadataEntry = static fn (int $malId, array $changes = []): array => array_replace([
    'mal_id' => $malId,
    'title' => 'Metadata anime ' . $malId,
    'title_english' => 'English metadata ' . $malId,
    'title_japanese' => '日本語 ' . $malId,
    'title_synonyms' => '["Metadata alias"]',
    'synopsis' => 'A complete description from the metadata snapshot.',
    'image_jpg_large_url' => 'https://cdn.myanimelist.net/images/anime/1/1l.jpg',
    'episodes' => 12,
    'score' => 8.4,
    'year' => 1999,
    'aired_from' => '1999-01-01T00:00:00+00:00',
    'studios' => 'Pierrot',
    'genres' => 'Action, Adventure',
    'themes' => 'Psychological',
], $changes);
$writeManifest = static function (string $prefix, iterable $aodRows, array $metadataRows) use (
    $testDirectory, &$writtenFiles, $columns, $writeFile,
): string {
    $aodName = $prefix . '-aod.jsonl';
    $aodPath = $testDirectory . DIRECTORY_SEPARATOR . $aodName;
    $writtenFiles[] = $aodPath;
    $stream = fopen($aodPath, 'wb');
    if ($stream === false) {
        throw new RuntimeException('Nie udało się przygotować JSONL testu.');
    }
    try {
        fwrite($stream, json_encode(['lastUpdate' => '2026-11-01'], JSON_THROW_ON_ERROR) . PHP_EOL);
        foreach ($aodRows as $row) {
            if (fwrite($stream, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL) === false) {
                throw new RuntimeException('Nie udało się zapisać rekordu testowego.');
            }
        }
    } finally {
        fclose($stream);
    }

    $csvName = $prefix . '-metadata.csv';
    $csvPath = $testDirectory . DIRECTORY_SEPARATOR . $csvName;
    $writtenFiles[] = $csvPath;
    $stream = fopen($csvPath, 'wb');
    if ($stream === false) {
        throw new RuntimeException('Nie udało się przygotować CSV testu.');
    }
    try {
        fputcsv($stream, $columns, ',', '"', '');
        foreach ($metadataRows as $row) {
            $values = array_map(static fn (string $column): mixed => $row[$column] ?? '', $columns);
            if (fputcsv($stream, $values, ',', '"', '') === false) {
                throw new RuntimeException('Nie udało się zapisać metadanych testowych.');
            }
        }
    } finally {
        fclose($stream);
    }

    $manifest = [
        'version' => 1,
        'fetchedAt' => gmdate(DATE_ATOM),
        'sources' => [
            'aod' => [
                'path' => $aodName,
                'sha256' => hash_file('sha256', $aodPath),
                'release' => 'test-release',
                'url' => 'https://github.com/cedya77/anime-offline-database/releases/tag/test-release',
            ],
            'metadata' => [
                'dataset' => 'moviebrain01/anime-dataset-2025',
                'url' => 'https://huggingface.co/datasets/moviebrain01/anime-dataset-2025',
                'files' => [['path' => $csvName, 'sha256' => hash_file('sha256', $csvPath)]],
            ],
        ],
    ];

    return $writeFile($prefix . '-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
};

try {
    if (!mkdir($testDirectory, 0700)) {
        throw new RuntimeException('Nie udało się przygotować katalogu tymczasowego.');
    }

    $run('Pełny import łączy AOD z opisami HF i zachowuje anime bez MAL oraz dodatkowe metadane', static function () use (
        $aodEntry, $metadataEntry, $writeManifest, $assert,
    ): void {
        $manifest = $writeManifest('enriched', [
            $aodEntry('https://myanimelist.net/anime/20', 'Naruto core'),
            $aodEntry('https://anilist.co/anime/1001', 'Anime without MAL'),
        ], [$metadataEntry(20), $metadataEntry(999)]);
        $service = new FullAnimeImportService();
        $records = iterator_to_array($service->records($manifest), false);
        $assert(count($records) === 3, 'Pozycje AOD oraz metadane spoza AOD muszą trafić do jednego katalogu.');
        $assert(($service->report()['recordsEmitted'] ?? null) === 3,
            'Raport musi podawać liczbę faktycznie udostępnionych rekordów.');
        $byMal = [];
        $withoutMal = [];
        foreach ($records as $record) {
            if ($record['malId'] === null) {
                $withoutMal[] = $record;
            } else {
                $byMal[$record['malId']] = $record;
            }
        }
        $assert(isset($byMal[20], $byMal[999]), 'Wspólny identyfikator łączy dane, a dodatkowe anime zostaje dołączone.');
        $assert($byMal[20]['episodes'] === 220 && $byMal[20]['year'] === 2002,
            'Aktualne podstawowe fakty AOD mają pierwszeństwo przed starym snapshotem.');
        $assert($byMal[20]['description'] === 'A complete description from the metadata snapshot.'
            && $byMal[20]['score'] === 8.4, 'HF uzupełnia opis i ocenę anime.');
        $genreNames = array_column($byMal[20]['genres'], 'name');
        $assert(in_array('Action', $genreNames, true) && in_array('Adventure', $genreNames, true),
            'Uporządkowane gatunki metadanych muszą przetrwać wzbogacenie.');
        $assert(count($withoutMal) === 1, 'Brak MAL ID nie może usuwać anime z pełnego zbioru.');
        $assert(in_array(['provider' => 'anilist', 'externalId' => '1001'], $withoutMal[0]['externalIds'], true),
            'Anime bez MAL musi zachować trwałe powiązanie z zewnętrznym źródłem.');
    });

    $run('Powtórzone metadane HF zachowują bogaty opis, ocenę i aliasy obu rekordów', static function () use (
        $aodEntry, $metadataEntry, $writeManifest, $assert,
    ): void {
        $manifest = $writeManifest('duplicates', [$aodEntry('https://myanimelist.net/anime/20', 'Naruto core')], [
            $metadataEntry(20, ['title_synonyms' => '["Rich alias"]']),
            $metadataEntry(20, [
                'synopsis' => null, 'score' => null, 'image_jpg_large_url' => null,
                'title_synonyms' => '["Sparse alias"]',
            ]),
        ]);
        $service = new FullAnimeImportService();
        $records = iterator_to_array($service->records($manifest), false);
        $assert(count($records) === 1, 'Powtórzony MAL ID nie może utworzyć drugiej pozycji katalogu.');
        $anime = $records[0];
        $assert($anime['description'] === 'A complete description from the metadata snapshot.'
            && $anime['score'] === 8.4, 'Puste pole kolejnego rekordu nie może nadpisać znanych danych.');
        $assert(in_array('Rich alias', $anime['alternativeTitles'], true)
            && in_array('Sparse alias', $anime['alternativeTitles'], true), 'Aliasy obu rekordów muszą być zachowane.');
    });

    $run('Nieprawidłowa suma pliku i ścieżka wychodząca z manifestu są odrzucane', static function () use (
        $aodEntry, $writeManifest, $writeFile, $assert,
    ): void {
        $validPath = $writeManifest('integrity', [$aodEntry('https://anilist.co/anime/1001', 'Local fixture')], []);
        $manifest = json_decode((string) file_get_contents($validPath), true, 64, JSON_THROW_ON_ERROR);
        foreach (['sha256', 'path'] as $field) {
            $invalid = $manifest;
            $invalid['sources']['aod'][$field] = $field === 'sha256' ? str_repeat('0', 64) : '../outside.jsonl';
            $path = $writeFile('invalid-' . $field . '.json', json_encode($invalid, JSON_THROW_ON_ERROR));
            $caught = null;
            try {
                iterator_to_array((new FullAnimeImportService())->records($path), false);
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }
            $assert($caught !== null, 'Nieprawidłowe ' . $field . ' musi przerwać odczyt źródła.');
        }
    });

    $run('Generator pełnego katalogu odczytuje więcej niż dawny limit 5000 anime', static function () use (
        $aodEntry, $writeManifest, $assert,
    ): void {
        $rows = (static function () use ($aodEntry): Generator {
            for ($number = 0; $number < 6001; $number++) {
                yield $aodEntry('https://anilist.co/anime/' . (100000 + $number), 'Streaming anime ' . $number);
            }
        })();
        $manifest = $writeManifest('stream', $rows, []);
        $records = (new FullAnimeImportService())->records($manifest);
        $assert($records instanceof Generator, 'Pełny importer powinien udostępniać strumień rekordów.');
        $count = 0;
        $last = null;
        foreach ($records as $record) {
            $count++;
            $last = $record;
        }
        $assert($count === 6001, 'Pełny odczyt nie może kończyć się na pilotażowym limicie 5000.');
        $assert($last !== null && $last['title'] === 'Streaming anime 6000' && $last['malId'] === null,
            'Ostatni rekord strumienia musi zachować dane anime bez MAL ID.');
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie testów pełnego importu: ' . $exception->getMessage() . PHP_EOL);
} finally {
    $resolvedDirectory = realpath($testDirectory);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolvedDirectory !== false && $temporaryRoot !== false && !is_link($testDirectory)
        && str_starts_with($resolvedDirectory, $temporaryRoot . DIRECTORY_SEPARATOR)) {
        foreach (array_unique($writtenFiles) as $file) {
            $resolvedFile = realpath($file);
            if ($resolvedFile !== false && is_file($file) && !is_link($file)
                && dirname($resolvedFile) === $resolvedDirectory && !unlink($file)) {
                $failed++;
            }
        }
        if (!rmdir($testDirectory)) {
            $failed++;
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
