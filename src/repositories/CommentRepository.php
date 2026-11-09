<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\CommentService;
use Closure;
use InvalidArgumentException;
use PDO;
use Throwable;

final class CommentRepository
{
    private static int $savepointSequence = 0;

    private const SELECT_COMMENTS = '
        SELECT comments.id, comments.anime_id, comments.user_id,
               CASE WHEN comments.moderated_at IS NULL AND comments.deleted_at IS NULL THEN comments.body END AS body,
               comments.moderated_at IS NOT NULL AS is_removed,
               comments.parent_id, comments.deleted_at IS NOT NULL AS is_deleted,
               (SELECT count(*) FROM anime_comments replies WHERE replies.parent_id = comments.id) AS reply_count,
               comments.created_at, comments.updated_at, users.name AS author_name, users.avatar_version
        FROM anime_comments AS comments
        INNER JOIN users ON users.id = comments.user_id';

    private const ROOT_CONDITION = 'comments.parent_id IS NULL AND (comments.deleted_at IS NULL
        OR EXISTS (SELECT 1 FROM anime_comments replies WHERE replies.parent_id = comments.id))';

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{entries: list<array>, page: int, total: int, totalPages: int} */
    public function findPageForAnime(int $animeId, int $page = 1, int $perPage = 10): array
    {
        $statement = $this->database->prepare('SELECT count(*) FROM anime_comments comments WHERE comments.anime_id = :anime_id AND ' . self::ROOT_CONDITION);
        $statement->execute(['anime_id' => $animeId]);
        $total = (int) $statement->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        $statement = $this->database->prepare(
            self::SELECT_COMMENTS . '
             WHERE comments.anime_id = :anime_id AND ' . self::ROOT_CONDITION . '
             ORDER BY comments.created_at DESC, comments.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('anime_id', $animeId, PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'entries' => array_map($this->mapComment(...), $statement->fetchAll(PDO::FETCH_ASSOC)),
            'page' => $page,
            'total' => $total,
            'totalPages' => $totalPages,
        ];
    }

    public function findByIdForAnime(int $animeId, int $commentId): ?array
    {
        $statement = $this->database->prepare(
            self::SELECT_COMMENTS . ' WHERE comments.id = :id AND comments.anime_id = :anime_id'
        );
        $statement->execute(['id' => $commentId, 'anime_id' => $animeId]);
        $comment = $statement->fetch(PDO::FETCH_ASSOC);

        return $comment === false ? null : $this->mapComment($comment);
    }

    /** @return array{entries: list<array>, page: int, total: int, totalPages: int}|null */
    public function findRepliesPageForAnime(int $animeId, int $parentId, int $page = 1, int $perPage = 10): ?array
    {
        $parent = $this->findByIdForAnime($animeId, $parentId);
        if ($parent === null || $parent['parentId'] !== null) {
            return null;
        }

        $count = $this->database->prepare('SELECT count(*) FROM anime_comments WHERE anime_id = :anime_id AND parent_id = :parent_id');
        $count->execute(['anime_id' => $animeId, 'parent_id' => $parentId]);
        $total = (int) $count->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $statement = $this->database->prepare(
            self::SELECT_COMMENTS . '
             WHERE comments.anime_id = :anime_id AND comments.parent_id = :parent_id
             ORDER BY comments.created_at ASC, comments.id ASC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('anime_id', $animeId, PDO::PARAM_INT);
        $statement->bindValue('parent_id', $parentId, PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'entries' => array_map($this->mapComment(...), $statement->fetchAll(PDO::FETCH_ASSOC)),
            'page' => $page, 'total' => $total, 'totalPages' => $totalPages,
        ];
    }

    public function findByIdForUser(int $userId, int $animeId, int $commentId): ?array
    {
        $statement = $this->database->prepare(
            self::SELECT_COMMENTS . '
             WHERE comments.id = :id AND comments.user_id = :user_id AND comments.anime_id = :anime_id'
        );
        $statement->execute(['id' => $commentId, 'user_id' => $userId, 'anime_id' => $animeId]);
        $comment = $statement->fetch(PDO::FETCH_ASSOC);

        return $comment === false ? null : $this->mapComment($comment);
    }

    public function createForUser(int $userId, int $animeId, string $body): int
    {
        $this->validateBody($body);
        $statement = $this->database->prepare(
            'INSERT INTO anime_comments (user_id, anime_id, body) VALUES (:user_id, :anime_id, :body) RETURNING id'
        );
        $statement->execute(['user_id' => $userId, 'anime_id' => $animeId, 'body' => $body]);

        return (int) $statement->fetchColumn();
    }

    public function updateForUser(int $userId, int $animeId, int $commentId, string $body): bool
    {
        $this->validateBody($body);
        $statement = $this->database->prepare(
            'UPDATE anime_comments SET body = :body, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND user_id = :user_id AND anime_id = :anime_id AND moderated_at IS NULL AND deleted_at IS NULL'
        );
        $statement->execute(['body' => $body, 'id' => $commentId, 'user_id' => $userId, 'anime_id' => $animeId]);

        return $statement->rowCount() === 1;
    }

    public function removeForUser(int $userId, int $animeId, int $commentId): bool
    {
        return $this->withinTransaction(function () use ($userId, $animeId, $commentId): bool {
            $target = $this->findByIdForUser($userId, $animeId, $commentId);
            if ($target === null || $target['isDeleted']) {
                return false;
            }

            $rootId = $target['parentId'] ?? $commentId;
            $lock = $this->database->prepare('SELECT id FROM anime_comments WHERE id = :id AND anime_id = :anime_id FOR UPDATE');
            $lock->execute(['id' => $rootId, 'anime_id' => $animeId]);
            if ($lock->fetchColumn() === false) {
                return false;
            }

            if ($target['parentId'] === null) {
                $children = $this->database->prepare('SELECT EXISTS (SELECT 1 FROM anime_comments WHERE parent_id = :id)');
                $children->execute(['id' => $commentId]);
                if (in_array($children->fetchColumn(), [true, 1, '1', 't'], true)) {
                    $delete = $this->database->prepare('UPDATE anime_comments SET deleted_at = clock_timestamp() WHERE id = :id AND user_id = :user_id AND anime_id = :anime_id AND deleted_at IS NULL');
                    $delete->execute(['id' => $commentId, 'user_id' => $userId, 'anime_id' => $animeId]);

                    return $delete->rowCount() === 1;
                }
            }

            $delete = $this->database->prepare('DELETE FROM anime_comments WHERE id = :id AND user_id = :user_id AND anime_id = :anime_id AND deleted_at IS NULL');
            $delete->execute(['id' => $commentId, 'user_id' => $userId, 'anime_id' => $animeId]);
            $removed = $delete->rowCount() === 1;
            if ($removed && $target['parentId'] !== null) {
                $cleanup = $this->database->prepare('DELETE FROM anime_comments WHERE id = :id AND deleted_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM anime_comments replies WHERE replies.parent_id = :parent_id)');
                $cleanup->execute(['id' => $rootId, 'parent_id' => $rootId]);
            }

            return $removed;
        });
    }

    public function createReplyForUser(int $userId, int $animeId, int $parentId, string $body): ?int
    {
        $this->validateBody($body);

        return $this->withinTransaction(function () use ($userId, $animeId, $parentId, $body): ?int {
            $lock = $this->database->prepare('SELECT id FROM anime_comments WHERE id = :id AND anime_id = :anime_id AND parent_id IS NULL AND deleted_at IS NULL AND moderated_at IS NULL FOR UPDATE');
            $lock->execute(['id' => $parentId, 'anime_id' => $animeId]);
            if ($lock->fetchColumn() === false) {
                return null;
            }

            $statement = $this->database->prepare(
                'INSERT INTO anime_comments (user_id, anime_id, parent_id, body, created_at)
                 VALUES (:user_id, :anime_id, :parent_id, :body, clock_timestamp()) RETURNING id'
            );
            $statement->execute(['user_id' => $userId, 'anime_id' => $animeId, 'parent_id' => $parentId, 'body' => $body]);

            return (int) $statement->fetchColumn();
        });
    }

    private function withinTransaction(Closure $callback): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        $savepoint = 'comment_' . ++self::$savepointSequence;
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        } else {
            $this->database->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->database->commit();
            } else {
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            } elseif (!$ownsTransaction && $this->database->inTransaction()) {
                $this->database->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            throw $exception;
        }
    }

    private function validateBody(string $body): void
    {
        $errors = CommentService::validate($body);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /** @return array{id: int, animeId: int, userId: int, authorName: string, avatarUrl: ?string, body: string, createdAt: string, updatedAt: ?string, isRemoved: bool, parentId: ?int, isDeleted: bool, replyCount: int} */
    private function mapComment(array $row): array
    {
        $version = $row['avatar_version'];

        return [
            'id' => (int) $row['id'],
            'animeId' => (int) $row['anime_id'],
            'userId' => (int) $row['user_id'],
            'authorName' => (string) $row['author_name'],
            'avatarUrl' => is_string($version) && preg_match('/\A[a-f0-9]{32}\z/', $version) === 1
                ? '/avatars/' . (int) $row['user_id'] . '?v=' . $version : null,
            'body' => (string) $row['body'],
            'createdAt' => (string) $row['created_at'],
            'updatedAt' => $row['updated_at'] === null ? null : (string) $row['updated_at'],
            'isRemoved' => in_array($row['is_removed'], [true, 1, '1', 't'], true),
            'parentId' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'isDeleted' => in_array($row['is_deleted'], [true, 1, '1', 't'], true),
            'replyCount' => (int) $row['reply_count'],
        ];
    }
}
