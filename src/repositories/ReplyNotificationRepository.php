<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ReplyNotificationRepository
{
    private const SELECT_NOTIFICATIONS = '
        SELECT notifications.id, reply.id AS reply_id, parent.id AS parent_id,
               reply.anime_id, anime.title AS anime_title, author.name AS author_name,
               notifications.created_at, notifications.read_at,
               (SELECT COUNT(*) FROM anime_comments AS sibling
                WHERE sibling.parent_id = parent.id
                  AND (sibling.created_at, sibling.id) <= (reply.created_at, reply.id)) AS reply_position
        FROM comment_reply_notifications AS notifications
        INNER JOIN anime_comments AS reply ON reply.id = notifications.reply_id
        INNER JOIN anime_comments AS parent ON parent.id = reply.parent_id
        INNER JOIN anime ON anime.id = reply.anime_id
        INNER JOIN users AS author ON author.id = reply.user_id
        WHERE notifications.user_id = :user_id AND parent.user_id = notifications.user_id
          AND reply.deleted_at IS NULL AND reply.moderated_at IS NULL';

    public function __construct(private readonly PDO $database)
    {
    }

    public function createForReply(int $replyId): bool
    {
        $statement = $this->database->prepare(
            'INSERT INTO comment_reply_notifications (user_id, reply_id)
             SELECT parent.user_id, reply.id FROM anime_comments AS reply
             INNER JOIN anime_comments AS parent ON parent.id = reply.parent_id AND parent.anime_id = reply.anime_id
             WHERE reply.id = :reply_id AND parent.parent_id IS NULL
               AND parent.user_id <> reply.user_id
               AND parent.deleted_at IS NULL AND parent.moderated_at IS NULL
               AND reply.deleted_at IS NULL AND reply.moderated_at IS NULL
             ON CONFLICT (reply_id) DO NOTHING RETURNING id'
        );
        $statement->execute(['reply_id' => $replyId]);

        return $statement->fetchColumn() !== false;
    }

    public function unreadCountForUser(int $userId): int
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM comment_reply_notifications AS notifications
             INNER JOIN anime_comments AS reply ON reply.id = notifications.reply_id
             INNER JOIN anime_comments AS parent ON parent.id = reply.parent_id
             WHERE notifications.user_id = :user_id AND parent.user_id = notifications.user_id
               AND notifications.read_at IS NULL AND reply.deleted_at IS NULL AND reply.moderated_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function findPageForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $count = $this->database->prepare(
            'SELECT COUNT(*), COALESCE(MAX(notifications.id), 0) AS snapshot_id
             FROM comment_reply_notifications AS notifications
             INNER JOIN anime_comments AS reply ON reply.id = notifications.reply_id
             INNER JOIN anime_comments AS parent ON parent.id = reply.parent_id
             WHERE notifications.user_id = :user_id AND parent.user_id = notifications.user_id
               AND reply.deleted_at IS NULL AND reply.moderated_at IS NULL'
        );
        $count->execute(['user_id' => $userId]);
        $state = $count->fetch(PDO::FETCH_ASSOC);
        $total = (int) $state['count'];
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $statement = $this->database->prepare(
            self::SELECT_NOTIFICATIONS . '
             ORDER BY notifications.created_at DESC, notifications.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'entries' => array_map($this->mapNotification(...), $statement->fetchAll(PDO::FETCH_ASSOC)),
            'page' => $page, 'total' => $total, 'totalPages' => $totalPages,
            'snapshotId' => (int) $state['snapshot_id'],
        ];
    }

    public function findByIdForUser(int $userId, int $notificationId): ?array
    {
        $statement = $this->database->prepare(self::SELECT_NOTIFICATIONS . ' AND notifications.id = :id');
        $statement->execute(['user_id' => $userId, 'id' => $notificationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapNotification($row);
    }

    public function markReadForUser(int $userId, int $notificationId): bool
    {
        $statement = $this->database->prepare(
            'UPDATE comment_reply_notifications SET read_at = COALESCE(read_at, clock_timestamp())
             WHERE user_id = :user_id AND id = :id RETURNING id'
        );
        $statement->execute(['user_id' => $userId, 'id' => $notificationId]);

        return $statement->fetchColumn() !== false;
    }

    public function markAllReadForUser(int $userId, int $lastNotificationId): bool
    {
        if ($lastNotificationId <= 0) {
            return false;
        }

        $statement = $this->database->prepare(
            'WITH owned_snapshot AS (
                 SELECT id FROM comment_reply_notifications WHERE user_id = :snapshot_user AND id = :snapshot_id
             ), updated AS (
                 UPDATE comment_reply_notifications SET read_at = COALESCE(read_at, clock_timestamp())
                 WHERE user_id = :reader AND id <= :last_id AND EXISTS (SELECT 1 FROM owned_snapshot)
                 RETURNING id
             ) SELECT EXISTS (SELECT 1 FROM owned_snapshot)'
        );
        $statement->execute(['snapshot_user' => $userId, 'snapshot_id' => $lastNotificationId, 'reader' => $userId, 'last_id' => $lastNotificationId]);

        return in_array($statement->fetchColumn(), [true, 1, '1', 't'], true);
    }

    private function mapNotification(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'replyId' => (int) $row['reply_id'],
            'parentId' => (int) $row['parent_id'], 'animeId' => (int) $row['anime_id'],
            'animeTitle' => (string) $row['anime_title'], 'authorName' => (string) $row['author_name'],
            'createdAt' => (string) $row['created_at'], 'isRead' => $row['read_at'] !== null,
            'replyPage' => max(1, (int) ceil((int) $row['reply_position'] / 10)),
        ];
    }
}
