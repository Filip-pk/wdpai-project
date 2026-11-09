<?php

declare(strict_types=1);

use App\Database;
use App\Repository\CommentRepository;
use App\Service\CommentService;

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
    foreach (['users', 'anime', 'anime_comments'] as $table) {
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
    foreach (['anime', 'anime_comments'] as $table) {
        $statement = $connection->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = pg_get_serial_sequence(:table, 'id')::regclass
             AND oid <> pg_get_serial_sequence(:public_table, 'id')::regclass
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Sekwencja testu musi być niezależna od danych aplikacji.');
    }
    $connection->exec(
        'ALTER TABLE pg_temp.anime_comments
             ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id) ON DELETE CASCADE'
    );
    $connection->exec(
        "INSERT INTO pg_temp.users (id, name, email, password_hash)
         VALUES (101, 'Łucja <svg>', 'comment-first@example.test', 'hash'),
                (202, 'Drugi', 'comment-second@example.test', 'hash');
         INSERT INTO pg_temp.anime (id, title) VALUES (1, 'Pierwsze anime'), (2, 'Drugie anime')"
    );
    $comments = new CommentRepository($connection);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT comment_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT comment_repository_test');
            $connection->exec('RELEASE SAVEPOINT comment_repository_test');
        }
    };

    $run('Walidacja dopuszcza tekst Unicode i nowe linie, odrzucając puste, nadmiarowe i uszkodzone dane', static function () use ($assert): void {
        foreach (['Łódź & <script>alert(1)</script>', "Pierwsza linia\nDruga\tlinia", str_repeat('ż', 2000)] as $body) {
            $assert(CommentService::validate($body) === []);
        }
        foreach (['', " \t\r\n", "\u{00A0}\u{2003}", str_repeat('ż', 2001), "x\0y", "x\x01y", "x\u{0085}y", "\xC3\x28"] as $body) {
            $assert(CommentService::validate($body) !== []);
        }
    });

    $run('Pusta lista ma jedną stronę, a numery stron są ograniczane', static function () use ($comments, $assert): void {
        $assert($comments->findPageForAnime(1, PHP_INT_MAX) === ['entries' => [], 'page' => 1, 'total' => 0, 'totalPages' => 1]);
        $assert($comments->findPageForAnime(1, -5)['page'] === 1);
    });

    $run('Komentarze dzielą się na strony 10/10/3, zachowując kolejność remisów i izolację anime', static function () use ($comments, $assert): void {
        $ids = [];
        for ($number = 1; $number <= 23; $number++) {
            $ids[] = $comments->createForUser($number % 2 === 0 ? 101 : 202, 1, 'Komentarz ' . $number);
        }
        $otherId = $comments->createForUser(101, 2, 'Inne anime');
        $pages = [];
        foreach ([1 => 10, 2 => 10, 3 => 3] as $pageNumber => $expectedCount) {
            $result = $comments->findPageForAnime(1, $pageNumber);
            $assert($result['page'] === $pageNumber && $result['total'] === 23 && $result['totalPages'] === 3);
            $assert(count($result['entries']) === $expectedCount);
            $pages = [...$pages, ...array_column($result['entries'], 'id')];
        }
        $assert($pages === array_reverse($ids));
        $assert(array_column($comments->findPageForAnime(2)['entries'], 'id') === [$otherId]);
        $assert($comments->findPageForAnime(1, PHP_INT_MAX)['page'] === 3);
        $assert(count($comments->findPageForAnime(1, 1, 0)['entries']) === 1);
        $assert(count($comments->findPageForAnime(1, 1, PHP_INT_MAX)['entries']) === 23);
    });

    $run('Publiczny odczyt pokazuje autora i avatar bez e-maila, ustawień i hasła', static function () use ($comments, $connection, $assert): void {
        $connection->exec("UPDATE pg_temp.users SET avatar_version = repeat('a', 32) WHERE id = 101");
        $body = "Łódź & <script>alert(1)</script>\nO'Reilly; SELECT 999; --";
        $id = $comments->createForUser(101, 1, $body);
        $comment = $comments->findPageForAnime(1)['entries'][0];
        $assert(array_keys($comment) === ['id', 'animeId', 'userId', 'authorName', 'avatarUrl', 'body', 'createdAt', 'updatedAt', 'isRemoved', 'parentId', 'isDeleted', 'replyCount']);
        $assert($comment['id'] === $id && $comment['body'] === $body && $comment['authorName'] === 'Łucja <svg>');
        $assert($comment['avatarUrl'] === '/avatars/101?v=' . str_repeat('a', 32));
        $assert($comment['createdAt'] !== '' && $comment['updatedAt'] === null);
        $assert($comments->findByIdForUser(101, 1, $id) === $comment);
    });

    $run('Edycja i usunięcie sprawdzają jednocześnie autora, anime i ID wpisu', static function () use ($comments, $assert): void {
        $firstId = $comments->createForUser(101, 1, 'Pierwotny');
        $secondId = $comments->createForUser(202, 1, 'Drugi autor');
        $otherId = $comments->createForUser(101, 2, 'Drugie anime');
        foreach ([[202, 1, $firstId], [101, 2, $firstId], [101, 1, $otherId], [101, 1, PHP_INT_MAX]] as [$userId, $animeId, $id]) {
            $assert($comments->findByIdForUser($userId, $animeId, $id) === null);
            $assert(!$comments->updateForUser($userId, $animeId, $id, 'Podmieniony'));
            $assert(!$comments->removeForUser($userId, $animeId, $id));
        }
        $assert($comments->updateForUser(101, 1, $firstId, 'Poprawiony'));
        $updated = $comments->findByIdForUser(101, 1, $firstId);
        $assert($updated['body'] === 'Poprawiony' && $updated['updatedAt'] !== null);
        $assert(array_column($comments->findPageForAnime(1)['entries'], 'id') === [$secondId, $firstId]);
        $assert($comments->removeForUser(101, 1, $firstId));
        $assert(!$comments->removeForUser(101, 1, $firstId));
        $assert($comments->findByIdForUser(202, 1, $secondId)['body'] === 'Drugi autor');
        $assert($comments->findByIdForUser(101, 2, $otherId)['body'] === 'Drugie anime');
    });

    $run('Niepoprawny tekst jest odrzucany przed zapisem i nie niszczy starej treści', static function () use ($comments, $assert): void {
        $id = $comments->createForUser(101, 1, 'Prawidłowy komentarz');
        foreach (['', " \n", str_repeat('a', 2001), "x\0y", "\xC3\x28"] as $invalid) {
            foreach ([static fn () => $comments->createForUser(101, 1, $invalid), static fn () => $comments->updateForUser(101, 1, $id, $invalid)] as $write) {
                $rejected = false;
                try {
                    $write();
                } catch (InvalidArgumentException) {
                    $rejected = true;
                }
                $assert($rejected);
            }
        }
        $assert($comments->findByIdForUser(101, 1, $id)['body'] === 'Prawidłowy komentarz');
        $assert($comments->findPageForAnime(1)['total'] === 1);
    });

    foreach (['', '   ', str_repeat('a', 2001)] as $invalid) {
        $run('Baza odrzuca niepoprawną długość lub pustą treść: ' . strlen($invalid), static function () use ($connection, $invalid, $assert): void {
            $rejected = false;
            try {
                $statement = $connection->prepare('INSERT INTO anime_comments (user_id, anime_id, body) VALUES (101, 1, :body)');
                $statement->execute(['body' => $invalid]);
            } catch (PDOException $exception) {
                $rejected = $exception->getCode() === '23514';
            }
            $assert($rejected);
        });
    }

    $run('Klucze obce odrzucają nieistniejącego autora i anime', static function () use ($comments, $connection, $assert): void {
        foreach ([[999, 1], [101, 999]] as [$userId, $animeId]) {
            $connection->exec('SAVEPOINT comment_invalid_reference');
            try {
                $rejected = false;
                try {
                    $comments->createForUser($userId, $animeId, 'Brak odniesienia');
                } catch (PDOException $exception) {
                    $rejected = $exception->getCode() === '23503';
                }
                $assert($rejected);
            } finally {
                $connection->exec('ROLLBACK TO SAVEPOINT comment_invalid_reference');
                $connection->exec('RELEASE SAVEPOINT comment_invalid_reference');
            }
        }
    });

    $run('Usunięcie autora lub anime usuwa tylko powiązane komentarze', static function () use ($comments, $connection, $assert): void {
        $comments->createForUser(101, 1, 'Autor do usunięcia');
        $survivingId = $comments->createForUser(202, 1, 'Drugi autor');
        $comments->createForUser(202, 2, 'Anime do usunięcia');
        $connection->exec('DELETE FROM pg_temp.users WHERE id = 101');
        $assert(array_column($comments->findPageForAnime(1)['entries'], 'id') === [$survivingId]);
        $connection->exec('DELETE FROM pg_temp.anime WHERE id = 2');
        $assert($comments->findPageForAnime(2)['total'] === 0);
        $assert($comments->findPageForAnime(1)['total'] === 1);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować izolowanych testów komentarzy: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
