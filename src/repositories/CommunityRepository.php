<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\CommunityService;
use App\Service\RateLimitException;
use Closure;
use InvalidArgumentException;
use PDO;
use Throwable;

final class CommunityRepository
{
    private static int $savepointSequence = 0;

    public function __construct(private readonly PDO $connection)
    {
    }

    /** @return array{unreadCount: int, unreadReplyCount: int, isModerator: bool} */
    public function navigationStateForUser(int $userId): array
    {
        $statement = $this->connection->prepare('SELECT is_moderator FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        return [
            'unreadCount' => $this->unreadCountForUser($userId),
            'unreadReplyCount' => (new ReplyNotificationRepository($this->connection))->unreadCountForUser($userId),
            'isModerator' => $this->toBool($statement->fetchColumn()),
        ];
    }

    public function unreadCountForUser(int $userId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM private_messages m
             JOIN private_conversations c ON c.id = m.conversation_id
             LEFT JOIN private_message_reads r ON r.conversation_id = c.id AND r.user_id = :reader
             WHERE (c.first_user_id = :first_participant OR c.second_user_id = :second_participant)
               AND m.sender_id <> :sender AND m.id > COALESCE(r.last_read_message_id, 0)'
        );
        $statement->execute(['reader' => $userId, 'first_participant' => $userId, 'second_participant' => $userId, 'sender' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function conversationUnreadCountForUser(int $userId, int $conversationId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM private_messages m
             JOIN private_conversations c ON c.id = m.conversation_id
             LEFT JOIN private_message_reads r ON r.conversation_id = c.id AND r.user_id = :reader
             WHERE c.id = :conversation
               AND (c.first_user_id = :first_participant OR c.second_user_id = :second_participant)
               AND m.sender_id <> :sender AND m.id > COALESCE(r.last_read_message_id, 0)'
        );
        $statement->execute(['reader' => $userId, 'conversation' => $conversationId,
            'first_participant' => $userId, 'second_participant' => $userId, 'sender' => $userId]);

        return (int) $statement->fetchColumn();
    }

    public function markReadForUser(int $userId, int $conversationId, int $lastMessageId): bool
    {
        if ($lastMessageId <= 0) {
            return false;
        }

        $statement = $this->connection->prepare(
            'INSERT INTO private_message_reads (user_id, conversation_id, last_read_message_id)
             SELECT :reader, c.id, m.id FROM private_conversations c
             JOIN private_messages m ON m.conversation_id = c.id AND m.id = :message
             WHERE c.id = :conversation AND (c.first_user_id = :first_participant OR c.second_user_id = :second_participant)
             ON CONFLICT (user_id, conversation_id) DO UPDATE
             SET last_read_message_id = GREATEST(private_message_reads.last_read_message_id, EXCLUDED.last_read_message_id)
             RETURNING user_id'
        );
        $statement->execute(['reader' => $userId, 'message' => $lastMessageId, 'conversation' => $conversationId, 'first_participant' => $userId, 'second_participant' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array{blockedByMe: bool, blockedEitherWay: bool} */
    public function blockStateForUser(int $userId, int $otherId): array
    {
        $statement = $this->connection->prepare(
            'SELECT blocker_id FROM user_blocks
             WHERE (blocker_id = :actor AND blocked_id = :other)
                OR (blocker_id = :reverse_other AND blocked_id = :reverse_actor)'
        );
        $statement->execute(['actor' => $userId, 'other' => $otherId, 'reverse_other' => $otherId, 'reverse_actor' => $userId]);
        $blockers = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));

        return ['blockedByMe' => in_array($userId, $blockers, true), 'blockedEitherWay' => $blockers !== []];
    }

    public function blockForUser(int $userId, int $otherId): bool
    {
        return $this->changeBlock($userId, $otherId, true);
    }

    public function unblockForUser(int $userId, int $otherId): bool
    {
        return $this->changeBlock($userId, $otherId, false);
    }

    public function blockedPageForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $count = $this->connection->prepare('SELECT COUNT(*) FROM user_blocks WHERE blocker_id = :id');
        $count->execute(['id' => $userId]);
        $paging = $this->pagination((int) $count->fetchColumn(), $page, $perPage);
        $statement = $this->connection->prepare(
            'SELECT u.id, u.name, u.avatar_version, b.created_at FROM user_blocks b
             JOIN users u ON u.id = b.blocked_id WHERE b.blocker_id = :id
             ORDER BY b.created_at DESC, u.id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $paging['perPage'], PDO::PARAM_INT);
        $statement->bindValue('offset', ($paging['page'] - 1) * $paging['perPage'], PDO::PARAM_INT);
        $statement->execute();
        $entries = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            $version = $row['avatar_version'];
            $entries[] = [
                'id' => $id,
                'name' => $row['name'],
                'avatarUrl' => is_string($version) && preg_match('/\A[a-f0-9]{32}\z/', $version) === 1 ? '/avatars/' . $id . '?v=' . $version : null,
                'createdAt' => $row['created_at'],
            ];
        }

        return ['entries' => $entries] + $paging;
    }

    public function reportTargetForUser(int $userId, string $type, int $targetId): ?array
    {
        return $this->findReportTarget($userId, $type, $targetId, false);
    }

    public function reportExistsForUser(int $userId, string $type, int $targetId): bool
    {
        return $this->existingReportId($userId, $type, $targetId) !== null;
    }

    public function createReportForUser(int $userId, string $type, int $targetId, string $reason): ?int
    {
        $reason = CommunityService::normalizeReason($reason);

        if (CommunityService::validateReason($reason) !== []) {
            return null;
        }

        $existing = $this->existingReportId($userId, $type, $targetId);
        if ($existing !== null) {
            return $existing;
        }
        $target = $this->findReportTarget($userId, $type, $targetId, false);
        if ($target === null) {
            return null;
        }

        // Lock the author before the target: report foreign keys must not invert edit/send lock order.
        return $this->withUsersLocked([$userId, $target['authorId']], function () use ($userId, $type, $targetId, $reason): ?int {
            $existing = $this->existingReportId($userId, $type, $targetId);

            if ($existing !== null) {
                return $existing;
            }

            $target = $this->findReportTarget($userId, $type, $targetId, true);

            if ($target === null) {
                return null;
            }

            $statement = $this->connection->prepare(
                'INSERT INTO community_reports (reporter_id, reported_user_id, target_type, target_id, snapshot, reason)
                 VALUES (:reporter, :author, :type, :target, :snapshot, :reason)
                 ON CONFLICT (reporter_id, target_type, target_id) DO NOTHING RETURNING id'
            );
            $statement->execute(['reporter' => $userId, 'author' => $target['authorId'], 'type' => $type, 'target' => $targetId, 'snapshot' => $target['body'], 'reason' => $reason]);
            $id = $statement->fetchColumn();

            return $id === false ? $this->existingReportId($userId, $type, $targetId) : (int) $id;
        });
    }

    public function reportsPageForModerator(int $moderatorId, int $page = 1, int $perPage = 20): ?array
    {
        if (!$this->isModerator($moderatorId)) {
            return null;
        }

        $total = (int) $this->connection->query("SELECT COUNT(*) FROM community_reports WHERE status = 'open'")->fetchColumn();
        $paging = $this->pagination($total, $page, $perPage);
        $statement = $this->connection->prepare(
            "SELECT r.id, r.target_type, r.target_id, r.snapshot, r.reason, r.status,
                    r.created_at, reporter.name AS reporter_name, author.name AS author_name
             FROM community_reports r JOIN users reporter ON reporter.id = r.reporter_id
             LEFT JOIN users author ON author.id = r.reported_user_id
             WHERE r.status = 'open' ORDER BY r.created_at ASC, r.id ASC LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue('limit', $paging['perPage'], PDO::PARAM_INT);
        $statement->bindValue('offset', ($paging['page'] - 1) * $paging['perPage'], PDO::PARAM_INT);
        $statement->execute();
        $entries = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[] = [
                'id' => (int) $row['id'], 'targetType' => $row['target_type'], 'targetId' => (int) $row['target_id'],
                'snapshot' => $row['snapshot'], 'reason' => $row['reason'], 'status' => $row['status'],
                'reporterName' => $row['reporter_name'], 'authorName' => $row['author_name'] ?? 'Usunięty użytkownik', 'createdAt' => $row['created_at'],
            ];
        }

        return ['entries' => $entries] + $paging;
    }

    public function resolveReportForModerator(int $moderatorId, int $reportId, string $action): bool
    {
        if (!in_array($action, ['dismiss', 'remove'], true)) {
            return false;
        }

        return $this->withUsersLocked([$moderatorId], function () use ($moderatorId, $reportId, $action): bool {
            if (!$this->isModerator($moderatorId)) {
                return false;
            }

            $lookup = $this->connection->prepare('SELECT target_type, target_id FROM community_reports WHERE id = :id');
            $lookup->execute(['id' => $reportId]);
            $target = $lookup->fetch(PDO::FETCH_ASSOC);

            if ($target === false) {
                return false;
            }

            // Lock reports for this target in one order, including when different moderators resolve them.
            $lock = $this->connection->prepare('SELECT id FROM community_reports WHERE target_type = :type AND target_id = :target ORDER BY id FOR UPDATE');
            $lock->execute(['type' => $target['target_type'], 'target' => $target['target_id']]);
            $lock->fetchAll(PDO::FETCH_COLUMN);
            $status = $this->connection->prepare('SELECT status FROM community_reports WHERE id = :id');
            $status->execute(['id' => $reportId]);

            if ($status->fetchColumn() !== 'open') {
                return false;
            }

            if ($action === 'remove') {
                $table = match ($target['target_type']) {
                    'comment' => 'anime_comments',
                    'message' => 'private_messages',
                    default => throw new InvalidArgumentException('Nieprawidłowy typ zgłoszenia.'),
                };
                $hide = $this->connection->prepare('UPDATE ' . $table . ' SET moderated_at = COALESCE(moderated_at, clock_timestamp()) WHERE id = :id');
                $hide->execute(['id' => $target['target_id']]);
                $resolve = $this->connection->prepare(
                    "UPDATE community_reports SET status = 'removed', resolved_by = :moderator, resolved_at = clock_timestamp()
                     WHERE target_type = :type AND target_id = :target AND status = 'open'"
                );
                $resolve->execute(['moderator' => $moderatorId, 'type' => $target['target_type'], 'target' => $target['target_id']]);
            } else {
                $resolve = $this->connection->prepare(
                    "UPDATE community_reports SET status = 'dismissed', resolved_by = :moderator, resolved_at = clock_timestamp() WHERE id = :id AND status = 'open'"
                );
                $resolve->execute(['moderator' => $moderatorId, 'id' => $reportId]);
            }

            return true;
        });
    }

    public function setModerator(int $userId, bool $enabled): bool
    {
        return $this->withUsersLocked([$userId], function () use ($userId, $enabled): bool {
            $statement = $this->connection->prepare('UPDATE users SET is_moderator = :enabled WHERE id = :id');
            $statement->bindValue('enabled', $enabled, PDO::PARAM_BOOL);
            $statement->bindValue('id', $userId, PDO::PARAM_INT);
            $statement->execute();

            return $statement->rowCount() === 1;
        });
    }

    public function withUsersLocked(array $userIds, Closure $callback): mixed
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        sort($userIds, SORT_NUMERIC);
        $ownsTransaction = !$this->connection->inTransaction();
        $savepoint = 'community_' . ++self::$savepointSequence;

        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        } else {
            $this->connection->exec('SAVEPOINT ' . $savepoint);
        }

        try {
            if ($userIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
                $statement = $this->connection->prepare('SELECT id FROM users WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE');
                $statement->execute($userIds);
                $statement->fetchAll(PDO::FETCH_COLUMN);
            }

            $result = $callback();

            if ($ownsTransaction) {
                $this->connection->commit();
            } else {
                $this->connection->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            } elseif (!$ownsTransaction && $this->connection->inTransaction()) {
                $this->connection->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $this->connection->exec('RELEASE SAVEPOINT ' . $savepoint);
            }

            throw $exception;
        }
    }

    public function assertActionAllowed(int $userId, string $action, string $hash): void
    {
        [$limit, $window] = $this->actionLimit($action);
        $cleanup = $this->connection->prepare("DELETE FROM community_activity WHERE user_id = :id AND created_at < clock_timestamp() - INTERVAL '1 day'");
        $cleanup->execute(['id' => $userId]);
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FILTER (WHERE created_at > clock_timestamp() - make_interval(secs => :window)) AS recent_count,
                    CEIL(EXTRACT(EPOCH FROM MIN(created_at) FILTER (WHERE created_at > clock_timestamp() - make_interval(secs => :oldest_window))
                        + make_interval(secs => :duration) - clock_timestamp())) AS window_retry,
                    CEIL(EXTRACT(EPOCH FROM MAX(created_at) FILTER (WHERE content_hash = :hash AND created_at > clock_timestamp() - INTERVAL \'30 seconds\')
                        + INTERVAL \'30 seconds\' - clock_timestamp())) AS duplicate_retry
             FROM community_activity WHERE user_id = :id AND action = :action'
        );
        $statement->execute(['window' => $window, 'oldest_window' => $window, 'duration' => $window, 'hash' => $hash, 'id' => $userId, 'action' => $action]);
        $state = $statement->fetch(PDO::FETCH_ASSOC);
        $retryAfter = 0;

        if ((int) $state['recent_count'] >= $limit) {
            $retryAfter = max(1, (int) $state['window_retry']);
        }

        if ($state['duplicate_retry'] !== null) {
            $retryAfter = max($retryAfter, 1, (int) $state['duplicate_retry']);
        }

        if ($retryAfter > 0) {
            throw new RateLimitException($retryAfter);
        }
    }

    public function recordAction(int $userId, string $action, string $hash): void
    {
        $this->actionLimit($action);
        $statement = $this->connection->prepare('INSERT INTO community_activity (user_id, action, content_hash) VALUES (:id, :action, :hash)');
        $statement->execute(['id' => $userId, 'action' => $action, 'hash' => $hash]);
    }

    private function changeBlock(int $userId, int $otherId, bool $blocked): bool
    {
        if ($userId <= 0 || $otherId <= 0 || $userId === $otherId) {
            return false;
        }

        return $this->withUsersLocked([$userId, $otherId], function () use ($userId, $otherId, $blocked): bool {
            $users = $this->connection->prepare('SELECT COUNT(*) FROM users WHERE id IN (:actor, :other)');
            $users->execute(['actor' => $userId, 'other' => $otherId]);

            if ((int) $users->fetchColumn() !== 2) {
                return false;
            }

            $sql = $blocked
                ? 'INSERT INTO user_blocks (blocker_id, blocked_id) VALUES (:actor, :other) ON CONFLICT DO NOTHING'
                : 'DELETE FROM user_blocks WHERE blocker_id = :actor AND blocked_id = :other';
            $statement = $this->connection->prepare($sql);
            $statement->execute(['actor' => $userId, 'other' => $otherId]);

            return true;
        });
    }

    private function findReportTarget(int $userId, string $type, int $targetId, bool $lock): ?array
    {
        if ($userId <= 0 || $targetId <= 0) {
            return null;
        }

        if ($type === 'comment') {
            $sql = 'SELECT t.id, t.user_id AS author_id, u.name AS author_name, t.body, t.anime_id, NULL AS conversation_id, t.parent_id
                    FROM anime_comments t JOIN users u ON u.id = t.user_id
                    WHERE t.id = :target AND t.user_id <> :actor AND t.moderated_at IS NULL AND t.deleted_at IS NULL';
            $parameters = ['target' => $targetId, 'actor' => $userId];
        } elseif ($type === 'message') {
            $sql = 'SELECT t.id, t.sender_id AS author_id, u.name AS author_name, t.body, NULL AS anime_id, t.conversation_id, NULL AS parent_id
                    FROM private_messages t JOIN users u ON u.id = t.sender_id
                    JOIN private_conversations c ON c.id = t.conversation_id
                    WHERE t.id = :target AND t.sender_id <> :actor AND t.moderated_at IS NULL
                      AND (c.first_user_id = :first_participant OR c.second_user_id = :second_participant)';
            $parameters = ['target' => $targetId, 'actor' => $userId, 'first_participant' => $userId, 'second_participant' => $userId];
        } else {
            return null;
        }

        $statement = $this->connection->prepare($sql . ($lock ? ' FOR SHARE OF t' : ''));
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'targetType' => $type, 'targetId' => (int) $row['id'], 'authorId' => (int) $row['author_id'],
            'authorName' => $row['author_name'], 'body' => $row['body'],
            'animeId' => $row['anime_id'] === null ? null : (int) $row['anime_id'],
            'conversationId' => $row['conversation_id'] === null ? null : (int) $row['conversation_id'],
            'parentId' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
        ];
    }

    private function existingReportId(int $userId, string $type, int $targetId): ?int
    {
        $statement = $this->connection->prepare('SELECT id FROM community_reports WHERE reporter_id = :actor AND target_type = :type AND target_id = :target');
        $statement->execute(['actor' => $userId, 'type' => $type, 'target' => $targetId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function isModerator(int $userId): bool
    {
        $statement = $this->connection->prepare('SELECT is_moderator FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);

        return $this->toBool($statement->fetchColumn());
    }

    private function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }

    private function pagination(int $total, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));

        return ['page' => max(1, min($page, $totalPages)), 'perPage' => $perPage, 'total' => $total, 'totalPages' => $totalPages];
    }

    /** @return array{int, int} */
    private function actionLimit(string $action): array
    {
        return match ($action) {
            'message' => [40, 60],
            'comment' => [15, 60],
            'report' => [5, 600],
            default => throw new InvalidArgumentException('Nieprawidłowy rodzaj aktywności.'),
        };
    }
}
