<?php

declare(strict_types=1);

use App\Database;
use App\Repository\MessageRepository;
use App\Service\MessageService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$prepare = static function (PDO $database, bool $dropOnCommit = true) use ($assert): void {
    foreach (['users', 'private_conversations', 'private_messages', 'user_blocks', 'private_message_reads'] as $table) {
        $database->exec(
            'CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT '
            . ($dropOnCommit ? 'DROP' : 'PRESERVE ROWS')
        );
        $statement = $database->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = to_regclass(:table)
             AND oid <> to_regclass(:public_table)
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Test może używać wyłącznie tabel tymczasowych.');
    }
    $database->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    foreach (['private_conversations', 'private_messages'] as $table) {
        $statement = $database->prepare(
            "SELECT count(*) FROM pg_class WHERE oid = pg_get_serial_sequence(:table, 'id')::regclass
             AND oid <> pg_get_serial_sequence(:public_table, 'id')::regclass
             AND relnamespace = pg_my_temp_schema() AND relpersistence = 't'"
        );
        $statement->execute(['table' => $table, 'public_table' => 'public.' . $table]);
        $assert((int) $statement->fetchColumn() === 1, 'Sekwencje muszą być niezależne od danych aplikacji.');
    }
    $database->exec(
        'ALTER TABLE pg_temp.private_conversations
             ADD FOREIGN KEY (first_user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (second_user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE;
         ALTER TABLE pg_temp.private_messages
             ADD FOREIGN KEY (conversation_id) REFERENCES pg_temp.private_conversations (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (sender_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE;
         ALTER TABLE pg_temp.user_blocks
             ADD FOREIGN KEY (blocker_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (blocked_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE;
         ALTER TABLE pg_temp.private_message_reads
             ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
             ADD FOREIGN KEY (conversation_id) REFERENCES pg_temp.private_conversations (id) ON DELETE CASCADE'
    );
    $database->exec(
        "INSERT INTO pg_temp.users (id, name, email, password_hash)
         VALUES (101, 'Łucja <svg>', 'message-first@example.test', 'hash'),
                (202, 'Drugi', 'message-second@example.test', 'hash'),
                (303, 'Osoba trzecia', 'message-third@example.test', 'hash')"
    );
};

$failWrites = static function (PDO $database): void {
    $database->exec(
        'CREATE FUNCTION pg_temp.reject_private_message() RETURNS trigger LANGUAGE plpgsql AS $$
         BEGIN RAISE EXCEPTION \'Testowa odmowa zapisu wiadomości\'; END $$;
         CREATE TRIGGER reject_private_message BEFORE INSERT ON pg_temp.private_messages
         FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_private_message()'
    );
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $prepare($connection);
    $messages = new MessageRepository($connection);
    $run = static function (string $name, callable $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT message_repository_test');
        try {
            $test();
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception::class . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT message_repository_test');
            $connection->exec('RELEASE SAVEPOINT message_repository_test');
        }
    };

    $run('Normalizacja i walidacja zachowują Unicode, HTML jako tekst i dozwolone nowe linie', static function () use ($assert): void {
        $assert(MessageService::normalize(" \tŁódź\r\nDruga\rlinia\n ") === "Łódź\nDruga\nlinia");
        $assert(MessageService::normalize("\0Tekst\0") === "\0Tekst\0");
        foreach (['Ź', '<script>alert(1)</script> & tekst', "Linia\nDruga\tlinia", str_repeat('ż', 4000)] as $body) {
            $assert(MessageService::validate($body) === []);
        }
        foreach (['', " \t\r\n", "\u{00A0}\u{2003}", str_repeat('ż', 4001), "\0Tekst", "x\x0By", "x\u{0085}y", "\xC3\x28"] as $body) {
            $assert(MessageService::validate($body) !== []);
        }
    });

    $run('Pusta skrzynka ma jedną stronę, a nieistniejący wątek nie udostępnia historii', static function () use ($messages, $assert): void {
        $assert($messages->findPageForUser(101, PHP_INT_MAX) === ['entries' => [], 'page' => 1, 'total' => 0, 'totalPages' => 1]);
        $assert($messages->findForUser(101, PHP_INT_MAX) === null);
        $assert($messages->findBetweenUsers(101, 202) === null);
        $assert($messages->findMessagesPageForUser(101, PHP_INT_MAX) === null);
    });

    $run('Rozpoczęcie rozmowy w obu kierunkach tworzy jedną uporządkowaną parę', static function () use ($messages, $connection, $assert): void {
        $first = $messages->startWithMessageForUser(202, 101, 'Pierwsza wiadomość');
        $second = $messages->startWithMessageForUser(101, 202, 'Odpowiedź');
        $assert($first !== null && $first === $second);
        $assert((int) $connection->query('SELECT count(*) FROM private_conversations')->fetchColumn() === 1);
        $pair = $connection->query('SELECT first_user_id, second_user_id FROM private_conversations')->fetch(PDO::FETCH_ASSOC);
        $assert((int) $pair['first_user_id'] === 101 && (int) $pair['second_user_id'] === 202);
        $assert(array_column($messages->findMessagesPageForUser(101, $first)['entries'], 'senderId') === [202, 101]);
        $assert($messages->findBetweenUsers(202, 101)['id'] === $first);
        $assert($messages->findBetweenUsers(101, 202)['id'] === $first);
    });

    $run('Rozmowa z sobą, brakujące konta i niepoprawne ID nie tworzą pustych wątków', static function () use ($messages, $connection, $assert): void {
        foreach ([[101, 101], [101, 999], [999, 101], [0, 202], [-1, 202]] as [$userId, $recipientId]) {
            $assert($messages->startWithMessageForUser($userId, $recipientId, 'Test') === null);
            $assert($messages->findBetweenUsers($userId, $recipientId) === null);
        }
        $assert($connection->inTransaction());
        $assert((int) $connection->query('SELECT count(*) FROM private_conversations')->fetchColumn() === 0);
        $assert((int) $connection->query('SELECT count(*) FROM private_messages')->fetchColumn() === 0);
    });

    $run('Wyłącznie uczestnicy odczytują i zapisują wiadomości w danym wątku', static function () use ($messages, $assert): void {
        $first = $messages->startWithMessageForUser(101, 202, 'Sekret A i B');
        $other = $messages->startWithMessageForUser(202, 303, 'Sekret B i C');
        $assert($messages->findForUser(101, $first)['recipientId'] === 202);
        $assert($messages->findForUser(202, $first)['recipientId'] === 101);
        foreach ([[303, $first], [101, $other], [0, $first], [999, $first]] as [$userId, $conversationId]) {
            $assert($messages->findForUser($userId, $conversationId) === null);
            $assert($messages->findMessagesPageForUser($userId, $conversationId) === null);
            $assert($messages->sendForUser($userId, $conversationId, 'Próba podszycia') === null);
        }
        $sentId = $messages->sendForUser(202, $first, 'Odpowiedź uczestnika');
        $assert($sentId !== null);
        $history = $messages->findMessagesPageForUser(101, $first);
        $assert($history['total'] === 2 && $history['entries'][1]['senderId'] === 202);
        $assert($messages->findPageForUser(101)['total'] === 1);
        $assert($messages->findPageForUser(202)['total'] === 2);
        $assert($messages->findPageForUser(303)['total'] === 1);
    });

    $run('Dane rozmów zawierają publiczny profil odbiorcy i ograniczony podgląd bez sekretów konta', static function () use ($messages, $connection, $assert): void {
        $connection->exec("UPDATE pg_temp.users SET avatar_version = repeat('a', 32) WHERE id = 202");
        $id = $messages->startWithMessageForUser(101, 202, str_repeat('ż', 200));
        $conversation = $messages->findForUser(101, $id);
        $assert(array_keys($conversation) === ['id', 'recipientId', 'recipientName', 'recipientAvatarUrl', 'createdAt']);
        $assert($conversation['recipientAvatarUrl'] === '/avatars/202?v=' . str_repeat('a', 32));
        $entry = $messages->findPageForUser(101)['entries'][0];
        $assert(array_keys($entry) === ['id', 'recipientId', 'recipientName', 'recipientAvatarUrl', 'lastMessagePreview', 'lastMessageSenderId', 'lastMessageAt', 'unreadCount']);
        $assert($entry['lastMessagePreview'] === str_repeat('ż', 160));
        $assert($entry['lastMessageSenderId'] === 101 && $entry['lastMessageAt'] !== null);
        $assert($entry['unreadCount'] === 0);
        $assert(array_keys($messages->findMessagesPageForUser(101, $id)['entries'][0]) === ['id', 'senderId', 'body', 'createdAt', 'isRemoved']);
    });

    $run('Liczniki obejmują wyłącznie otrzymane wiadomości po kursorze danej osoby', static function () use ($messages, $connection, $assert): void {
        $id = $messages->startWithMessageForUser(101, 202, 'Pierwsza do odbiorcy');
        $firstMessageId = $messages->findMessagesPageForUser(202, $id)['entries'][0]['id'];
        $messages->sendForUser(202, $id, 'Odpowiedź nadawcy');
        $messages->sendForUser(101, $id, 'Druga do odbiorcy');
        $assert($messages->findPageForUser(101)['entries'][0]['unreadCount'] === 1);
        $assert($messages->findPageForUser(202)['entries'][0]['unreadCount'] === 2);
        $statement = $connection->prepare(
            'INSERT INTO private_message_reads (user_id, conversation_id, last_read_message_id) VALUES (202, :conversation_id, :last_read_id)'
        );
        $statement->execute(['conversation_id' => $id, 'last_read_id' => $firstMessageId]);
        $assert($messages->findPageForUser(202)['entries'][0]['unreadCount'] === 1);
        $assert($messages->findPageForUser(101)['entries'][0]['unreadCount'] === 1);
        $assert($messages->findPageForUser(303)['entries'] === []);
        $assert($messages->findMessagesPageForUser(202, $id)['total'] === 3);
        $assert($messages->findPageForUser(202)['entries'][0]['unreadCount'] === 1, 'Odczyt repozytorium nie zmienia kursora.');
    });

    $run('Blokada zatrzymuje zapis w obu kierunkach, zachowuje historię i nie tworzy pustych rozmów', static function () use ($messages, $connection, $assert): void {
        $id = $messages->startWithMessageForUser(101, 202, 'Historia sprzed blokady');
        $connection->exec('INSERT INTO user_blocks (blocker_id, blocked_id) VALUES (202, 101), (101, 303)');
        foreach ([[101, 202], [202, 101], [101, 303], [303, 101]] as [$sender, $recipient]) {
            $assert($messages->startWithMessageForUser($sender, $recipient, 'Zablokowany start') === null);
        }
        $assert($messages->sendForUser(101, $id, 'Zablokowany zapis') === null);
        $assert($messages->sendForUser(202, $id, 'Zablokowana odpowiedź') === null);
        $assert($messages->findMessagesPageForUser(101, $id)['total'] === 1);
        $assert($messages->findMessagesPageForUser(202, $id)['entries'][0]['body'] === 'Historia sprzed blokady');
        $assert($messages->findBetweenUsers(101, 303) === null);
        $assert((int) $connection->query('SELECT count(*) FROM private_conversations')->fetchColumn() === 1);
        $assert($connection->inTransaction());
        $connection->exec('DELETE FROM user_blocks WHERE blocker_id = 202 AND blocked_id = 101');
        $assert($messages->sendForUser(101, $id, 'Odblokowana wiadomość') !== null);
    });

    $run('Moderacja usuwa treść z projekcji historii i podglądu bez zmiany paginacji', static function () use ($messages, $connection, $assert): void {
        $id = $messages->startWithMessageForUser(101, 202, 'Treść ukryta przez moderatora');
        $statement = $connection->prepare('UPDATE private_messages SET moderated_at = CURRENT_TIMESTAMP WHERE conversation_id = :id');
        $statement->execute(['id' => $id]);
        foreach ([101, 202] as $viewer) {
            $history = $messages->findMessagesPageForUser($viewer, $id);
            $assert($history['total'] === 1 && $history['totalPages'] === 1);
            $assert($history['entries'][0]['body'] === '' && $history['entries'][0]['isRemoved']);
            $assert($messages->findPageForUser($viewer)['entries'][0]['lastMessagePreview'] === 'Wiadomość ukryta przez moderację.');
        }
        $assert($messages->findPageForUser(202)['entries'][0]['unreadCount'] === 1);
    });

    $run('Skrzynka dzieli 25 rozmów na strony 20/5 i sortuje według ostatniej aktywności', static function () use ($messages, $connection, $assert): void {
        $connection->exec(
            "INSERT INTO pg_temp.users (id, name, email, password_hash)
             SELECT id, 'Odbiorca ' || id, 'message-member-' || id || '@example.test', 'hash'
             FROM generate_series(401, 425) AS id"
        );
        $ids = [];
        for ($recipientId = 401; $recipientId <= 425; $recipientId++) {
            $ids[] = $messages->startWithMessageForUser(101, $recipientId, 'Rozmowa ' . $recipientId);
        }
        $messages->startWithMessageForUser(202, 303, 'Cudza rozmowa');
        $first = $messages->findPageForUser(101);
        $second = $messages->findPageForUser(101, 2);
        $assert($first['total'] === 25 && $first['totalPages'] === 2);
        $assert(count($first['entries']) === 20 && count($second['entries']) === 5);
        $assert([...array_column($first['entries'], 'id'), ...array_column($second['entries'], 'id')] === array_reverse($ids));
        $messages->sendForUser(101, $ids[0], 'Nowa aktywność');
        $assert($messages->findPageForUser(101)['entries'][0]['id'] === $ids[0]);
        $assert($messages->findPageForUser(101, PHP_INT_MAX)['page'] === 2);
        $assert(count($messages->findPageForUser(101, 1, 0)['entries']) === 1);
        $assert(count($messages->findPageForUser(101, 1, PHP_INT_MAX)['entries']) === 25);
    });

    $run('Historia jest chronologiczna, ma strony 30/30/5 i domyślnie otwiera ostatnią stronę', static function () use ($messages, $assert): void {
        $id = $messages->startWithMessageForUser(101, 202, 'Wiadomość 1');
        $ids = [$messages->findMessagesPageForUser(101, $id)['entries'][0]['id']];
        for ($number = 2; $number <= 65; $number++) {
            $ids[] = $messages->sendForUser($number % 2 === 0 ? 202 : 101, $id, 'Wiadomość ' . $number);
        }
        $all = [];
        foreach ([1 => 30, 2 => 30, 3 => 5] as $page => $count) {
            $result = $messages->findMessagesPageForUser(101, $id, $page);
            $assert($result['page'] === $page && $result['total'] === 65 && $result['totalPages'] === 3);
            $assert(count($result['entries']) === $count);
            $all = [...$all, ...array_column($result['entries'], 'id')];
        }
        $assert($all === $ids);
        $assert($messages->findMessagesPageForUser(202, $id)['page'] === 3);
        $assert($messages->findMessagesPageForUser(202, $id, PHP_INT_MAX)['page'] === 3);
        $assert($messages->findMessagesPageForUser(202, $id, -1)['page'] === 1);
        $assert(count($messages->findMessagesPageForUser(101, $id, 1, 0)['entries']) === 1);
        $assert(count($messages->findMessagesPageForUser(101, $id, 1, PHP_INT_MAX)['entries']) === 65);
    });

    $run('Niepoprawne wiadomości nie tworzą rozmów ani nie zmieniają istniejącej historii i dat', static function () use ($messages, $assert): void {
        $id = $messages->startWithMessageForUser(101, 202, "  Zażółć\r\n<script>tekst</script>  ");
        $before = $messages->findMessagesPageForUser(101, $id);
        $createdAt = $messages->findForUser(101, $id)['createdAt'];
        $assert($before['entries'][0]['body'] === "Zażółć\n<script>tekst</script>");
        foreach (['', " \n", str_repeat('a', 4001), "\0Tekst", "Tekst\0", "x\x0By", "\xC3\x28"] as $body) {
            foreach ([static fn () => $messages->startWithMessageForUser(101, 303, $body), static fn () => $messages->sendForUser(101, $id, $body)] as $write) {
                $rejected = false;
                try {
                    $write();
                } catch (InvalidArgumentException) {
                    $rejected = true;
                }
                $assert($rejected);
            }
        }
        $assert($messages->findMessagesPageForUser(101, $id) === $before);
        $assert($messages->findBetweenUsers(101, 303) === null);
        $messages->startWithMessageForUser(202, 101, 'Kolejna wiadomość');
        $assert($messages->findForUser(101, $id)['createdAt'] === $createdAt);
        $assert($messages->findMessagesPageForUser(101, $id)['entries'][0] === $before['entries'][0]);
    });

    $run('Baza odrzuca duplikaty par, rozmowę z sobą, odwrotną parę i niepoprawną treść', static function () use ($messages, $connection, $assert): void {
        $id = $messages->startWithMessageForUser(101, 202, 'Poprawna wiadomość');
        $queries = [
            'INSERT INTO private_conversations (first_user_id, second_user_id) VALUES (101, 202)',
            'INSERT INTO private_conversations (first_user_id, second_user_id) VALUES (101, 101)',
            'INSERT INTO private_conversations (first_user_id, second_user_id) VALUES (202, 101)',
            "INSERT INTO private_messages (conversation_id, sender_id, body) VALUES ($id, 101, '')",
            "INSERT INTO private_messages (conversation_id, sender_id, body) VALUES ($id, 101, repeat('a', 4001))",
            "INSERT INTO private_messages (conversation_id, sender_id, body) VALUES ($id, 999, 'Brak konta')",
        ];
        foreach ($queries as $sql) {
            $connection->exec('SAVEPOINT message_invalid_sql');
            try {
                $rejected = false;
                try {
                    $connection->exec($sql);
                } catch (PDOException $exception) {
                    $rejected = in_array($exception->getCode(), ['23505', '23514', '23503'], true);
                }
                $assert($rejected);
            } finally {
                $connection->exec('ROLLBACK TO SAVEPOINT message_invalid_sql');
                $connection->exec('RELEASE SAVEPOINT message_invalid_sql');
            }
        }
    });

    $run('Błąd pierwszej wiadomości wycofuje rozmowę i zachowuje zewnętrzną transakcję', static function () use ($messages, $connection, $failWrites, $assert): void {
        $failWrites($connection);
        $rejected = false;
        try {
            $messages->startWithMessageForUser(101, 202, 'Zapis, który się nie powiedzie');
        } catch (PDOException) {
            $rejected = true;
        }
        $assert($rejected && $connection->inTransaction());
        $assert((int) $connection->query('SELECT count(*) FROM private_conversations')->fetchColumn() === 0);
        $assert((int) $connection->query('SELECT count(*) FROM private_messages')->fetchColumn() === 0);
        $connection->exec('DROP TRIGGER reject_private_message ON pg_temp.private_messages');
        $assert($messages->startWithMessageForUser(101, 202, 'Ponowienie po błędzie') !== null);
    });

    $run('Samodzielna transakcja zatwierdza parę i wiadomość oraz wycofuje błąd bez pustego wątku', static function () use ($prepare, $failWrites, $assert): void {
        $standalone = (new Database())->connect();
        $prepare($standalone, false);
        $repository = new MessageRepository($standalone);
        $id = $repository->startWithMessageForUser(101, 202, 'Zatwierdzona wiadomość');
        $assert($id !== null && !$standalone->inTransaction());
        $assert($repository->startWithMessageForUser(202, 101, 'Zatwierdzona odpowiedź') === $id);
        $assert($repository->findMessagesPageForUser(101, $id)['total'] === 2);
        $failWrites($standalone);
        $rejected = false;
        try {
            $repository->startWithMessageForUser(101, 303, 'Nieudany zapis');
        } catch (PDOException) {
            $rejected = true;
        }
        $assert($rejected && !$standalone->inTransaction());
        $assert($repository->findBetweenUsers(101, 303) === null);
        $assert($repository->findMessagesPageForUser(101, $id)['total'] === 2);
    });

    $run('Usunięcie konta lub rozmowy usuwa tylko powiązane wiadomości', static function () use ($messages, $connection, $assert): void {
        $first = $messages->startWithMessageForUser(101, 202, 'Rozmowa do usunięcia');
        $messages->sendForUser(202, $first, 'Odpowiedź również znika');
        $other = $messages->startWithMessageForUser(202, 303, 'Rozmowa do zachowania');
        $connection->exec('DELETE FROM pg_temp.users WHERE id = 101');
        $assert($messages->findForUser(202, $first) === null);
        $assert($messages->findMessagesPageForUser(202, $other)['total'] === 1);
        $assert((int) $connection->query('SELECT count(*) FROM private_messages')->fetchColumn() === 1);
        $statement = $connection->prepare('DELETE FROM pg_temp.private_conversations WHERE id = :id');
        $statement->execute(['id' => $other]);
        $assert((int) $connection->query('SELECT count(*) FROM private_messages')->fetchColumn() === 0);
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować izolowanych testów wiadomości: ' . $exception::class . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
