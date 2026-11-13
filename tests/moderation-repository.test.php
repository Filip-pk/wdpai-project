<?php

declare(strict_types=1);

use App\Database;
use App\Repository\ModerationRepository;

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
    foreach (['users', 'anime', 'anime_comments', 'community_reports'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $check = $connection->prepare("SELECT relpersistence = 't' AND relnamespace = pg_my_temp_schema() FROM pg_class WHERE oid = to_regclass(:table)");
        $check->execute(['table' => $table]);
        $assert($check->fetchColumn() === true, 'Wymagane są wyłącznie tabele tymczasowe.');
    }
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    $migration = file_get_contents(dirname(__DIR__) . '/database/23-moderation-and-timed-bans.sql');
    $assert(is_string($migration));
    $migration = preg_replace('/\b(?:BEGIN|COMMIT);/', '', $migration);
    $migration = str_replace('CREATE TABLE ', 'CREATE TEMP TABLE ', $migration);
    $connection->exec($migration);
    foreach (['user_bans', 'moderation_actions', 'moderation_notices'] as $table) {
        $check->execute(['table' => $table]);
        $assert($check->fetchColumn() === true, 'Migracja testowa musi tworzyć tabele tymczasowe.');
    }
    $connection->exec("INSERT INTO pg_temp.users (id, name, email, password_hash, is_moderator) VALUES
        (101, 'Moderator', 'mod-test@example.test', 'hash', true),
        (202, 'Autor <svg>', 'author-test@example.test', 'hash', false),
        (303, 'Zgłaszający', 'reporter-test@example.test', 'hash', false),
        (404, 'Drugi moderator', 'other-mod-test@example.test', 'hash', true);
        INSERT INTO pg_temp.anime (id, title) VALUES (1, 'Naruto <svg>'), (2, 'Inne anime');
        INSERT INTO pg_temp.anime_comments (id, anime_id, user_id, body) VALUES
        (11, 1, 202, 'Komentarz do moderacji'), (12, 2, 303, 'Drugi komentarz');
        INSERT INTO pg_temp.anime_comments (id, anime_id, user_id, body, parent_id) VALUES (13, 1, 303, 'Odpowiedź pozostaje', 11);
        INSERT INTO pg_temp.community_reports (id, reporter_id, reported_user_id, target_type, target_id, snapshot, reason) VALUES
        (21, 303, 202, 'comment', 11, 'Komentarz do moderacji', 'Obraźliwy'),
        (22, 303, 202, 'message', 11, 'TAJNY FRAGMENT WIADOMOŚCI', 'Prywatne zgłoszenie');");
    $repository = new ModerationRepository($connection);
    $key = static fn (): string => bin2hex(random_bytes(32));
    $count = static fn (string $table): int => (int) $connection->query('SELECT count(*) FROM pg_temp.' . $table)->fetchColumn();
    $run = static function (string $name, Closure $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT moderation_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . ' ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT moderation_test');
            $connection->exec('RELEASE SAVEPOINT moderation_test');
        }
    };

    $run('Moderator nie odczyta ani nie rozstrzygnie zgłoszeń wiadomości nawet przez ich ID', static function () use ($repository, $assert, $key, $count): void {
        $page = $repository->reportsPageForModerator(101);
        $assert($page['total'] === 1 && $page['entries'][0]['id'] === 21);
        $assert(!str_contains(json_encode($page, JSON_THROW_ON_ERROR), 'TAJNY'));
        $assert(!$repository->dismissReport(101, 22, 'Powód', $key()));
        $assert(!$repository->resolveReportForModerator(101, 22, 'remove', 'Powód', $key()));
        $assert(!$repository->removeComment(101, 11, 'Powód', $key(), 22));
        $assert($count('moderation_actions') === 0 && $count('moderation_notices') === 0);
    });

    $run('Odebrana rola natychmiast blokuje odczyt i wszystkie decyzje', static function () use ($connection, $repository, $assert, $key, $count): void {
        $assert($repository->isModerator(101));
        $connection->exec('UPDATE pg_temp.users SET is_moderator = false WHERE id = 101');
        $assert($repository->commentsPageForModerator(101) === null);
        $assert($repository->usersPageForModerator(101) === null);
        $assert($repository->actionsPageForModerator(101) === null);
        $assert(!$repository->removeComment(101, 11, 'Powód', $key()));
        $assert(!$repository->warnUser(101, 202, 'Powód', $key(), 11));
        $assert(!$repository->banUser(101, 202, 'Powód', 3600, $key()));
        $assert($count('moderation_actions') === 0);
    });

    $run('Usunięcie zachowuje drzewo dyskusji, dodaje historię i jedno powiadomienie', static function () use ($connection, $repository, $assert, $key, $count): void {
        $request = $key();
        $assert($repository->removeComment(101, 11, 'Obrażanie innych użytkowników', $request, 21));
        $assert(!$repository->removeComment(101, 11, 'Powtórzenie', $request, 21));
        $assert(!$repository->removeComment(404, 11, 'Inny moderator', $key()));
        $assert($count('anime_comments') === 3 && $count('moderation_actions') === 1 && $count('moderation_notices') === 1);
        $assert((int) $connection->query('SELECT parent_id FROM pg_temp.anime_comments WHERE id = 13')->fetchColumn() === 11);
        $assert($connection->query('SELECT moderated_at FROM pg_temp.anime_comments WHERE id = 11')->fetchColumn() !== null);
        $assert($connection->query('SELECT status FROM pg_temp.community_reports WHERE id = 21')->fetchColumn() === 'removed');
        $assert($connection->query('SELECT status FROM pg_temp.community_reports WHERE id = 22')->fetchColumn() === 'open');
        $notices = $repository->noticesPageForUser(202);
        $assert($notices['total'] === 1 && $notices['entries'][0]['animeTitle'] === 'Naruto <svg>');
        $assert($notices['entries'][0]['actionType'] === 'comment_removed');
        $assert($repository->activeBanForUser(202) === null);
    });

    $run('Błąd powiadomienia cofa usunięcie, rozstrzygnięcie oraz historię w jednej transakcji', static function () use ($connection, $repository, $assert, $key, $count): void {
        $connection->exec("CREATE FUNCTION pg_temp.reject_moderation_notice() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'notice rejected'; END \$\$;
            CREATE TRIGGER reject_notice BEFORE INSERT ON pg_temp.moderation_notices FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_moderation_notice()");
        $rejected = false;
        try {
            $repository->removeComment(101, 11, 'Powód', $key(), 21);
        } catch (PDOException) {
            $rejected = true;
        }
        $assert($rejected);
        $assert($connection->query('SELECT moderated_at FROM pg_temp.anime_comments WHERE id = 11')->fetchColumn() === null);
        $assert($connection->query('SELECT status FROM pg_temp.community_reports WHERE id = 21')->fetchColumn() === 'open');
        $assert($count('moderation_actions') === 0 && $count('moderation_notices') === 0);
    });

    $run('Ostrzeżenie jest osobną decyzją bez bana, z trwałą ochroną przed ponowieniem', static function () use ($repository, $assert, $key, $count): void {
        $request = $key();
        $assert($repository->warnUser(101, 202, 'Pierwsze ostrzeżenie', $request, 11));
        $assert(!$repository->warnUser(101, 202, 'Pierwsze ostrzeżenie', $request, 11));
        $assert(!$repository->warnUser(101, 303, 'Niezgodny autor komentarza', $key(), 11));
        $assert($repository->activeBanForUser(202) === null && $count('moderation_actions') === 1);
        $assert($repository->unreadNoticesForUser(202)[0]['actionType'] === 'warning');
    });

    $run('Bany wymagają powodu i dozwolonego okresu oraz odrzucają własne konto i moderatorów', static function () use ($repository, $assert, $key, $count): void {
        foreach ([0, 1, 7200, 2592001] as $duration) {
            $assert(!$repository->banUser(101, 202, 'Powód', $duration, $key()));
        }
        foreach (['', '   ', str_repeat('ż', 1001), "x\0y"] as $reason) {
            $assert(!$repository->banUser(101, 202, $reason, 3600, $key()));
        }
        $assert(!$repository->banUser(101, 101, 'Własne konto', 3600, $key()));
        $assert(!$repository->banUser(101, 404, 'Inny moderator', 3600, $key()));
        $assert(!$repository->banUser(101, 202, 'Brak poprawnego klucza', 3600, 'invalid'));
        $assert($count('user_bans') === 0 && $count('moderation_actions') === 0);
    });

    $run('Każdy obsługiwany okres ma długość wyznaczoną przez bazę', static function () use ($connection, $repository, $assert, $key): void {
        foreach (ModerationRepository::BAN_DURATIONS as $seconds) {
            $assert($repository->banUser(101, 202, 'Powtarzające się naruszenia', $seconds, $key()));
            $ban = $repository->activeBanForUser(202);
            $assert($ban !== null && $ban['reason'] === 'Powtarzające się naruszenia');
            $duration = $connection->prepare('SELECT EXTRACT(EPOCH FROM ends_at - starts_at)::integer FROM pg_temp.user_bans WHERE id = :id');
            $duration->execute(['id' => $ban['id']]);
            $assert((int) $duration->fetchColumn() === $seconds);
            $assert(!$repository->banUser(101, 202, 'Duplikat aktywnego bana', $seconds, $key()));
            $assert($repository->revokeBan(101, $ban['id'], 'Decyzja cofnięta', $key()));
        }
    });

    $run('Wygaśnięcie według zegara bazy oraz cofnięcie zachowują historię decyzji', static function () use ($connection, $repository, $assert, $key, $count): void {
        $assert($repository->banUser(101, 202, 'Pierwsza blokada', 3600, $key()));
        $ban = $repository->activeBanForUser(202);
        $expire = $connection->prepare("UPDATE pg_temp.user_bans SET starts_at = clock_timestamp() - INTERVAL '2 hours', ends_at = clock_timestamp() - INTERVAL '1 hour' WHERE id = :id");
        $expire->execute(['id' => $ban['id']]);
        $assert($repository->activeBanForUser(202) === null);
        $assert(!$repository->revokeBan(101, $ban['id'], 'Wygasła blokada', $key()));
        $assert($repository->banUser(101, 202, 'Druga blokada', 86400, $key()));
        $ban = $repository->activeBanForUser(202);
        $assert($repository->revokeBan(404, $ban['id'], 'Odwołanie zaakceptowane', $key()));
        $assert(!$repository->revokeBan(404, $ban['id'], 'Powtórzenie', $key()));
        $assert($repository->activeBanForUser(202) === null);
        $assert($count('user_bans') === 2 && $count('moderation_actions') === 3 && $count('moderation_notices') === 3);
        $assert($repository->noticesPageForUser(202)['entries'][0]['actionType'] === 'ban_revoked');
    });

    $run('Powiadomienia może czytać i potwierdzać wyłącznie właściciel, bez usuwania historii', static function () use ($repository, $assert, $key): void {
        $assert($repository->warnUser(101, 202, 'Poufna decyzja dla autora', $key(), 11));
        $notice = $repository->unreadNoticesForUser(202)[0];
        $assert($repository->noticesPageForUser(303)['total'] === 0);
        $assert($repository->noticesPageForUser(101)['total'] === 0);
        $assert(!$repository->acknowledgeNoticeForUser(303, $notice['id']));
        $assert(!$repository->acknowledgeNoticeForUser(101, $notice['id']));
        $assert($repository->noticesPageForUser(202)['entries'][0]['readAt'] === null);
        $assert($repository->acknowledgeNoticeForUser(202, $notice['id']));
        $assert($repository->acknowledgeNoticeForUser(202, $notice['id']));
        $assert($repository->unreadNoticesForUser(202) === []);
        $assert($repository->noticesPageForUser(202)['total'] === 1);
        $assert($repository->noticesPageForUser(202)['entries'][0]['readAt'] !== null);
    });

    $run('Filtry autora, anime i statusu obejmują komentarze oraz odpowiedzi', static function () use ($repository, $assert, $key): void {
        $assert($repository->commentsPageForModerator(101, 1, ['author' => '202', 'anime' => 'Naruto'])['total'] === 1);
        $assert($repository->commentsPageForModerator(101, 1, ['author' => 'Autor', 'anime' => '1'])['entries'][0]['id'] === 11);
        $assert($repository->commentsPageForModerator(101, 1, ['author' => '%'])['total'] === 0);
        $assert($repository->commentsPageForModerator(101, 1, ['anime' => 'Naruto'])['total'] === 2);
        $assert($repository->removeComment(101, 11, 'Powód', $key()));
        $assert($repository->commentsPageForModerator(101, 1, ['status' => 'removed'])['total'] === 1);
        $assert($repository->commentsPageForModerator(101, 1, ['status' => 'active'])['total'] === 2);
        $assert($repository->actionsPageForModerator(101, 1, ['author' => 'Autor', 'anime' => 'Naruto'])['total'] === 1);
        $assert($repository->usersPageForModerator(101, 1, ['author' => '202'])['entries'][0]['name'] === 'Autor <svg>');
    });

    $run('Oddalenie zgłoszenia wymaga powodu i zachowuje audyt bez powiadamiania autora', static function () use ($repository, $assert, $key, $count): void {
        $assert(!$repository->dismissReport(101, 21, '', $key()));
        $assert($repository->dismissReport(101, 21, 'Brak naruszenia zasad', $key()));
        $assert(!$repository->dismissReport(101, 21, 'Powtórzenie', $key()));
        $assert($repository->reportsPageForModerator(101)['total'] === 0);
        $assert($repository->reportsPageForModerator(101, 1, ['status' => 'dismissed'])['total'] === 1);
        $assert($count('moderation_actions') === 1 && $count('moderation_notices') === 0);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Przygotowanie izolowanych testów moderacji: ' . $exception::class . ' ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
