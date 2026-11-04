<?php

declare(strict_types=1);

use App\Database;
use App\Repository\ExperienceRepository;
use App\Repository\MalImportRepository;
use App\Repository\RatingRepository;
use App\Repository\WatchlistRepository;
use App\Service\MalImportChangedException;
use App\Service\MalImportService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$entry = static fn (int $malId = 1001, string $status = 'watching', int $watched = 3, ?int $rating = 8): array => [
    'malId' => $malId, 'title' => 'Tytuł z eksportu', 'status' => $status, 'watched' => $watched, 'rating' => $rating,
];
$parsed = static fn (array $entries, array $issues = []): array => [
    'entries' => $entries, 'issues' => $issues, 'total' => count($entries) + count($issues),
];
$throws = static function (callable $operation, string $class) use ($assert): void {
    try {
        $operation();
    } catch (Throwable $exception) {
        $assert($exception instanceof $class, 'Nieoczekiwany typ błędu: ' . $exception::class);
        return;
    }
    throw new RuntimeException('Oczekiwano odrzucenia operacji.');
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach (['users', 'anime', 'user_watchlist', 'user_anime_ratings', 'user_anime_experience', 'anime_collections', 'anime_collection_items'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND oid <> to_regclass(:public_table) AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Każda tabela musi być tymczasowa.');
    }
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    foreach (['anime', 'anime_collections'] as $table) {
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = pg_get_serial_sequence(:table, 'id')::regclass
             AND oid <> pg_get_serial_sequence(:public_table, 'id')::regclass
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Sekwencje nie mogą odwoływać się do prawdziwego katalogu.');
    }
    foreach (['user_watchlist', 'user_anime_ratings', 'user_anime_experience'] as $table) {
        $connection->exec('ALTER TABLE pg_temp.' . $table
            . ' ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,'
            . ' ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id)');
    }
    $connection->exec(
        'ALTER TABLE pg_temp.anime_collections ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE;
         ALTER TABLE pg_temp.anime_collection_items
             ADD FOREIGN KEY (collection_id) REFERENCES pg_temp.anime_collections (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id)'
    );
    $connection->exec(
        "INSERT INTO pg_temp.users (id, name, email, password_hash)
         VALUES (101, 'Pierwszy', 'mal-first@example.test', 'hash'), (202, 'Drugi', 'mal-second@example.test', 'hash');
         INSERT INTO pg_temp.anime (id, mal_id, title, episodes)
         VALUES (1, 1001, 'Sezon z katalogu', 12), (2, 1002, 'Film', 1),
                (3, 1003, 'Nieznana długość', NULL), (4, 1004, 'Zero odcinków', 0), (5, 1005, 'Drugi sezon', 24)"
    );
    $service = new MalImportService(new MalImportRepository($connection));
    $watchlists = new WatchlistRepository($connection);
    $ratings = new RatingRepository($connection);
    $experience = new ExperienceRepository($connection);
    $confirm = static function (int $userId, array $source, bool $update = false) use ($service): array {
        $preview = $service->previewForUser($userId, $source, $update);
        return $service->importForUser($userId, $source, $update, $preview['fingerprint']);
    };
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT mal_import_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT mal_import_test');
            $connection->exec('RELEASE SAVEPOINT mal_import_test');
        }
    };

    $run('Podgląd jest tylko do odczytu i dopasowuje po ID MAL zamiast tytule', static function () use ($service, $watchlists, $ratings, $experience, $entry, $parsed, $assert): void {
        $preview = $service->previewForUser(101, $parsed([$entry(), $entry(999999)], [
            ['malId' => null, 'title' => 'Uszkodzony wpis', 'reason' => 'Błędny identyfikator.'],
        ]));
        $assert($preview['counts'] === ['add' => 1, 'update' => 0, 'unchanged' => 0, 'skipped' => 2]);
        $assert($preview['rows'][0]['animeId'] === 1 && $preview['rows'][0]['title'] === 'Sezon z katalogu');
        $assert(strlen($preview['fingerprint']) === 64 && $watchlists->findForUser(101) === []);
        $assert($ratings->findForUser(101, 1) === null && $experience->totalForUser(101) === 0);
    });

    $run('Import zachowuje pięć statusów, własne oceny i poprawnie wylicza EXP', static function () use ($confirm, $watchlists, $ratings, $experience, $entry, $parsed, $assert): void {
        $report = $confirm(101, $parsed([
            $entry(1001, 'completed', 0, 8), $entry(1002, 'planned', 99, null),
            $entry(1003, 'on_hold', 4, 7), $entry(1004, 'dropped', 2, null), $entry(1005, 'watching', 3, 9),
        ]));
        $assert($report['added'] === 5 && $report['ratingsSaved'] === 3 && $report['skipped'] === 0);
        $assert($report['expBefore'] === 0 && $report['expAfter'] === 205 && $experience->totalForUser(101) === 205);
        $rows = $watchlists->findForUser(101);
        $assert(array_column($rows, 'status') === ['completed', 'planned', 'on_hold', 'dropped', 'watching']);
        $assert(array_column($rows, 'watched') === [12, 0, 4, 2, 3]);
        $assert($ratings->findForUser(101, 1) === 8 && $ratings->findForUser(101, 2) === null);
    });

    $run('Import jednego konta nie zmienia drugiego ani prywatnych kolekcji', static function () use ($connection, $confirm, $watchlists, $ratings, $experience, $entry, $parsed, $assert): void {
        $watchlists->saveForUser(202, 1, 'completed', 12);
        $ratings->saveForUser(202, 1, 10);
        $connection->exec("INSERT INTO anime_collections (id, user_id, name) VALUES (1, 202, 'Prywatna kolekcja');
            INSERT INTO anime_collection_items (collection_id, anime_id) VALUES (1, 1)");
        $before = $watchlists->findForUser(202);
        $confirm(101, $parsed([$entry()]));
        $assert($watchlists->findForUser(202) === $before && $ratings->findForUser(202, 1) === 10);
        $assert($experience->totalForUser(101) === 15 && $experience->totalForUser(202) === 160);
        $assert((int) $connection->query('SELECT count(*) FROM anime_collection_items WHERE collection_id = 1 AND anime_id = 1')->fetchColumn() === 1);
    });

    $run('Tryb domyślny zachowuje całe istniejące wpisy i ich oceny', static function () use ($service, $confirm, $watchlists, $ratings, $experience, $entry, $parsed, $assert): void {
        $watchlists->saveForUser(101, 1, 'watching', 5);
        $ratings->saveForUser(101, 1, 9);
        $source = $parsed([$entry(1001, 'completed', 12, 1)]);
        $preview = $service->previewForUser(101, $source);
        $assert($preview['rows'][0]['action'] === 'unchanged' && $preview['rows'][0]['watched'] === 5);
        $assert($preview['rows'][0]['rating'] === 9 && $preview['rows'][0]['status'] === 'watching');
        $report = $confirm(101, $source);
        $assert($report['unchanged'] === 1 && $report['added'] === 0 && $experience->totalForUser(101) === 25);
        $assert($ratings->findForUser(101, 1) === 9);
    });

    $run('Ocena wystawiona poza watchlistą pozostaje zachowana przy dopisywaniu tytułu', static function () use ($confirm, $ratings, $experience, $entry, $parsed, $assert): void {
        $ratings->saveForUser(101, 1, 9);
        $report = $confirm(101, $parsed([$entry(rating: 1)]));
        $assert($report['added'] === 1 && $report['ratingsSaved'] === 0);
        $assert($ratings->findForUser(101, 1) === 9 && $experience->totalForUser(101) === 15);
    });

    $run('Stary eksport nie zmniejsza zachowanego EXP po usunięciu z watchlisty', static function () use ($confirm, $watchlists, $experience, $entry, $parsed, $assert): void {
        $watchlists->saveForUser(101, 1, 'completed', 12);
        $watchlists->saveForUser(101, 5, 'watching', 10);
        $watchlists->removeForUser(101, 1);
        $watchlists->removeForUser(101, 5);
        $source = $parsed([$entry(1001, 'planned', 0), $entry(1005, 'watching', 4)]);
        $report = $confirm(101, $source);
        $assert($report['added'] === 2 && $report['expBefore'] === 210 && $report['expAfter'] === 210);
        $rows = $watchlists->findForUser(101);
        $assert(array_column($rows, 'status') === ['completed', 'watching'] && array_column($rows, 'watched') === [12, 10]);
        $confirm(101, $source);
        $assert($experience->totalForUser(101) === 210);
    });

    $run('Opcja zastępowania świadomie zmienia postęp i usuwa ocenę dla MAL zero', static function () use ($confirm, $watchlists, $ratings, $experience, $entry, $parsed, $assert): void {
        $watchlists->saveForUser(101, 1, 'watching', 3);
        $ratings->saveForUser(101, 1, 9);
        $report = $confirm(101, $parsed([$entry(1001, 'completed', 1, 8)]), true);
        $assert($report['updated'] === 1 && $report['ratingsSaved'] === 1 && $report['expAfter'] === 160);
        $report = $confirm(101, $parsed([$entry(1001, 'on_hold', 2, null)]), true);
        $assert($report['updated'] === 1 && $report['ratingsRemoved'] === 1 && $report['expAfter'] === 10);
        $assert($ratings->findForUser(101, 1) === null && $experience->totalForUser(101) === 10);
        $same = $confirm(101, $parsed([$entry(1001, 'on_hold', 2, null)]), true);
        $assert($same['unchanged'] === 1 && $same['updated'] === 0 && $same['expAfter'] === 10);
    });

    $run('Postęp ponad limit katalogu jest pomijany bez przycinania lub dopisywania EXP', static function () use ($confirm, $watchlists, $experience, $entry, $parsed, $assert): void {
        $report = $confirm(101, $parsed([$entry(1001, watched: 13), $entry(1005, 'completed', 25)]));
        $assert($report['skipped'] === 2 && $report['added'] === 0 && $watchlists->findForUser(101) === []);
        $assert($experience->totalForUser(101) === 0);
    });

    $run('Zmiana własnego postępu po podglądzie unieważnia zatwierdzenie bez nadpisania', static function () use ($service, $watchlists, $ratings, $experience, $entry, $parsed, $assert, $throws): void {
        $source = $parsed([$entry()]);
        $preview = $service->previewForUser(101, $source, true);
        $watchlists->saveForUser(101, 1, 'watching', 5);
        $throws(static fn () => $service->importForUser(101, $source, true, $preview['fingerprint']), MalImportChangedException::class);
        $assert($watchlists->findForUser(101)[0]['watched'] === 5 && $experience->totalForUser(101) === 25);
        $assert($ratings->findForUser(101, 1) === null);
    });

    $run('Zmiana samej oceny lub zachowanej historii EXP również wymaga nowego podglądu', static function () use ($service, $watchlists, $ratings, $experience, $entry, $parsed, $assert, $throws): void {
        $source = $parsed([$entry()]);
        $preview = $service->previewForUser(101, $source, true);
        $ratings->saveForUser(101, 1, 10);
        $throws(static fn () => $service->importForUser(101, $source, true, $preview['fingerprint']), MalImportChangedException::class);
        $preview = $service->previewForUser(101, $source);
        $watchlists->saveForUser(101, 1, 'watching', 7);
        $watchlists->removeForUser(101, 1);
        $throws(static fn () => $service->importForUser(101, $source, false, $preview['fingerprint']), MalImportChangedException::class);
        $assert($watchlists->findForUser(101) === [] && $experience->totalForUser(101) === 35 && $ratings->findForUser(101, 1) === 10);
    });

    $run('Błąd zapisu oceny wycofuje wszystkie postępy i EXP całego importu', static function () use ($connection, $confirm, $watchlists, $ratings, $experience, $entry, $parsed, $assert, $throws): void {
        $connection->exec('ALTER TABLE pg_temp.user_anime_ratings ADD CONSTRAINT mal_test_reject_score CHECK (rating <> 7)');
        $source = $parsed([$entry(1001, rating: 8), $entry(1002, 'completed', 1, 7)]);
        $throws(static fn () => $confirm(101, $source), PDOException::class);
        $assert($watchlists->findForUser(101) === [] && $experience->totalForUser(101) === 0);
        $assert($ratings->findByAnimeIdsForUser(101, [1, 2]) === []);
    });

    $run('Podgląd nie może zostać użyty na innym koncie ani dla innego trybu importu', static function () use ($service, $watchlists, $entry, $parsed, $assert, $throws): void {
        $source = $parsed([$entry()]);
        $preview = $service->previewForUser(101, $source);
        $throws(static fn () => $service->importForUser(202, $source, false, $preview['fingerprint']), MalImportChangedException::class);
        $throws(static fn () => $service->importForUser(101, $source, true, $preview['fingerprint']), MalImportChangedException::class);
        $assert($watchlists->findForUser(101) === [] && $watchlists->findForUser(202) === []);
    });

    $run('Zmiana liczby odcinków katalogu wymaga ponownego zatwierdzenia', static function () use ($connection, $service, $watchlists, $entry, $parsed, $assert, $throws): void {
        $source = $parsed([$entry(1001, 'completed', 0)]);
        $preview = $service->previewForUser(101, $source);
        $connection->exec('UPDATE pg_temp.anime SET episodes = 24 WHERE id = 1');
        $throws(static fn () => $service->importForUser(101, $source, false, $preview['fingerprint']), MalImportChangedException::class);
        $assert($watchlists->findForUser(101) === []);
    });

    $run('Duży import przechodzi przez granicę partii i nadal pozostaje idempotentny', static function () use ($connection, $confirm, $watchlists, $experience, $entry, $parsed, $assert): void {
        $insert = $connection->prepare('INSERT INTO pg_temp.anime (id, mal_id, title, episodes) VALUES (:id, :mal, :title, 12)');
        $entries = [];
        for ($index = 0; $index < 405; $index++) {
            $insert->execute(['id' => 100 + $index, 'mal' => 2000 + $index, 'title' => 'Anime ' . $index]);
            $entries[] = $entry(2000 + $index, 'watching', 1, ($index % 10) + 1);
        }
        $source = $parsed($entries);
        $report = $confirm(101, $source);
        $assert($report['added'] === 405 && $report['ratingsSaved'] === 405 && $report['expAfter'] === 2025);
        $again = $confirm(101, $source, true);
        $assert($again['unchanged'] === 405 && $again['updated'] === 0 && count($watchlists->findForUser(101)) === 405);
        $assert($experience->totalForUser(101) === 2025);
    });

    $run('Pusta lista daje raport bez zmian', static function () use ($confirm, $parsed, $assert): void {
        $report = $confirm(101, $parsed([]));
        $assert($report['added'] === 0 && $report['updated'] === 0 && $report['rows'] === [] && $report['expAfter'] === 0);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie izolowanych testów importu: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
