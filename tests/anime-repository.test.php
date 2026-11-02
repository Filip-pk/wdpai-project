<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AnimeRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$entry = static fn (int $malId, string $title, array $changes = []): array => array_replace([
    'malId' => $malId,
    'title' => $title,
    'alternativeTitles' => [],
    'description' => 'Opis źródłowy anime.',
    'descriptionLanguage' => 'en',
    'coverUrl' => 'https://cdn.myanimelist.net/images/anime/1/1.jpg',
    'episodes' => 12,
    'score' => 8.25,
    'studio' => 'Studio testowe',
    'year' => 2020,
    'type' => 'TV',
    'sourceUrl' => 'https://myanimelist.net/anime/' . $malId,
    'genres' => [['sourceId' => 1, 'name' => 'Action']],
], $changes);

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();

    foreach (['anime', 'genres', 'anime_genres', 'anime_external_ids'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND oid <> to_regclass(:public_table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Test musi używać tabeli tymczasowej.');
    }
    foreach (['anime', 'genres'] as $table) {
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = pg_get_serial_sequence(:table, 'id')::regclass
             AND oid <> pg_get_serial_sequence(:public_table, 'id')::regclass
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Sekwencja testu musi być niezależna od katalogu.');
    }

    $repository = new AnimeRepository($connection);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT anime_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT anime_repository_test');
            $connection->exec('RELEASE SAVEPOINT anime_repository_test');
        }
    };

    $run('Pusty katalog i nieznane ID zwracają puste wyniki', static function () use ($repository, $assert): void {
        $page = $repository->search();
        $assert($repository->count() === 0 && $repository->genres() === []);
        $assert($page['animes'] === [] && $page['total'] === 0);
        $assert($page['page'] === 1 && $page['totalPages'] === 1);
        $assert($repository->findById(PHP_INT_MAX) === null);
        $assert($repository->findByIds([]) === []);
    });

    $run('Import zapisuje dane źródłowe oraz dopuszcza nieznaną ocenę i liczbę odcinków', static function () use (
        $repository, $entry, $assert,
    ): void {
        $source = $entry(101, "Żółć O'Reilly <script>test</script>", [
            'description' => "Opis <b>anime</b> & jego bohaterów.",
            'score' => null,
            'episodes' => null,
        ]);
        $result = $repository->importBatch([$source]);
        $assert($result['inserted'] === 1 && $result['updated'] === 0 && $result['total'] === 1);
        $page = $repository->search('Żółć');
        $id = array_key_first($page['animes']);
        $anime = $repository->findById($id);
        $assert($anime !== null && $anime['title'] === $source['title']);
        $assert($anime['description'] === $source['description']);
        $assert($anime['coverUrl'] === $source['coverUrl'] && $anime['descriptionLanguage'] === 'en');
        $assert($anime['episodes'] === null && $anime['score'] === null);
        $assert($anime['studio'] === $source['studio'] && $anime['year'] === 2020);
        $assert(count($anime['genres']) === 1);
    });

    $run('Ponowny import aktualizuje po MAL ID i zachowuje wewnętrzne ID', static function () use (
        $repository, $entry, $assert, $connection,
    ): void {
        $repository->importBatch([$entry(102, 'Pierwszy tytuł')]);
        $id = array_key_first($repository->search()['animes']);
        $result = $repository->importBatch([$entry(102, 'Nowy tytuł', [
            'genres' => [['sourceId' => 4, 'name' => 'Comedy']],
            'description' => 'Zaktualizowany opis.',
        ])]);
        $assert($result['inserted'] === 0 && $result['updated'] === 1 && $repository->count() === 1);
        $assert($repository->findById($id)['title'] === 'Nowy tytuł');
        $assert($repository->findById($id)['description'] === 'Zaktualizowany opis.');
        $assert((int) $connection->query('SELECT count(*) FROM anime_genres')->fetchColumn() === 1);
        $assert($repository->search('Pierwszy')['total'] === 0);
    });

    $run('Wyszukiwanie obejmuje aliasy, polskie znaki i pomija wielkość liter', static function () use (
        $repository, $entry, $assert,
    ): void {
        $repository->importBatch([
            $entry(103, 'Shingeki no Kyojin', ['alternativeTitles' => ['Attack on Titan', 'Zażółć gęślą jaźń']]),
            $entry(104, 'Naruto'),
        ]);
        foreach (['shingeki', 'ATTACK ON TITAN', 'ZAŻÓŁĆ'] as $query) {
            $page = $repository->search($query);
            $assert($page['total'] === 1 && reset($page['animes'])['title'] === 'Shingeki no Kyojin');
        }
    });

    $run('Znaki %, _ i fragmenty SQL pozostają dosłowną treścią zapytania', static function () use (
        $repository, $entry, $assert,
    ): void {
        $repository->importBatch([
            $entry(105, '100% Real'), $entry(106, '1000 Real'),
            $entry(107, 'Under_score'), $entry(108, 'UnderXscore'),
            $entry(109, "O'Reilly"),
            $entry(113, 'Escaped aliases', ['alternativeTitles' => ['He said "Hello"', 'Path\\Name']]),
        ]);
        $assert($repository->search('%')['total'] === 1);
        $assert($repository->search('_')['total'] === 1);
        $assert($repository->search("O'Reilly")['total'] === 1);
        $assert($repository->search('"Hello"')['total'] === 1);
        $assert($repository->search('Path\\Name')['total'] === 1);
        $assert($repository->search("' OR 1=1 --")['total'] === 0);
        $assert($repository->count() === 6);
    });

    $run('Gatunek i tytuł filtrują wspólnie, bez powielania wyników', static function () use (
        $repository, $entry, $assert, $connection,
    ): void {
        $repository->importBatch([
            $entry(110, 'Naruto Action', ['genres' => [['sourceId' => 1, 'name' => 'Action'], ['sourceId' => 2, 'name' => 'Adventure']]]),
            $entry(111, 'Naruto Comedy', ['genres' => [['sourceId' => 4, 'name' => 'Comedy']]]),
            $entry(112, 'Other Action'),
        ]);
        $genreId = (int) $connection->query('SELECT id FROM genres WHERE source_id = 1')->fetchColumn();
        $assert($repository->search('', $genreId)['total'] === 2);
        $page = $repository->search('naruto', $genreId);
        $assert($page['total'] === 1 && reset($page['animes'])['title'] === 'Naruto Action');
        $assert($repository->search('', PHP_INT_MAX)['total'] === 0);
    });

    $run('Paginacja jest rozłączna i ogranicza nieistniejącą stronę do ostatniej', static function () use (
        $repository, $entry, $assert,
    ): void {
        $batch = [];
        for ($number = 1; $number <= 25; $number++) {
            $batch[] = $entry(200 + $number, sprintf('Anime %03d', $number));
        }
        $repository->importBatch($batch);
        $first = $repository->search();
        $second = $repository->search('', null, 2);
        $last = $repository->search('', null, 9999);
        $assert($first['total'] === 25 && $first['totalPages'] === 2 && count($first['animes']) === 20);
        $assert($second['page'] === 2 && count($second['animes']) === 5);
        $assert(array_intersect_key($first['animes'], $second['animes']) === []);
        $assert($last['page'] === 2 && array_keys($last['animes']) === array_keys($second['animes']));
        $assert($repository->search('', null, 0)['page'] === 1);
    });

    $run('Odczyt wielu ID zwraca istniejące anime i pomija brakujące', static function () use (
        $repository, $entry, $assert,
    ): void {
        $repository->importBatch([$entry(301, 'Pierwsze anime'), $entry(302, 'Drugie anime')]);
        $ids = array_keys($repository->search()['animes']);
        $found = $repository->findByIds([$ids[0], $ids[0], PHP_INT_MAX, $ids[1]]);
        $assert(count($found) === 2 && isset($found[$ids[0]], $found[$ids[1]]));
    });

    $run('Anime bez MAL ID zachowuje własne ID przy ponownym imporcie po źródle zewnętrznym', static function () use (
        $repository, $entry, $assert, $connection,
    ): void {
        $source = $entry(501, 'Tytuł bez MAL', [
            'malId' => null,
            'sourceUrl' => 'https://anilist.co/anime/123',
            'externalIds' => [['provider' => 'anilist', 'externalId' => '123']],
        ]);
        $stream = (static function () use ($source): Generator {
            yield $source;
        })();
        $result = $repository->importBatch($stream);
        $id = array_key_first($repository->search()['animes']);
        $assert($result['inserted'] === 1 && $result['total'] === 1);
        $assert($repository->findById($id)['malId'] === null);

        $result = $repository->importBatch([array_replace($source, ['title' => 'Nowa nazwa bez MAL'])]);
        $assert($result['inserted'] === 0 && $result['updated'] === 1 && $result['total'] === 1);
        $assert($repository->findById($id)['title'] === 'Nowa nazwa bez MAL');
        $mapping = $connection->query(
            "SELECT anime_id FROM anime_external_ids WHERE provider = 'anilist' AND external_id = '123'"
        )->fetchColumn();
        $assert((int) $mapping === $id);
        $assert((int) $connection->query('SELECT count(*) FROM anime_external_ids')->fetchColumn() === 1);
    });

    $run('Późniejsze powiązanie MAL uzupełnia ten sam rekord bez duplikatu anime', static function () use (
        $repository, $entry, $assert, $connection,
    ): void {
        $repository->importBatch([$entry(502, 'Anime z AniList', [
            'malId' => null,
            'sourceUrl' => 'https://anilist.co/anime/124',
            'externalIds' => [['provider' => 'anilist', 'externalId' => '124']],
        ])]);
        $id = array_key_first($repository->search()['animes']);

        $result = $repository->importBatch([$entry(1502, 'Anime z powiązaniem MAL', [
            'externalIds' => [
                ['provider' => 'anilist', 'externalId' => '124'],
                ['provider' => 'mal', 'externalId' => '1502'],
            ],
        ])]);
        $assert($result['inserted'] === 0 && $result['updated'] === 1 && $repository->count() === 1);
        $assert($repository->findById($id)['malId'] === 1502);
        $mappings = $connection->query('SELECT anime_id FROM anime_external_ids')->fetchAll(PDO::FETCH_COLUMN);
        $assert(count($mappings) === 2 && count(array_unique(array_map('intval', $mappings))) === 1);
        $assert((int) $mappings[0] === $id);
    });

    $run('Uboga aktualizacja zachowuje opis i znane fakty oraz łączy aliasy i gatunki', static function () use (
        $repository, $entry, $assert,
    ): void {
        $rich = $entry(1503, 'Wzbogacone anime', [
            'description' => 'Znany polski opis fabuły.',
            'descriptionLanguage' => 'pl',
            'alternativeTitles' => ['Rich alias'],
            'score' => 9.1,
        ]);
        $repository->importBatch([$rich]);
        $id = array_key_first($repository->search()['animes']);

        $repository->importBatch([$entry(1503, 'Wzbogacone anime', [
            'description' => '',
            'descriptionLanguage' => 'en',
            'coverUrl' => null,
            'episodes' => null,
            'score' => null,
            'year' => null,
            'alternativeTitles' => ['Sparse alias'],
            'genres' => [['sourceId' => 4, 'name' => 'Comedy']],
        ])], true);
        $anime = $repository->findById($id);
        $assert($repository->count() === 1 && $anime['description'] === $rich['description']);
        $assert($anime['descriptionLanguage'] === 'pl');
        foreach (['coverUrl', 'episodes', 'score', 'year'] as $field) {
            $assert($anime[$field] === $rich[$field], 'Uboga aktualizacja nie może usunąć znanego pola ' . $field . '.');
        }
        $assert(in_array('Rich alias', $anime['alternativeTitles'], true)
            && in_array('Sparse alias', $anime['alternativeTitles'], true));
        $assert(count($anime['genres']) === 2
            && in_array('Akcja', $anime['genres'], true) && in_array('Komedia', $anime['genres'], true));
    });

    $run('Sprzeczne powiązania źródeł przerywają partię bez zmiany istniejących anime', static function () use (
        $repository, $entry, $assert, $connection,
    ): void {
        $repository->importBatch([
            $entry(1701, 'Pierwsze anime', ['externalIds' => [
                ['provider' => 'mal', 'externalId' => '1701'],
                ['provider' => 'anilist', 'externalId' => '7001'],
            ]]),
            $entry(1702, 'Drugie anime', ['externalIds' => [
                ['provider' => 'mal', 'externalId' => '1702'],
                ['provider' => 'anilist', 'externalId' => '7002'],
            ]]),
        ]);
        $caught = null;
        try {
            $repository->importBatch([
                $entry(1703, 'Nieutrwalony nowy wpis'),
                $entry(1701, 'Sprzeczna aktualizacja', ['externalIds' => [
                    ['provider' => 'mal', 'externalId' => '1701'],
                    ['provider' => 'anilist', 'externalId' => '7002'],
                ]]),
            ]);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $assert($caught !== null && $repository->count() === 2);
        $assert($repository->search('Pierwsze anime')['total'] === 1
            && $repository->search('Drugie anime')['total'] === 1);
        $assert($repository->search('Nieutrwalony')['total'] === 0
            && $repository->search('Sprzeczna')['total'] === 0);
        $assert((int) $connection->query('SELECT count(*) FROM anime_external_ids')->fetchColumn() === 4);
    });

    $run('Błąd wewnątrz partii nie zapisuje części importu', static function () use ($repository, $entry, $assert): void {
        $caught = null;
        try {
            $repository->importBatch([$entry(401, 'Poprawny wpis'), $entry(402, str_repeat('x', 256))]);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $assert($caught !== null && $repository->count() === 0);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować izolowanych testów katalogu: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
