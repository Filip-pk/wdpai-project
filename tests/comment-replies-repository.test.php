<?php

declare(strict_types=1);

use App\Database;
use App\Repository\CommentRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$connection = null;
$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    foreach (['users', 'anime', 'anime_comments'] as $table) {
        $connection->exec('CREATE TEMP TABLE ' . $table . ' (LIKE public.' . $table . ' INCLUDING ALL) ON COMMIT DROP');
        $check = $connection->prepare("SELECT relnamespace = pg_my_temp_schema() AND relpersistence = 't' FROM pg_class WHERE oid = to_regclass(:table)");
        $check->execute(['table' => $table]);
        $assert(in_array($check->fetchColumn(), [true, 1, '1', 't'], true), 'Only session-local tables may receive test writes.');
    }
    $connection->exec('ALTER TABLE pg_temp.users ALTER COLUMN id DROP DEFAULT');
    foreach (['anime', 'anime_comments'] as $table) {
        $check = $connection->prepare("SELECT relnamespace = pg_my_temp_schema() AND relpersistence = 't' FROM pg_class WHERE oid = pg_get_serial_sequence(:table, 'id')::regclass");
        $check->execute(['table' => $table]);
        $assert(in_array($check->fetchColumn(), [true, 1, '1', 't'], true), 'Only independent temporary identity sequences may be advanced.');
    }
    $connection->exec(<<<'SQL'
ALTER TABLE pg_temp.anime_comments
    ADD FOREIGN KEY (user_id) REFERENCES pg_temp.users (id) ON DELETE CASCADE,
    ADD FOREIGN KEY (anime_id) REFERENCES pg_temp.anime (id) ON DELETE CASCADE,
    ADD FOREIGN KEY (parent_id, anime_id) REFERENCES pg_temp.anime_comments (id, anime_id) ON DELETE CASCADE;
CREATE TRIGGER anime_comments_parent_guard BEFORE INSERT OR UPDATE OF parent_id, anime_id ON pg_temp.anime_comments
    FOR EACH ROW EXECUTE FUNCTION public.enforce_anime_comment_parent();
INSERT INTO pg_temp.users (id, name, email, password_hash)
    VALUES (101, 'Root author', 'replies-author@example.test', 'hash'),
           (102, 'Reply author', 'replies-second@example.test', 'hash'),
           (103, 'Other user', 'replies-other@example.test', 'hash');
INSERT INTO pg_temp.anime (id, title) VALUES (201, 'First anime'), (202, 'Second anime');
SQL);
    $comments = new CommentRepository($connection);
    $run = static function (string $name, Closure $test) use ($connection, &$passed, &$failed): void {
        $connection->exec('SAVEPOINT reply_repository_test');
        try {
            $test();
            $passed++;
            echo 'PASS ' . $name . PHP_EOL;
        } catch (Throwable $exception) {
            $failed++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT reply_repository_test');
            $connection->exec('RELEASE SAVEPOINT reply_repository_test');
        }
    };
    $rejectsSql = static function (Closure $write, array $codes) use ($connection, $assert): void {
        $connection->exec('SAVEPOINT invalid_reply');
        try {
            $rejected = false;
            try {
                $write();
            } catch (PDOException $exception) {
                $rejected = in_array($exception->getCode(), $codes, true);
            }
            $assert($rejected, 'Database must reject invalid reply or thread mutation.');
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT invalid_reply');
            $connection->exec('RELEASE SAVEPOINT invalid_reply');
        }
    };

    $run('root pagination excludes replies and keeps root chronology stable', static function () use ($comments, $assert): void {
        $rootIds = [];
        for ($index = 0; $index < 23; $index++) {
            $rootIds[] = $comments->createForUser(101, 201, 'Root ' . $index);
        }
        $reply = $comments->createReplyForUser(102, 201, $rootIds[0], 'An old root gets a new reply');
        $comments->createForUser(101, 202, 'Other anime');
        $all = [];
        foreach ([1 => 10, 2 => 10, 3 => 3] as $page => $count) {
            $result = $comments->findPageForAnime(201, $page);
            $assert($result['total'] === 23 && $result['totalPages'] === 3 && count($result['entries']) === $count, 'Replies must not consume root pagination slots.');
            $all = [...$all, ...array_column($result['entries'], 'id')];
        }
        $assert($all === array_reverse($rootIds) && !in_array($reply, $all, true), 'Root order is independent of reply activity.');
        $root = $comments->findByIdForAnime(201, $rootIds[0]);
        $assert($root['parentId'] === null && $root['replyCount'] === 1 && !$root['isDeleted'], 'Root projection contains thread metadata.');
    });

    $run('reply pagination is chronological, bounded and isolated to the requested root and anime', static function () use ($comments, $assert): void {
        $root = $comments->createForUser(101, 201, 'Root');
        $another = $comments->createForUser(101, 201, 'Another root');
        $ids = [];
        for ($index = 0; $index < 23; $index++) {
            $ids[] = $comments->createReplyForUser(102, 201, $root, 'Reply ' . $index);
        }
        $comments->createReplyForUser(103, 201, $another, 'Separate thread');
        $all = [];
        foreach ([1 => 10, 2 => 10, 3 => 3] as $page => $count) {
            $result = $comments->findRepliesPageForAnime(201, $root, $page);
            $assert($result['page'] === $page && $result['total'] === 23 && $result['totalPages'] === 3 && count($result['entries']) === $count, 'Each reply page contains only its own bounded thread.');
            $all = [...$all, ...array_column($result['entries'], 'id')];
        }
        $assert($all === $ids, 'Replies stay oldest-first with stable ID tie-breaker.');
        $assert($comments->findRepliesPageForAnime(201, $root, PHP_INT_MAX)['page'] === 3, 'Oversized page is clamped.');
        $assert(count($comments->findRepliesPageForAnime(201, $root, 1, 0)['entries']) === 1, 'Page size has a lower bound.');
        $assert(count($comments->findRepliesPageForAnime(201, $root, 1, PHP_INT_MAX)['entries']) === 23, 'Page size has an upper bound.');
        $assert($comments->findRepliesPageForAnime(202, $root) === null && $comments->findRepliesPageForAnime(201, $ids[0]) === null, 'Wrong anime and nested thread lookup are rejected.');
        $assert($comments->findByIdForAnime(202, $ids[0]) === null, 'Public lookup cannot cross anime IDs.');
    });

    $run('reply creation rejects absent, cross-anime, nested, deleted and moderated targets', static function () use ($comments, $connection, $assert): void {
        $root = $comments->createForUser(101, 201, 'Root');
        $reply = $comments->createReplyForUser(102, 201, $root, 'Reply');
        foreach ([[201, PHP_INT_MAX], [202, $root], [201, $reply]] as [$anime, $target]) {
            $assert($comments->createReplyForUser(103, $anime, $target, 'Rejected') === null, 'Only existing top-level comments in this anime accept replies.');
        }
        foreach (['deleted_at', 'moderated_at'] as $column) {
            $hidden = $comments->createForUser(101, 201, 'Hidden ' . $column);
            $statement = $connection->prepare('UPDATE anime_comments SET ' . $column . ' = clock_timestamp() WHERE id = :id');
            $statement->execute(['id' => $hidden]);
            $assert($comments->createReplyForUser(103, 201, $hidden, 'Rejected hidden target') === null, 'Hidden target cannot get new replies.');
        }
        $assert($comments->findByIdForAnime(201, $root)['replyCount'] === 1, 'Rejected replies never leave partial entries.');
    });

    $run('database guards enforce a single level, same anime and immutable thread assignment', static function () use ($comments, $connection, $rejectsSql): void {
        $root = $comments->createForUser(101, 201, 'Root');
        $reply = $comments->createReplyForUser(102, 201, $root, 'Reply');
        foreach ([[201, $reply, ['23514']], [202, $root, ['23503']], [201, PHP_INT_MAX, ['23503']]] as [$anime, $parent, $codes]) {
            $rejectsSql(static function () use ($connection, $anime, $parent): void {
                $statement = $connection->prepare("INSERT INTO anime_comments (anime_id, user_id, parent_id, body) VALUES (:anime, 103, :parent, 'Invalid direct reply')");
                $statement->execute(['anime' => $anime, 'parent' => $parent]);
            }, $codes);
        }
        $rejectsSql(static function () use ($connection, $root, $reply): void {
            $statement = $connection->prepare('UPDATE anime_comments SET parent_id = :reply WHERE id = :root');
            $statement->execute(['reply' => $reply, 'root' => $root]);
        }, ['23514']);
        $rejectsSql(static function () use ($connection, $reply): void {
            $statement = $connection->prepare('UPDATE anime_comments SET anime_id = 202 WHERE id = :id');
            $statement->execute(['id' => $reply]);
        }, ['23514']);
    });

    $run('reply ownership and validation protect all mutation paths', static function () use ($comments, $assert): void {
        $root = $comments->createForUser(101, 201, 'Root');
        $reply = $comments->createReplyForUser(102, 201, $root, 'Unicode reply 🔥 <script>text</script>');
        $assert($comments->findByIdForUser(101, 201, $reply) === null && !$comments->updateForUser(101, 201, $reply, 'Forged') && !$comments->removeForUser(101, 201, $reply), 'Root author does not own another user reply.');
        $assert(!$comments->removeForUser(102, 202, $reply), 'Wrong anime cannot delete reply.');
        foreach (['', " \n", str_repeat('ą', 2001), "Invalid\0", "\xFF"] as $body) {
            $rejected = false;
            try {
                $comments->createReplyForUser(102, 201, $root, $body);
            } catch (InvalidArgumentException) {
                $rejected = true;
            }
            $assert($rejected, 'Invalid reply body must be rejected before write.');
        }
        $assert($comments->updateForUser(102, 201, $reply, 'Edited reply'), 'Reply author can edit own active text.');
        $assert($comments->findByIdForAnime(201, $reply)['body'] === 'Edited reply', 'Reply is projected with its current body.');
        $assert($comments->removeForUser(102, 201, $reply), 'Reply author can delete own reply.');
        $assert($comments->findByIdForAnime(201, $reply) === null && $comments->findByIdForAnime(201, $root)['replyCount'] === 0, 'Leaf removal does not remove its root.');
    });

    $run('deleting a root preserves other authors replies and never exposes deleted text', static function () use ($comments, $assert): void {
        $root = $comments->createForUser(101, 201, 'Root text must disappear');
        $first = $comments->createReplyForUser(102, 201, $root, 'Reply one');
        $second = $comments->createReplyForUser(103, 201, $root, 'Reply two');
        $assert($comments->removeForUser(101, 201, $root), 'Root owner can remove original text.');
        $deleted = $comments->findByIdForAnime(201, $root);
        $assert($deleted['isDeleted'] && $deleted['body'] === '' && $deleted['replyCount'] === 2, 'Deleted root is represented only by a tombstone.');
        $assert($comments->findPageForAnime(201)['entries'][0]['body'] === '' && $comments->findByIdForUser(101, 201, $root)['body'] === '', 'No read projection reveals deleted original text.');
        $assert(array_column($comments->findRepliesPageForAnime(201, $root)['entries'], 'id') === [$first, $second], 'Other authors replies survive root deletion.');
        $assert(!$comments->updateForUser(101, 201, $root, 'Restore') && !$comments->removeForUser(101, 201, $root), 'Deleted root cannot be restored or repeatedly removed.');
        $assert($comments->createReplyForUser(103, 201, $root, 'New reply') === null, 'Deleted root cannot receive new replies.');
    });

    $run('removing the last reply cleans an already deleted root without touching other threads', static function () use ($comments, $assert): void {
        $root = $comments->createForUser(101, 201, 'Root');
        $other = $comments->createForUser(101, 201, 'Untouched root');
        $first = $comments->createReplyForUser(102, 201, $root, 'First');
        $second = $comments->createReplyForUser(103, 201, $root, 'Second');
        $comments->removeForUser(101, 201, $root);
        $assert($comments->removeForUser(102, 201, $first), 'First reply removed.');
        $assert($comments->findByIdForAnime(201, $root)['replyCount'] === 1, 'Deleted root remains while any reply survives.');
        $assert($comments->removeForUser(103, 201, $second), 'Last reply removed.');
        $assert($comments->findByIdForAnime(201, $root) === null, 'Empty tombstone is cleaned after last reply deletion.');
        $assert(array_column($comments->findPageForAnime(201)['entries'], 'id') === [$other], 'Unrelated thread remains intact.');
    });

    $run('moderating root text preserves reply visibility and moderation placeholders stay paginated', static function () use ($comments, $connection, $assert): void {
        $root = $comments->createForUser(101, 201, 'Root');
        $reply = $comments->createReplyForUser(102, 201, $root, 'Visible reply');
        $statement = $connection->prepare('UPDATE anime_comments SET moderated_at = clock_timestamp() WHERE id = :id');
        $statement->execute(['id' => $root]);
        $assert($comments->findByIdForAnime(201, $root)['body'] === '', 'Moderated root body stays hidden.');
        $assert($comments->findRepliesPageForAnime(201, $root)['entries'][0]['body'] === 'Visible reply', 'Root moderation does not hide another author reply.');
        $assert($comments->updateForUser(102, 201, $reply, 'Reply still editable'), 'Visible child remains editable by its author.');
        $statement->execute(['id' => $reply]);
        $entry = $comments->findRepliesPageForAnime(201, $root)['entries'][0];
        $assert($entry['id'] === $reply && $entry['isRemoved'] && $entry['body'] === '', 'Moderated reply has a placeholder without changing chronological position.');
        $assert(!$comments->updateForUser(102, 201, $reply, 'Restore'), 'Moderated reply cannot be restored by editing.');
    });

    $run('failed reply insertion rolls back nested work and keeps caller transaction usable', static function () use ($comments, $connection, $assert): void {
        $root = $comments->createForUser(101, 201, 'Root');
        $rejected = false;
        try {
            $comments->createReplyForUser(999, 201, $root, 'Missing author');
        } catch (PDOException $exception) {
            $rejected = $exception->getCode() === '23503';
        }
        $assert($rejected && $connection->inTransaction(), 'Failed nested write retains caller transaction.');
        $assert($comments->findByIdForAnime(201, $root)['replyCount'] === 0, 'Failed insert leaves no orphan reply.');
        $assert($comments->createReplyForUser(102, 201, $root, 'Subsequent success') !== null, 'Caller can continue after invalid nested write.');
    });
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, 'FAIL reply fixture: ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo $passed . ' passed, ' . $failed . ' failed.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
