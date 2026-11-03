<?php

declare(strict_types=1);

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\RatingRepository;
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
    foreach (['users', 'anime', 'user_watchlist', 'anime_collections', 'anime_collection_items', 'user_anime_ratings'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND oid <> to_regclass(:public_table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Test musi używać tabel tymczasowych.');
    }
    // BIGSERIAL defaults copied by LIKE would reference the public sequence; users use explicit test IDs.
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    foreach (['anime', 'anime_collections'] as $table) {
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = pg_get_serial_sequence(:table, 'id')::regclass
             AND oid <> pg_get_serial_sequence(:public_table, 'id')::regclass
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Sekwencje testu muszą być niezależne od danych aplikacji.');
    }
    $connection->exec(
        'ALTER TABLE pg_temp.user_anime_ratings
             ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id) ON DELETE CASCADE'
    );
    $connection->exec(
        "INSERT INTO users (id, name, email, password_hash)
         VALUES (101, 'Pierwszy', 'rating-first@example.test', 'hash'),
                (202, 'Drugi', 'rating-second@example.test', 'hash');
         INSERT INTO anime (id, title, score)
         VALUES (1, 'Pierwsze anime', 8.75), (2, 'Drugie anime', 7.25), (3, 'Nieocenione anime', NULL)"
    );
    $ratings = new RatingRepository($connection);
    $collections = new CollectionRepository($connection);
    $watchlists = new WatchlistRepository($connection);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT rating_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT rating_repository_test');
            $connection->exec('RELEASE SAVEPOINT rating_repository_test');
        }
    };

    $run('Każde konto odczytuje wyłącznie własną ocenę tego samego anime', static function () use ($ratings, $assert): void {
        $assert($ratings->findForUser(101, 1) === null);
        $ratings->saveForUser(101, 1, 9);
        $assert($ratings->findForUser(101, 1) === 9);
        $assert($ratings->findForUser(202, 1) === null);
        $ratings->saveForUser(202, 1, 4);
        $assert($ratings->findForUser(101, 1) === 9 && $ratings->findForUser(202, 1) === 4);
        $assert($ratings->findForUser(101, PHP_INT_MAX) === null);
    });

    $run('Ponowny zapis zmienia jedną ocenę bez tworzenia duplikatów', static function () use ($ratings, $connection, $assert): void {
        $ratings->saveForUser(101, 1, 1);
        $ratings->saveForUser(101, 1, 10);
        $ratings->saveForUser(101, 1, 10);
        $assert($ratings->findForUser(101, 1) === 10);
        $assert((int) $connection->query('SELECT count(*) FROM user_anime_ratings')->fetchColumn() === 1);
    });

    $run('Usunięcie własnej oceny nie zmienia oceny drugiego konta ani innego anime', static function () use ($ratings, $assert): void {
        $ratings->saveForUser(101, 1, 9);
        $ratings->saveForUser(101, 2, 6);
        $ratings->saveForUser(202, 1, 4);
        $ratings->removeForUser(101, 1);
        $ratings->removeForUser(101, 1);
        $ratings->removeForUser(101, PHP_INT_MAX);
        $assert($ratings->findForUser(101, 1) === null);
        $assert($ratings->findForUser(202, 1) === 4 && $ratings->findForUser(101, 2) === 6);
    });

    $run('Odczyt zbiorczy filtruje konto, zakres ID i brakujące oceny oraz pomija duplikaty', static function () use ($ratings, $assert): void {
        $ratings->saveForUser(101, 1, 9);
        $ratings->saveForUser(101, 2, 7);
        $ratings->saveForUser(202, 1, 2);
        $ratings->saveForUser(202, 3, 8);
        $assert($ratings->findByAnimeIdsForUser(101, []) === []);
        $assert($ratings->findByAnimeIdsForUser(101, [2, 1, 2, 3, PHP_INT_MAX]) === [1 => 9, 2 => 7]);
        $assert($ratings->findByAnimeIdsForUser(101, [2]) === [2 => 7]);
        $assert($ratings->findByAnimeIdsForUser(202, [1, 2, 3]) === [1 => 2, 3 => 8]);
        $assert($ratings->findByAnimeIdsForUser(999, [1, 2, 3]) === []);
    });

    $run('Repozytorium odrzuca oceny poza zakresem przed wykonaniem SQL', static function () use ($ratings, $assert): void {
        $ratings->saveForUser(101, 1, 8);
        foreach ([0, -1, 11, PHP_INT_MAX] as $invalid) {
            $rejected = false;
            try {
                $ratings->saveForUser(101, 1, $invalid);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $assert($rejected);
        }
        $assert($ratings->findForUser(101, 1) === 8);
    });

    $run('Repozytorium odrzuca niepoprawne identyfikatory w odczycie zbiorczym', static function () use ($ratings, $assert): void {
        foreach ([['1'], [0], [-1], [[1]], [true]] as $invalid) {
            $rejected = false;
            try {
                $ratings->findByAnimeIdsForUser(101, $invalid);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $assert($rejected);
        }
    });

    $run('Oceny nie zmieniają źródłowej oceny, statusu, postępu ani kolekcji', static function () use (
        $ratings, $collections, $watchlists, $connection, $assert,
    ): void {
        $watchlists->saveForUser(101, 1, 'watching', 4);
        $collectionId = $collections->createForUser(101, 'Weekend', 'Prywatny wybór');
        $assert($collections->addAnimeForUser(101, $collectionId, 1));
        $watchlistBefore = $watchlists->findForUser(101);
        $collectionBefore = $collections->findForUser(101);
        $sourceScore = $connection->query('SELECT score FROM anime WHERE id = 1')->fetchColumn();
        $ratings->saveForUser(101, 1, 9);
        $ratings->saveForUser(101, 1, 5);
        $ratings->saveForUser(101, 2, 6);
        $ratings->removeForUser(101, 1);
        $assert($watchlists->findForUser(101) === $watchlistBefore);
        $assert($collections->findForUser(101) === $collectionBefore);
        $assert($connection->query('SELECT score FROM anime WHERE id = 1')->fetchColumn() === $sourceScore);
        $assert($ratings->findForUser(101, 2) === 6);
        $assert($watchlists->findForUser(101) === [['animeId' => 1, 'status' => 'watching', 'watched' => 4]]);
    });

    foreach ([0, 11] as $invalid) {
        $run('Baza odrzuca ocenę poza zakresem 1–10: ' . $invalid, static function () use ($connection, $invalid, $assert): void {
            $rejected = false;
            try {
                $statement = $connection->prepare('INSERT INTO user_anime_ratings (user_id, anime_id, rating) VALUES (101, 1, :rating)');
                $statement->execute(['rating' => $invalid]);
            } catch (PDOException $exception) {
                $rejected = $exception->getCode() === '23514';
            }
            $assert($rejected);
        });
    }

    $run('Baza odrzuca ocenę nieistniejącego anime', static function () use ($ratings, $assert): void {
        $rejected = false;
        try {
            $ratings->saveForUser(101, PHP_INT_MAX, 9);
        } catch (PDOException $exception) {
            $rejected = $exception->getCode() === '23503';
        }
        $assert($rejected);
    });

    $run('Usunięcie konta usuwa jego oceny, zachowując inne konta', static function () use ($ratings, $connection, $assert): void {
        $ratings->saveForUser(101, 1, 9);
        $ratings->saveForUser(202, 1, 6);
        $connection->exec('DELETE FROM pg_temp.users WHERE id = 101');
        $assert($ratings->findForUser(101, 1) === null && $ratings->findForUser(202, 1) === 6);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować izolowanych testów ocen: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
