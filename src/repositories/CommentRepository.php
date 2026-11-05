<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\CommentService;
use InvalidArgumentException;
use PDO;

final class CommentRepository
{
    private const SELECT_COMMENTS = '
        SELECT comments.id, comments.anime_id, comments.user_id,
               CASE WHEN comments.moderated_at IS NULL THEN comments.body END AS body,
               comments.moderated_at IS NOT NULL AS is_removed,
               comments.created_at, comments.updated_at, users.name AS author_name, users.avatar_version
        FROM anime_comments AS comments
        INNER JOIN users ON users.id = comments.user_id';

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{entries: list<array>, page: int, total: int, totalPages: int} */
    public function findPageForAnime(int $animeId, int $page = 1, int $perPage = 10): array
    {
        $statement = $this->database->prepare('SELECT count(*) FROM anime_comments WHERE anime_id = :anime_id');
        $statement->execute(['anime_id' => $animeId]);
        $total = (int) $statement->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        $statement = $this->database->prepare(
            self::SELECT_COMMENTS . '
             WHERE comments.anime_id = :anime_id
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
             WHERE id = :id AND user_id = :user_id AND anime_id = :anime_id AND moderated_at IS NULL'
        );
        $statement->execute(['body' => $body, 'id' => $commentId, 'user_id' => $userId, 'anime_id' => $animeId]);

        return $statement->rowCount() === 1;
    }

    public function removeForUser(int $userId, int $animeId, int $commentId): bool
    {
        $statement = $this->database->prepare(
            'DELETE FROM anime_comments WHERE id = :id AND user_id = :user_id AND anime_id = :anime_id'
        );
        $statement->execute(['id' => $commentId, 'user_id' => $userId, 'anime_id' => $animeId]);

        return $statement->rowCount() === 1;
    }

    private function validateBody(string $body): void
    {
        $errors = CommentService::validate($body);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /** @return array{id: int, animeId: int, userId: int, authorName: string, avatarUrl: ?string, body: string, createdAt: string, updatedAt: ?string, isRemoved: bool} */
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
        ];
    }
}
