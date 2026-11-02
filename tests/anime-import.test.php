<?php

declare(strict_types=1);

use App\Service\AnimeImportService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$service = new AnimeImportService();
$legacyIds = [20, 1535, 42310, 9253, 16498, 44511];
$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anivibe-anime-import-' . bin2hex(random_bytes(8));
$nestedDirectory = $testDirectory . DIRECTORY_SEPARATOR . 'nested';
$snapshotDirectory = $nestedDirectory . DIRECTORY_SEPARATOR . 'imports';
$snapshotPath = $snapshotDirectory . DIRECTORY_SEPARATOR . 'pilot.json';
$missingPath = $testDirectory . DIRECTORY_SEPARATOR . 'missing-legacy.json';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $action): void {
    try {
        $action();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('Nieprawidłowe dane powinny zostać odrzucone.');
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
$jikanEntry = static fn (int $malId = 20, array $changes = []): array => array_replace([
    'mal_id' => $malId,
    'title' => 'Anime ' . $malId,
    'titles' => [['title' => '86'], ['title' => '日本語の別名']],
    'synopsis' => 'Opis źródłowy anime.',
    'images' => ['jpg' => ['large_image_url' => 'https://cdn.myanimelist.net/images/anime/1/1.jpg']],
    'episodes' => 12,
    'score' => 8.25,
    'studios' => [['name' => 'Studio testowe']],
    'year' => 2020,
    'type' => 'TV',
    'genres' => [['mal_id' => 1, 'name' => 'Action']],
    'themes' => [['mal_id' => 40, 'name' => 'Psychological']],
], $changes);

try {
    if (!mkdir($testDirectory, 0700)) {
        throw new RuntimeException('Nie udało się przygotować katalogu tymczasowego.');
    }

    $run('Normalizacja Jikan zachowuje numeryczne aliasy, Unicode i brakujące metadane', static function () use (
        $service, $jikanEntry, $assert,
    ): void {
        $title = str_repeat('ż', 255);
        $anime = $service->normalize($jikanEntry(20, [
            'title' => $title,
            'synopsis' => null,
            'episodes' => null,
            'score' => null,
        ]));

        $assert($anime['title'] === $title, 'Limit tytułu dotyczy znaków, a nie bajtów UTF-8.');
        $assert(in_array('86', $anime['alternativeTitles'], true), 'Alias 86 musi pozostać ciągiem znaków.');
        $assert(in_array('日本語の別名', $anime['alternativeTitles'], true), 'Nazwy japońskie muszą zachować Unicode.');
        $assert($anime['description'] === '' && $anime['episodes'] === null && $anime['score'] === null,
            'Nieznane dane nie powinny otrzymać wymyślonych wartości.');
        $assert(array_column($anime['genres'], 'sourceId') === [1, 40], 'Gatunki i tematy muszą zachować identyfikatory źródła.');
    });

    $run('Normalizacja snapshotu HF usuwa padding i poprawnie odczytuje liczby oraz gatunki', static function () use (
        $service, $assert,
    ): void {
        $anime = $service->normalize([
            'anime_id' => '20',
            'anime_url' => 'https://myanimelist.net/anime/20',
            'image_url' => '  https://cdn.myanimelist.net/images/anime/1/1.jpg  ',
            'name' => "  Naruto\t  testowe  ",
            'english_name' => '  86  ',
            'japanese_names' => '  日本語  ',
            'score' => '  8.25  ',
            'genres' => "  Action,\tAdventure  ",
            'synopsis' => "  Przykładowy\t opis.  ",
            'type' => '  TV  ',
            'episodes' => 12.0,
            'premiered' => '  fall 2023  ',
            'studios' => '  Pierrot  ',
        ]);

        $assert($anime['malId'] === 20 && $anime['title'] === 'Naruto testowe', 'Źródłowy identyfikator i tytuł muszą zostać oczyszczone.');
        $assert($anime['episodes'] === 12 && $anime['score'] === 8.25 && $anime['year'] === 2023,
            'Poprawne wartości liczbowe muszą zachować znaczenie.');
        $assert($anime['description'] === 'Przykładowy opis.' && $anime['studio'] === 'Pierrot',
            'Padding źródła nie może trafić do katalogu.');
        $assert(in_array('86', $anime['alternativeTitles'], true), 'Numeryczny alias HF musi pozostać tekstem.');
        $assert(array_column($anime['genres'], 'sourceId') === [1, 2], 'Gatunki HF muszą używać kanonicznych ID.');
    });

    $run('Niebezpieczne adresy okładek są odrzucane przed importem', static function () use (
        $service, $jikanEntry, $rejects,
    ): void {
        foreach ([
            'http://cdn.myanimelist.net/images/anime/1/1.jpg',
            'https://example.test/images/anime/1/1.jpg',
            'https://cdn.myanimelist.net.example.test/images/anime/1/1.jpg',
            'https://cdn.myanimelist.net:444/images/anime/1/1.jpg',
        ] as $url) {
            $rejects(static fn () => $service->normalize($jikanEntry(20, [
                'images' => ['jpg' => ['large_image_url' => $url]],
            ])));
        }
    });

    $run('Snapshot bez wymaganego anime jest odrzucany przed importem', static function () use (
        $service, $jikanEntry, $legacyIds, $missingPath, $rejects,
    ): void {
        $ids = $legacyIds;
        $ids[count($ids) - 1] = 999999;
        $payload = json_encode(['data' => array_map($jikanEntry, $ids)], JSON_THROW_ON_ERROR);
        if (file_put_contents($missingPath, $payload) === false) {
            throw new RuntimeException('Nie udało się zapisać testowego snapshotu.');
        }

        $rejects(static fn () => $service->fromFile($missingPath, 6));
    });

    $run('Zapis tworzy zagnieżdżony katalog i pozwala ponownie wczytać sześć anime', static function () use (
        $service, $jikanEntry, $legacyIds, $snapshotPath, $assert,
    ): void {
        $entries = array_map(static fn (int $id): array => $service->normalize($jikanEntry($id)), $legacyIds);
        $service->saveFile($snapshotPath, $entries);
        $assert(is_file($snapshotPath), 'Zapis powinien utworzyć katalog docelowy i snapshot.');
        $loaded = $service->fromFile($snapshotPath, 6);

        $assert(count($loaded) === 6 && array_column($loaded, 'malId') === $legacyIds,
            'Ponowne wczytanie musi zachować komplet dotychczasowych ID.');
        foreach ($loaded as $entry) {
            $assert(in_array('86', $entry['alternativeTitles'], true), 'Alias 86 musi przetrwać zapis i ponowny odczyt.');
            $assert($entry['description'] === 'Opis źródłowy anime.', 'Opis musi przetrwać zapis snapshotu.');
        }
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie testów importera: ' . $exception->getMessage() . PHP_EOL);
} finally {
    $resolvedDirectory = realpath($testDirectory);
    if ($resolvedDirectory !== false) {
        foreach ([$missingPath, $snapshotPath] as $file) {
            $resolvedFile = realpath($file);
            if ($resolvedFile !== false && is_file($file)
                && str_starts_with($resolvedFile, $resolvedDirectory . DIRECTORY_SEPARATOR)) {
                if (!unlink($file)) {
                    $failed++;
                }
            }
        }
        foreach ([$snapshotDirectory, $nestedDirectory, $testDirectory] as $directory) {
            $resolvedPath = realpath($directory);
            if ($resolvedPath !== false && is_dir($directory) && !is_link($directory)
                && ($resolvedPath === $resolvedDirectory
                    || str_starts_with($resolvedPath, $resolvedDirectory . DIRECTORY_SEPARATOR))) {
                if (!rmdir($directory)) {
                    $failed++;
                }
            }
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
