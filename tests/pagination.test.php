<?php

declare(strict_types=1);

use App\Controller\CollectionController;
use App\Controller\ProfileController;
use App\Database;
use App\Repository\AnimeRepository;
use App\Repository\CollectionRepository;
use App\Repository\ExperienceRepository;
use App\Repository\RatingRepository;
use App\Repository\UserRepository;
use App\Repository\WatchlistRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Buffer CLI progress so controller responses can still set HTTP status codes.
ob_start();
$writeFixtures = in_array('--fixtures', $argv ?? [], true);
$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Niepoprawny wynik paginacji.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach ([
        'users', 'anime', 'genres', 'anime_genres', 'user_watchlist', 'user_anime_ratings',
        'user_anime_experience', 'anime_collections', 'anime_collection_items',
    ] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND oid <> to_regclass(:public_table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Test może zapisywać wyłącznie do tabel tymczasowych.');
    }
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $assert((int) $connection->query(
        "SELECT count(*) FROM pg_class
         WHERE oid = pg_get_serial_sequence('anime_collections', 'id')::regclass
         AND oid <> pg_get_serial_sequence('public.anime_collections', 'id')::regclass
         AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
    )->fetchColumn() === 1, 'Tworzenie kolekcji musi używać niezależnej sekwencji tymczasowej.');
    $connection->exec(
        "INSERT INTO pg_temp.users (id, name, email, password_hash)
         VALUES (101, 'Pierwszy', 'pagination-first@example.test', 'hash'),
                (202, 'Drugi', 'pagination-second@example.test', 'hash'),
                (303, 'Pusty', 'pagination-empty@example.test', 'hash');
         INSERT INTO pg_temp.anime (id, title, episodes)
         SELECT id, 'Anime ' || id, 12 FROM generate_series(1, 125) AS id"
    );
    $watchlists = new WatchlistRepository($connection);
    $collections = new CollectionRepository($connection);
    $users = new UserRepository($connection);
    $animes = new AnimeRepository($connection);
    $ratings = new RatingRepository($connection);
    $experience = new ExperienceRepository($connection);
    $profile = new ProfileController($users, $watchlists, $animes, $ratings, $experience);
    $collectionController = new CollectionController($users, $collections, $animes, $watchlists);
    $seedWatchlist = static function () use ($connection): void {
        $connection->exec(
            "INSERT INTO pg_temp.user_watchlist (user_id, anime_id, status, watched)
             SELECT 101, id, CASE WHEN id <= 30 THEN 'watching' WHEN id <= 36 THEN 'completed'
                        WHEN id <= 46 THEN 'on_hold' WHEN id <= 56 THEN 'dropped' ELSE 'planned' END,
                    CASE WHEN id <= 30 THEN 3 WHEN id <= 36 THEN 12
                         WHEN id <= 56 THEN 2 ELSE 0 END
             FROM generate_series(1, 60) AS id;
             INSERT INTO pg_temp.user_watchlist (user_id, anime_id, status, watched)
             VALUES (202, 125, 'completed', 12)"
        );
    };
    $seedCollections = static function (int $count = 26) use ($collections): array {
        $ids = [];
        for ($number = 1; $number < $count; $number++) {
            $ids[] = $collections->createForUser(101, 'Kolekcja ' . $number, 'Opis ' . $number);
        }
        // Domyślna powstaje ostatnia, lecz musi być pierwsza w posortowanych wynikach.
        $collections->ensureDefaultForUser(101);
        return [$collections->findForUser(101)[0]['id'], ...$ids];
    };
    $render = static function (
        callable $action,
        string $path,
        array $query = [],
        int $userId = 101,
        ?array $fields = null,
        int $expectedStatus = 200,
    ) use ($writeFixtures): string {
        $previousGet = $_GET;
        $previousPost = $_POST;
        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousStatus = http_response_code();
        $_GET = $query;
        $_POST = $fields ?? [];
        $_SESSION = ['user_id' => $userId, 'csrf_token' => str_repeat('a', 64)];
        $_SERVER['REQUEST_METHOD'] = $fields === null ? 'GET' : 'POST';
        $_SERVER['REQUEST_URI'] = $path . ($query === [] ? '' : '?' . http_build_query($query));
        http_response_code(200);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        ob_start();
        try {
            $action();
            if (http_response_code() !== $expectedStatus) {
                throw new RuntimeException('Niepoprawny status odpowiedzi widoku.');
            }
            $html = ob_get_contents();
            if (!is_string($html)) {
                throw new RuntimeException('Nie udało się przechwycić widoku.');
            }
            if ($writeFixtures && $fields === null && ($query['page'] ?? null) === '2') {
                $fixtureName = match ($path) {
                    '/watchlist' => 'watchlist',
                    '/profile' => 'profile',
                    '/profile/collections' => 'collections',
                    default => 'collection-detail',
                };
                $fixturePath = dirname(__DIR__) . '/storage/imports/pagination-' . $fixtureName . '-qa.html';
                if (file_put_contents($fixturePath, $html) === false) {
                    throw new RuntimeException('Nie udało się zapisać podglądu układu.');
                }
            }
            return $html;
        } finally {
            ob_end_clean();
            restore_error_handler();
            $_GET = $previousGet;
            $_POST = $previousPost;
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            http_response_code($previousStatus === false ? 200 : $previousStatus);
        }
    };
    $watchlistIds = static function (string $html): array {
        preg_match_all('/data-anime-id="(\d+)"\s+data-watchlist-status=/', $html, $matches);
        return array_map('intval', $matches[1]);
    };
    $collectionIds = static function (string $html): array {
        preg_match_all('/data-collection-id="(\d+)"/', $html, $matches);
        return array_map('intval', $matches[1]);
    };
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT pagination_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . ' — ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT pagination_test');
            $connection->exec('RELEASE SAVEPOINT pagination_test');
        }
    };

    $run('Pusta watchlista ma jedną pustą stronę i zerowe statystyki', static function () use ($watchlists, $assert): void {
        $page = $watchlists->findPageForUser(303, PHP_INT_MAX);
        $assert($page['entries'] === [] && $page['page'] === 1 && $page['totalPages'] === 1);
        $assert($page['total'] === 0 && $page['watchlistTotal'] === 0 && $page['completedCount'] === 0);
    });

    $run('Watchlista dzieli 60 tytułów na strony 24, 24 i 12 bez duplikatów', static function () use (
        $seedWatchlist, $watchlists, $assert,
    ): void {
        $seedWatchlist();
        $ids = [];
        foreach ([1 => 24, 2 => 24, 3 => 12] as $number => $count) {
            $page = $watchlists->findPageForUser(101, $number);
            $assert(count($page['entries']) === $count && $page['page'] === $number && $page['totalPages'] === 3);
            $assert($page['total'] === 60 && $page['watchlistTotal'] === 60 && $page['completedCount'] === 6);
            $ids = [...$ids, ...array_column($page['entries'], 'animeId')];
        }
        $assert($ids === range(1, 60));
        $assert($watchlists->findPageForUser(101, -1)['page'] === 1);
        $assert($watchlists->findPageForUser(101, PHP_INT_MAX)['page'] === 3);
    });

    $run('Filtr watchlisty działa przed podziałem i nie zmniejsza statystyk profilu', static function () use (
        $seedWatchlist, $watchlists, $assert,
    ): void {
        $seedWatchlist();
        $first = $watchlists->findPageForUser(101, 1, 24, 'watching');
        $second = $watchlists->findPageForUser(101, 2, 24, 'watching');
        $assert(array_column($first['entries'], 'animeId') === range(1, 24));
        $assert(array_column($second['entries'], 'animeId') === range(25, 30));
        $assert($second['total'] === 30 && $second['totalPages'] === 2);
        $assert($second['watchlistTotal'] === 60 && $second['completedCount'] === 6);
        foreach (['completed' => 6, 'on_hold' => 10, 'dropped' => 10, 'planned' => 4] as $status => $count) {
            $page = $watchlists->findPageForUser(101, 1, 24, $status);
            $assert($page['total'] === $count && count($page['entries']) === $count);
            $assert(array_unique(array_column($page['entries'], 'status')) === [$status]);
        }
        $empty = $watchlists->findPageForUser(202, 50, 24, 'watching');
        $assert($empty['entries'] === [] && $empty['total'] === 0 && $empty['page'] === 1);
        $assert($empty['watchlistTotal'] === 1 && $empty['completedCount'] === 1);
    });

    $run('Paginacja i odczyt wskazanych anime ujawniają wyłącznie dane właściciela', static function () use (
        $seedWatchlist, $watchlists, $assert,
    ): void {
        $seedWatchlist();
        $assert(array_column($watchlists->findPageForUser(202)['entries'], 'animeId') === [125]);
        $assert($watchlists->findByAnimeIdsForUser(202, [1, 2, 3]) === []);
        $own = $watchlists->findByAnimeIdsForUser(101, [3, 1, 3, 125]);
        $assert(array_column($own, 'animeId') === [1, 3]);
        $assert(array_column($own, 'status') === ['watching', 'watching']);
        $assert($watchlists->findByAnimeIdsForUser(101, []) === []);
    });

    $run('Watchlista ogranicza rozmiar strony do zakresu 1–100', static function () use ($connection, $watchlists, $assert): void {
        $connection->exec('INSERT INTO pg_temp.user_watchlist (user_id, anime_id) SELECT 101, id FROM generate_series(1, 125) AS id');
        $first = $watchlists->findPageForUser(101, 1, PHP_INT_MAX);
        $last = $watchlists->findPageForUser(101, PHP_INT_MAX, PHP_INT_MAX);
        $assert(count($first['entries']) === 100 && $first['totalPages'] === 2);
        $assert(array_column($last['entries'], 'animeId') === range(101, 125));
        $small = $watchlists->findPageForUser(101, 2, 0);
        $assert(count($small['entries']) === 1 && $small['entries'][0]['animeId'] === 2);
    });

    $run('Pusta lista kolekcji ma poprawne liczniki', static function () use ($collections, $assert): void {
        $page = $collections->findPageForUser(303, PHP_INT_MAX);
        $assert($page['collections'] === [] && $page['total'] === 0 && $page['page'] === 1 && $page['totalPages'] === 1);
    });

    $run('Kolekcje dzielą się po 12, a domyślna jest pierwsza niezależnie od ID', static function () use (
        $seedCollections, $collections, $assert,
    ): void {
        $expectedIds = $seedCollections();
        $ids = [];
        foreach ([1 => 12, 2 => 12, 3 => 2] as $number => $count) {
            $page = $collections->findPageForUser(101, $number);
            $assert(count($page['collections']) === $count && $page['page'] === $number);
            $assert($page['total'] === 26 && $page['totalPages'] === 3);
            $ids = [...$ids, ...array_column($page['collections'], 'id')];
        }
        $assert($ids === $expectedIds);
        $assert(count(array_unique($ids)) === 26);
        $assert($collections->findPageForUser(101, -1)['page'] === 1);
        $assert($collections->findPageForUser(101, PHP_INT_MAX)['page'] === 3);
    });

    $run('Miniatury i licznik paginowanej kolekcji są kompletne i prywatne', static function () use (
        $seedCollections, $collections, $assert,
    ): void {
        $ids = $seedCollections();
        foreach (range(1, 6) as $animeId) {
            $collections->addAnimeForUser(101, $ids[12], $animeId);
        }
        $foreign = $collections->createForUser(202, 'Tajna kolekcja', 'Tajny opis');
        $page = $collections->findPageForUser(101, 2);
        $collection = $page['collections'][0];
        $assert($collection['id'] === $ids[12] && $collection['animeCount'] === 6);
        $assert(array_column($collection['previews'], 'id') === [6, 5, 4, 3]);
        $assert(!in_array($foreign, array_column($page['collections'], 'id'), true));
        $other = $collections->findPageForUser(202, 2);
        $assert(array_column($other['collections'], 'id') === [$foreign] && $other['total'] === 1);
        $assert(count($collections->findForUser(101)) === 26, 'Wybór kolekcji na stronie anime nadal widzi pełną listę.');
    });

    $run('Lista kolekcji ogranicza rozmiar strony do zakresu 1–100', static function () use (
        $seedCollections, $collections, $assert,
    ): void {
        $seedCollections(105);
        $large = $collections->findPageForUser(101, 1, PHP_INT_MAX);
        $small = $collections->findPageForUser(101, 1, 0);
        $assert(count($large['collections']) === 100 && $large['totalPages'] === 2 && $large['total'] === 105);
        $assert(count($small['collections']) === 1 && $small['collections'][0]['isDefault']);
    });

    $run('Watchlista renderuje drugą stronę z zachowanym filtrem i pełnymi statystykami', static function () use (
        $seedWatchlist, $render, $profile, $watchlistIds, $assert,
    ): void {
        $seedWatchlist();
        foreach (['/watchlist' => 'watchlist', '/profile' => 'index'] as $path => $method) {
            $html = $render([$profile, $method], $path, ['page' => '2', 'status' => 'watching']);
            $assert($watchlistIds($html) === range(25, 30));
            $assert(str_contains($html, 'Strona 2 z 2'));
            preg_match_all('/href="([^"]+)"/', $html, $matches);
            $previous = false;
            foreach ($matches[1] as $href) {
                $url = parse_url(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
                parse_str($url['query'] ?? '', $query);
                if (($url['path'] ?? '') === $path && ($query['page'] ?? '1') === '1' && ($query['status'] ?? '') === 'watching') {
                    $previous = true;
                }
            }
            $assert($previous, 'Link poprzedniej strony musi zachować filtr statusu.');
            if ($path === '/profile') {
                $assert(str_contains($html, 'data-watchlist-count="60"'));
                $assert(str_contains($html, 'data-completed-count="6"'));
            }
        }
    });

    $run('Niepoprawne i tablicowe numery stron nie powodują błędu widoku watchlisty', static function () use (
        $seedWatchlist, $render, $profile, $watchlistIds, $assert,
    ): void {
        $seedWatchlist();
        foreach (['abc', '0', '-1', ['2']] as $page) {
            $html = $render([$profile, 'watchlist'], '/watchlist', ['page' => $page]);
            $assert($watchlistIds($html) === range(1, 24));
        }
        $html = $render([$profile, 'watchlist'], '/watchlist', ['page' => (string) PHP_INT_MAX]);
        $assert($watchlistIds($html) === range(49, 60));
    });

    $run('Błędy zapisu na drugiej stronie profilu zachowują filtr i dane formularza', static function () use (
        $seedWatchlist, $render, $profile, $watchlistIds, $assert,
    ): void {
        $seedWatchlist();
        $valid = [
            'animeId' => '25', 'status' => 'watching', 'watched' => '3',
            'listView' => 'profile', 'listPage' => '2', 'listStatus' => 'watching',
            'csrfToken' => str_repeat('a', 64),
        ];
        foreach ([[['csrfToken' => 'wrong'], 403], [['watched' => '13'], 422]] as [$invalid, $status]) {
            $html = $render([$profile, 'saveWatchlist'], '/watchlist', fields: array_replace($valid, $invalid), expectedStatus: $status);
            $assert($watchlistIds($html) === range(25, 30));
            $assert(str_contains($html, 'Strona 2 z 2'));
            $assert(str_contains($html, 'name="name" type="text" value="Pierwszy"'));
            $assert(str_contains($html, 'name="email" type="email" value="pagination-first@example.test"'));
            $assert(str_contains($html, 'value="watching" selected'));
            $assert(str_contains($html, 'role="alert"'));
        }
    });

    $run('Lista kolekcji renderuje 12 kart, kolejne strony i łączny licznik', static function () use (
        $seedCollections, $render, $collectionController, $collectionIds, $assert,
    ): void {
        $ids = $seedCollections();
        $first = $render([$collectionController, 'index'], '/profile/collections');
        $second = $render([$collectionController, 'index'], '/profile/collections', ['page' => '2']);
        $last = $render([$collectionController, 'index'], '/profile/collections', ['page' => (string) PHP_INT_MAX]);
        $assert($collectionIds($first) === array_slice($ids, 0, 12));
        $assert($collectionIds($second) === array_slice($ids, 12, 12));
        $assert($collectionIds($last) === array_slice($ids, 24));
        $assert(str_contains($second, 'Strona 2 z 3'));
        $assert(str_contains($second, '/profile/collections?page=1'));
        $assert(str_contains($second, '/profile/collections?page=3'));
        $assert(preg_match('/Prywatne\s*·\s*(?:<[^>]+>\s*)*26/', $second) === 1, 'Licznik musi opisywać wszystkie kolekcje.');
    });

    $run('Szczegół kolekcji nadal dzieli anime po 24 i pokazuje własny postęp', static function () use (
        $collections, $watchlists, $render, $collectionController, $assert,
    ): void {
        $id = $collections->createForUser(101, 'Wiele anime', '');
        foreach (range(1, 30) as $animeId) {
            $collections->addAnimeForUser(101, $id, $animeId);
        }
        $watchlists->saveForUser(101, 3, 'watching', 4);
        $watchlists->saveForUser(202, 4, 'completed', 12);
        $html = $render(static fn () => $collectionController->show($id), '/profile/collections/' . $id, ['page' => '2']);
        preg_match_all('/data-collection-anime-id="(\d+)"/', $html, $matches);
        $assert(array_map('intval', $matches[1]) === range(1, 6));
        $assert(str_contains($html, 'Strona 2 z 2'));
        $assert(str_contains($html, '/profile/collections/' . $id . '?page=1'));
        $assert(str_contains($html, 'Obejrzane odcinki: 4 / 12'));
        $assert(!str_contains($html, 'Obejrzane odcinki: 12 / 12'));
        $assert(str_contains($html, 'Liczba anime: 30'));
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie izolowanych testów paginacji: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

ob_end_flush();
echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
