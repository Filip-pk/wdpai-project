<?php

declare(strict_types=1);

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\WatchlistRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach (['users', 'anime', 'user_watchlist', 'user_anime_experience', 'anime_collections', 'anime_collection_items'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $statement = $connection->prepare(
            'SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND oid <> to_regclass(:public_table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = \'t\''
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Test musi używać tabel tymczasowych.');
    }
    $assert((int) $connection->query(
        "SELECT count(*) FROM pg_class
         WHERE oid = pg_get_serial_sequence('anime_collections', 'id')::regclass
         AND oid <> pg_get_serial_sequence('public.anime_collections', 'id')::regclass
         AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
    )->fetchColumn() === 1, 'Sekwencja kolekcji musi być niezależna od danych użytkowników.');
    $connection->exec(
        'ALTER TABLE pg_temp.anime_collections ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE;
         ALTER TABLE pg_temp.anime_collection_items
             ADD FOREIGN KEY (collection_id) REFERENCES pg_temp.anime_collections (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id) ON DELETE CASCADE'
    );
    $connection->exec(
        "INSERT INTO users (id, name, email, password_hash)
         VALUES (101, 'Pierwszy', 'pierwszy@example.test', 'hash'), (202, 'Drugi', 'drugi@example.test', 'hash')"
    );
    $statement = $connection->prepare('INSERT INTO anime (id, title, cover_url) VALUES (:id, :title, :cover)');
    for ($id = 1; $id <= 30; $id++) {
        $statement->execute([
            'id' => $id, 'title' => 'Anime ' . $id,
            'cover' => $id % 2 === 0 ? 'https://example.test/' . $id . '.jpg' : null,
        ]);
    }
    $collections = new CollectionRepository($connection);
    $watchlists = new WatchlistRepository($connection);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT collection_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT collection_repository_test');
            $connection->exec('RELEASE SAVEPOINT collection_repository_test');
        }
    };

    $run('Każde konto zaczyna z jedną własną domyślną kolekcją, bez duplikatów', static function () use (
        $collections, $assert,
    ): void {
        foreach ([101, 202] as $userId) {
            $collections->ensureDefaultForUser($userId);
            $collections->ensureDefaultForUser($userId);
            $own = $collections->findForUser($userId);
            $assert(count($own) === 1 && $own[0]['name'] === 'moja kolekcja');
            $assert($own[0]['isDefault'] === true && $own[0]['animeCount'] === 0 && $own[0]['previews'] === []);
        }
        $assert($collections->findForUser(101)[0]['id'] !== $collections->findForUser(202)[0]['id']);
    });

    $run('Odczyt, edycja i zmiana zawartości nie dopuszczają cudzych kolekcji', static function () use (
        $collections, $assert,
    ): void {
        $foreignId = $collections->createForUser(101, 'Prywatna nazwa', 'Prywatny opis');
        $assert($collections->addAnimeForUser(101, $foreignId, 1));
        $assert($collections->findForUser(202) === []);
        $assert($collections->findByIdForUser(202, $foreignId) === null);
        $assert(!$collections->updateForUser(202, $foreignId, 'Atak', 'Atak'));
        $assert(!$collections->addAnimeForUser(202, $foreignId, 2));
        $assert(!$collections->removeAnimeForUser(202, $foreignId, 1));
        $assert($collections->animeIdsForUser(202, $foreignId)['animeIds'] === []);
        $assert($collections->collectionIdsForAnime(202, 1) === []);
        $own = $collections->findByIdForUser(101, $foreignId);
        $assert($own['name'] === 'Prywatna nazwa' && $own['description'] === 'Prywatny opis' && $own['animeCount'] === 1);
    });

    $run('Wiele kolekcji przyjmuje ten sam tytuł bez powielania przypisań', static function () use (
        $collections, $assert,
    ): void {
        $firstId = $collections->createForUser(101, 'Weekend', '');
        $secondId = $collections->createForUser(101, 'Ze znajomymi', '');
        $assert($collections->addAnimeToManyForUser(101, [$firstId, $secondId, $firstId], 1));
        $assert($collections->addAnimeToManyForUser(101, [$firstId, $secondId], 1));
        $assert($collections->collectionIdsForAnime(101, 1) === [$firstId, $secondId]);
        $assert(array_column($collections->findForUser(101), 'animeCount') === [1, 1]);
    });

    $run('Utworzenie kolekcji wraz z anime jest atomowe', static function () use (
        $collections, $assert,
    ): void {
        $id = $collections->createWithAnimeForUser(101, 'Na weekend', 'Nowa lista', 1);
        $assert($id > 0 && $collections->collectionIdsForAnime(101, 1) === [$id]);
        $collection = $collections->findByIdForUser(101, $id);
        $assert($collection['name'] === 'Na weekend' && $collection['animeCount'] === 1);
        $assert($collections->findForUser(202) === []);
        $assert($collections->createWithAnimeForUser(101, 'Błędna lista', '', PHP_INT_MAX) === 0);
        $assert(count($collections->findForUser(101)) === 1);
    });

    $run('Obce ID w wyborze wielu kolekcji odrzuca cały zapis', static function () use (
        $collections, $assert,
    ): void {
        $ownId = $collections->createForUser(101, 'Własna', '');
        $foreignId = $collections->createForUser(202, 'Cudza', '');
        $assert(!$collections->addAnimeToManyForUser(101, [$ownId, $foreignId], 1));
        $assert($collections->collectionIdsForAnime(101, 1) === []);
        $assert($collections->collectionIdsForAnime(202, 1) === []);
        $assert(!$collections->addAnimeToManyForUser(101, [$ownId, PHP_INT_MAX], 1));
        $assert(!$collections->addAnimeForUser(101, $ownId, PHP_INT_MAX));
        foreach ([[], ['1'], [0], [-1]] as $invalid) {
            $assert(!$collections->addAnimeToManyForUser(101, $invalid, 1));
        }
        $assert($collections->findByIdForUser(101, $ownId)['animeCount'] === 0);
    });

    $run('Status i postęp watchlisty pozostają niezależne od kolekcji', static function () use (
        $collections, $watchlists, $assert,
    ): void {
        $watchlists->saveForUser(101, 1, 'watching', 4);
        $before = $watchlists->findForUser(101);
        $firstId = $collections->createForUser(101, 'Weekend', '');
        $secondId = $collections->createForUser(101, 'Powroty', '');
        $assert($collections->addAnimeToManyForUser(101, [$firstId, $secondId], 1));
        $assert($collections->addAnimeForUser(101, $firstId, 2));
        $assert($collections->removeAnimeForUser(101, $firstId, 1));
        $assert($collections->collectionIdsForAnime(101, 1) === [$secondId]);
        $assert($watchlists->findForUser(101) === $before);
        $watchlists->removeForUser(101, 1);
        $assert($collections->collectionIdsForAnime(101, 1) === [$secondId]);
    });

    $run('Podgląd zawiera do czterech miniatur i pełny licznik', static function () use (
        $collections, $assert,
    ): void {
        $id = $collections->createForUser(101, 'Filmy', 'Krótki opis');
        foreach (range(1, 6) as $animeId) {
            $assert($collections->addAnimeForUser(101, $id, $animeId));
        }
        $collection = $collections->findForUser(101)[0];
        $assert($collection['animeCount'] === 6 && count($collection['previews']) === 4);
        $assert(array_column($collection['previews'], 'id') === [6, 5, 4, 3]);
        $assert($collection['previews'][0]['title'] === 'Anime 6');
        $assert($collection['previews'][0]['coverUrl'] === 'https://example.test/6.jpg');
        $assert($collection['previews'][1]['coverUrl'] === null);
    });

    $run('Paginacja ogranicza odczyt i koryguje numer strony', static function () use (
        $collections, $assert,
    ): void {
        $id = $collections->createForUser(101, 'Wszystkie', '');
        foreach (range(1, 30) as $animeId) {
            $collections->addAnimeForUser(101, $id, $animeId);
        }
        $first = $collections->animeIdsForUser(101, $id);
        $second = $collections->animeIdsForUser(101, $id, 2);
        $assert($first['total'] === 30 && $first['totalPages'] === 2 && count($first['animeIds']) === 24);
        $assert(count($second['animeIds']) === 6 && $second['page'] === 2);
        $assert(count(array_unique([...$first['animeIds'], ...$second['animeIds']])) === 30);
        $assert($collections->animeIdsForUser(101, $id, PHP_INT_MAX)['page'] === 2);
        $assert($collections->animeIdsForUser(101, $id, -1)['page'] === 1);
        $assert($collections->animeIdsForUser(101, $id, 1, PHP_INT_MAX)['animeIds'] === range(30, 1));
    });

    $run('Nazwa i opis zachowują znaki polskie, HTML i apostrofy jako dane', static function () use (
        $collections, $assert,
    ): void {
        $name = "Żółć O'Reilly <script>test</script>";
        $description = "Anime na weekend; SELECT 1; -- <b>opis</b>";
        $id = $collections->createForUser(101, $name, $description);
        $collection = $collections->findByIdForUser(101, $id);
        $assert($collection['name'] === $name && $collection['description'] === $description);
        $assert($collections->updateForUser(101, $id, 'Nowa nazwa', 'Nowy opis'));
        $collection = $collections->findByIdForUser(101, $id);
        $assert($collection['name'] === 'Nowa nazwa' && $collection['description'] === 'Nowy opis');
        $assert($collection['isDefault'] === false);
    });

    foreach ([['', ''], [str_repeat('x', 121), ''], ['Nazwa', str_repeat('x', 501)]] as [$name, $description]) {
        $run('Baza odrzuca niepoprawną długość nazwy lub opisu', static function () use (
            $collections, $assert, $name, $description,
        ): void {
            $rejected = false;
            try {
                $collections->createForUser(101, $name, $description);
            } catch (PDOException $exception) {
                $rejected = in_array($exception->getCode(), ['23514', '22001'], true);
            }
            $assert($rejected);
        });
    }
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować izolowanych testów kolekcji: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
