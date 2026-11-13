<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\CommunityService;
use Closure;
use PDO;

final class ModerationRepository
{
    public const BAN_DURATIONS = [3600, 86400, 259200, 604800, 2592000];

    public function __construct(private readonly PDO $connection)
    {
    }

    public function isModerator(int $userId): bool
    {
        $statement = $this->connection->prepare('SELECT is_moderator FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        return in_array($statement->fetchColumn(), [true, 1, '1', 't'], true);
    }

    public function activeBanForUser(int $userId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, user_id, moderator_id, reason, starts_at, ends_at FROM user_bans
             WHERE user_id = :id AND revoked_at IS NULL AND starts_at <= clock_timestamp()
               AND ends_at > clock_timestamp() ORDER BY ends_at DESC, id DESC LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? null : $this->mapBan($row);
    }

    public function reportsPageForModerator(int $actor, int $page = 1, array $filters = [], int $perPage = 20): ?array
    {
        if (!$this->canModerate($actor)) {
            return null;
        }
        [$where, $parameters] = $this->commentFilters($filters);
        $where[] = "r.target_type = 'comment'";
        $status = $filters['status'] ?? 'open';
        if (in_array($status, ['open', 'dismissed', 'removed'], true)) {
            $where[] = 'r.status = :report_status';
            $parameters['report_status'] = $status;
        }
        $from = 'FROM community_reports r JOIN anime_comments c ON c.id = r.target_id
                 JOIN users u ON u.id = c.user_id JOIN users reporter ON reporter.id = r.reporter_id
                 JOIN anime a ON a.id = c.anime_id';
        return $this->page(
            'SELECT r.id, r.target_id AS comment_id, r.snapshot, r.reason, r.status,
                    r.created_at, reporter.name AS reporter_name, c.user_id, u.name AS author_name,
                    c.anime_id, a.title AS anime_title, c.parent_id, c.body, c.moderated_at, c.deleted_at,
                    c.created_at AS comment_created_at ' . $from,
            $from, $where, $parameters, 'r.created_at ASC, r.id ASC', $page, $perPage,
            fn (array $row): array => [
                'id' => (int) $row['id'], 'commentId' => (int) $row['comment_id'], 'targetId' => (int) $row['comment_id'],
                'snapshot' => $row['snapshot'], 'reason' => $row['reason'], 'status' => $row['status'],
                'createdAt' => $row['created_at'], 'reporterName' => $row['reporter_name'],
                'userId' => (int) $row['user_id'], 'authorName' => $row['author_name'],
                'animeId' => (int) $row['anime_id'], 'animeTitle' => $row['anime_title'],
                'parentId' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'body' => $row['body'], 'moderatedAt' => $row['moderated_at'], 'deletedAt' => $row['deleted_at'],
                'commentStatus' => $this->commentStatus($row), 'commentCreatedAt' => $row['comment_created_at'],
            ],
        );
    }

    public function commentsPageForModerator(int $actor, int $page = 1, array $filters = [], int $perPage = 20): ?array
    {
        if (!$this->canModerate($actor)) {
            return null;
        }
        [$where, $parameters] = $this->commentFilters($filters);
        $status = $filters['status'] ?? 'all';
        if ($status === 'active') {
            $where[] = 'c.moderated_at IS NULL AND c.deleted_at IS NULL';
        } elseif ($status === 'removed') {
            $where[] = 'c.moderated_at IS NOT NULL';
        } elseif ($status === 'deleted') {
            $where[] = 'c.deleted_at IS NOT NULL';
        }
        $from = 'FROM anime_comments c JOIN users u ON u.id = c.user_id JOIN anime a ON a.id = c.anime_id';
        return $this->page(
            'SELECT c.*, u.name AS author_name, a.title AS anime_title ' . $from,
            $from, $where, $parameters, 'c.created_at DESC, c.id DESC', $page, $perPage,
            fn (array $row): array => [
                'id' => (int) $row['id'], 'userId' => (int) $row['user_id'], 'authorName' => $row['author_name'],
                'animeId' => (int) $row['anime_id'], 'animeTitle' => $row['anime_title'],
                'parentId' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'body' => $row['body'], 'createdAt' => $row['created_at'],
                'moderatedAt' => $row['moderated_at'], 'deletedAt' => $row['deleted_at'],
                'status' => $this->commentStatus($row),
            ],
        );
    }

    public function usersPageForModerator(int $actor, int $page = 1, array $filters = [], int $perPage = 20): ?array
    {
        if (!$this->canModerate($actor)) {
            return null;
        }
        $where = [];
        $parameters = [];
        $this->textFilter($where, $parameters, 'author', $filters['author'] ?? '', 'u.name', 'u.id');
        $activeBan = 'SELECT b.id, b.reason, b.starts_at, b.ends_at FROM user_bans b
                     WHERE b.user_id = u.id AND b.revoked_at IS NULL AND b.starts_at <= clock_timestamp()
                       AND b.ends_at > clock_timestamp() ORDER BY b.ends_at DESC, b.id DESC LIMIT 1';
        $from = 'FROM users u LEFT JOIN LATERAL (' . $activeBan . ') b ON true';
        return $this->page(
            'SELECT u.id, u.name, u.is_moderator, b.id AS ban_id, b.reason AS ban_reason, b.starts_at, b.ends_at ' . $from,
            $from, $where, $parameters, 'u.id DESC', $page, $perPage,
            static fn (array $row): array => [
                'id' => (int) $row['id'], 'name' => $row['name'],
                'isModerator' => in_array($row['is_moderator'], [true, 1, '1', 't'], true),
                'activeBan' => $row['ban_id'] === null ? null : [
                    'id' => (int) $row['ban_id'], 'reason' => $row['ban_reason'],
                    'startsAt' => $row['starts_at'], 'endsAt' => $row['ends_at'],
                ],
            ],
        );
    }

    public function actionsPageForModerator(int $actor, int $page = 1, array $filters = [], int $perPage = 20): ?array
    {
        if (!$this->canModerate($actor)) {
            return null;
        }
        $where = [];
        $parameters = [];
        $this->textFilter($where, $parameters, 'author', $filters['author'] ?? '', 'm.user_name', 'm.user_id');
        $anime = is_string($filters['anime'] ?? null) ? trim($filters['anime']) : '';
        if ($anime !== '') {
            $where[] = "m.anime_title ILIKE :anime ESCAPE '!'";
            $parameters['anime'] = $this->searchPattern($anime);
        }
        return $this->page(
            'SELECT m.* FROM moderation_actions m', 'FROM moderation_actions m', $where, $parameters,
            'm.created_at DESC, m.id DESC', $page, $perPage, $this->mapAction(...),
        );
    }

    public function removeComment(int $actor, int $commentId, string $reason, string $key, ?int $reportId = null): bool
    {
        $comment = $this->comment($commentId);
        if ($comment === null) {
            return false;
        }
        return $this->decision($actor, (int) $comment['user_id'], $reason, $key, function (array $user, string $reason) use ($actor, $commentId, $key, $reportId): bool {
            $comment = $this->comment($commentId, true);
            if ($comment === null || $comment['moderated_at'] !== null || $comment['deleted_at'] !== null) {
                return false;
            }
            $reports = $this->connection->prepare("SELECT id, status FROM community_reports WHERE target_type = 'comment' AND target_id = :id ORDER BY id FOR UPDATE");
            $reports->execute(['id' => $commentId]);
            $rows = $reports->fetchAll();
            if ($reportId !== null && !array_filter($rows, static fn (array $row): bool => (int) $row['id'] === $reportId && $row['status'] === 'open')) {
                return false;
            }
            $update = $this->connection->prepare('UPDATE anime_comments SET moderated_at = clock_timestamp() WHERE id = :id');
            $update->execute(['id' => $commentId]);
            $resolve = $this->connection->prepare("UPDATE community_reports SET status = 'removed', resolved_by = :actor, resolved_at = clock_timestamp() WHERE target_type = 'comment' AND target_id = :id AND status = 'open'");
            $resolve->execute(['actor' => $actor, 'id' => $commentId]);
            $this->record($actor, $user, 'comment_removed', $reason, $key, $comment, $reportId);
            return true;
        });
    }

    public function warnUser(int $actor, int $userId, string $reason, string $key, ?int $commentId = null): bool
    {
        return $this->decision($actor, $userId, $reason, $key, function (array $user, string $reason) use ($actor, $key, $commentId, $userId): bool {
            $comment = $commentId === null ? null : $this->comment($commentId, true);
            if ($commentId !== null && ($comment === null || (int) $comment['user_id'] !== $userId)) {
                return false;
            }
            $this->record($actor, $user, 'warning', $reason, $key, $comment);
            return true;
        });
    }

    public function banUser(int $actor, int $userId, string $reason, int $durationSeconds, string $key): bool
    {
        // Reject moderator targets before waiting for an exclusive request lock. This also
        // prevents two invalid moderator-to-moderator attempts from forming a lock cycle.
        if ($userId <= 0 || !in_array($durationSeconds, self::BAN_DURATIONS, true) || $actor === $userId
            || !$this->canModerate($actor)) {
            return false;
        }
        $lookup = $this->connection->prepare('SELECT is_moderator FROM users WHERE id = :id');
        $lookup->execute(['id' => $userId]);
        $target = $lookup->fetch();
        if ($target === false || in_array($target['is_moderator'], [true, 1, '1', 't'], true)) {
            return false;
        }
        return $this->exclusiveUserMutation($userId, fn (): bool => $this->decision($actor, $userId, $reason, $key, function (array $user, string $reason) use ($actor, $userId, $durationSeconds, $key): bool {
            if (in_array($user['is_moderator'], [true, 1, '1', 't'], true) || $this->activeBanForUser($userId) !== null) {
                return false;
            }
            // One database timestamp determines both ends, independent of the PHP host clock.
            $insert = $this->connection->prepare('INSERT INTO user_bans (user_id, moderator_id, reason, starts_at, ends_at)
                SELECT :user, :actor, :reason, now, now + make_interval(secs => :seconds)
                FROM (SELECT clock_timestamp() AS now) timing RETURNING id, ends_at');
            $insert->execute(['user' => $userId, 'actor' => $actor, 'reason' => $reason, 'seconds' => $durationSeconds]);
            $ban = $insert->fetch();
            $this->record($actor, $user, 'ban', $reason, $key, banId: (int) $ban['id'], endsAt: $ban['ends_at']);
            return true;
        }));
    }

    public function revokeBan(int $actor, int $banId, string $reason, string $key): bool
    {
        $lookup = $this->connection->prepare('SELECT user_id FROM user_bans WHERE id = :id');
        $lookup->execute(['id' => $banId]);
        $userId = $lookup->fetchColumn();
        if ($userId === false) {
            return false;
        }
        return $this->exclusiveUserMutation((int) $userId, fn (): bool => $this->decision($actor, (int) $userId, $reason, $key, function (array $user, string $reason) use ($actor, $banId, $key): bool {
            $update = $this->connection->prepare('UPDATE user_bans SET revoked_at = clock_timestamp(), revoked_by = :actor, revoke_reason = :reason
                WHERE id = :id AND revoked_at IS NULL AND starts_at <= clock_timestamp() AND ends_at > clock_timestamp() RETURNING ends_at');
            $update->execute(['actor' => $actor, 'reason' => $reason, 'id' => $banId]);
            $endsAt = $update->fetchColumn();
            if ($endsAt === false) {
                return false;
            }
            $this->record($actor, $user, 'ban_revoked', $reason, $key, banId: $banId, endsAt: $endsAt);
            return true;
        }));
    }

    public function resolveReportForModerator(int $actor, int $reportId, string $action, string $reason, string $key): bool
    {
        if ($action === 'dismiss') {
            return $this->dismissReport($actor, $reportId, $reason, $key);
        }
        if ($action !== 'remove' || !$this->canModerate($actor)) {
            return false;
        }
        $lookup = $this->connection->prepare("SELECT target_id FROM community_reports WHERE id = :id AND target_type = 'comment' AND status = 'open'");
        $lookup->execute(['id' => $reportId]);
        $commentId = $lookup->fetchColumn();
        return $commentId !== false && $this->removeComment($actor, (int) $commentId, $reason, $key, $reportId);
    }

    public function dismissReport(int $actor, int $reportId, string $reason, string $key): bool
    {
        $lookup = $this->connection->prepare("SELECT r.target_id AS id, COALESCE(c.user_id, r.reported_user_id) AS user_id
            FROM community_reports r LEFT JOIN anime_comments c ON c.id = r.target_id
            WHERE r.id = :id AND r.target_type = 'comment'");
        $lookup->execute(['id' => $reportId]);
        $target = $lookup->fetch();
        if ($target === false || $target['user_id'] === null) {
            return false;
        }
        return $this->decision($actor, (int) $target['user_id'], $reason, $key, function (array $user, string $reason) use ($actor, $reportId, $key, $target): bool {
            $comment = $this->comment((int) $target['id'], true);
            $update = $this->connection->prepare("UPDATE community_reports SET status = 'dismissed', resolved_by = :actor, resolved_at = clock_timestamp() WHERE id = :id AND target_type = 'comment' AND status = 'open'");
            $update->execute(['actor' => $actor, 'id' => $reportId]);
            if ($update->rowCount() !== 1) {
                return false;
            }
            $this->record($actor, $user, 'report_dismissed', $reason, $key, $comment, $reportId, notify: false);
            return true;
        });
    }

    public function noticesPageForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $from = 'FROM moderation_notices n JOIN moderation_actions m ON m.id = n.action_id';
        return $this->page(
            'SELECT n.id AS notice_id, n.created_at AS notice_created_at, n.read_at, m.* ' . $from,
            $from, ['n.user_id = :user'], ['user' => $userId], 'n.created_at DESC, n.id DESC', $page, $perPage,
            $this->mapNotice(...),
        );
    }

    public function unreadNoticesForUser(int $userId, int $limit = 5): array
    {
        $statement = $this->connection->prepare('SELECT n.id AS notice_id, n.created_at AS notice_created_at, n.read_at, m.*
            FROM moderation_notices n JOIN moderation_actions m ON m.id = n.action_id
            WHERE n.user_id = :user AND n.read_at IS NULL ORDER BY n.created_at DESC, n.id DESC LIMIT :limit');
        $statement->bindValue('user', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return array_map($this->mapNotice(...), $statement->fetchAll());
    }

    public function acknowledgeNoticeForUser(int $userId, int $noticeId): bool
    {
        $statement = $this->connection->prepare('UPDATE moderation_notices SET read_at = COALESCE(read_at, clock_timestamp()) WHERE id = :id AND user_id = :user RETURNING id');
        $statement->execute(['id' => $noticeId, 'user' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    private function canModerate(int $actor): bool
    {
        return $this->isModerator($actor) && $this->activeBanForUser($actor) === null;
    }

    private function exclusiveUserMutation(int $userId, Closure $callback): bool
    {
        // Every authenticated HTTP request holds the shared counterpart on the same
        // connection until its transaction commits. A ban therefore waits for older
        // writes, and all following requests see the committed ban before writing.
        return (new CommunityRepository($this->connection))->withUsersLocked([], function () use ($userId, $callback): bool {
            // The shared HTTP guard reserves the same negative bigint account key.
            $lock = $this->connection->prepare('SELECT pg_advisory_xact_lock(-CAST(:user AS bigint))');
            $lock->execute(['user' => $userId]);
            $lock->fetchColumn();
            return $callback();
        });
    }

    private function decision(int $actor, int $userId, string $reason, string $key, Closure $callback): bool
    {
        $reason = CommunityService::normalizeReason($reason);
        if ($actor <= 0 || $userId <= 0 || CommunityService::validateReason($reason) !== [] || preg_match('/\A[a-f0-9]{32,64}\z/', $key) !== 1) {
            return false;
        }
        return (new CommunityRepository($this->connection))->withUsersLocked([$actor, $userId], function () use ($actor, $userId, $reason, $key, $callback): bool {
            if (!$this->canModerate($actor)) {
                return false;
            }
            $existing = $this->connection->prepare('SELECT id FROM moderation_actions WHERE moderator_id = :actor AND request_key = :key');
            $existing->execute(['actor' => $actor, 'key' => $key]);
            if ($existing->fetchColumn() !== false) {
                return false;
            }
            $lookup = $this->connection->prepare('SELECT id, name, is_moderator FROM users WHERE id = :id');
            $lookup->execute(['id' => $userId]);
            $user = $lookup->fetch();
            return $user !== false && $callback($user, $reason);
        });
    }

    private function record(int $actor, array $user, string $type, string $reason, string $key, ?array $comment = null, ?int $reportId = null, ?int $banId = null, ?string $endsAt = null, bool $notify = true): void
    {
        $statement = $this->connection->prepare('INSERT INTO moderation_actions
            (moderator_id, moderator_name, user_id, user_name, action_type, comment_id, report_id, ban_id, anime_title, reason, ends_at, request_key)
            SELECT :actor, name, :user, :user_name, :type, :comment, :report, :ban, :anime, :reason, :ends, :key FROM users WHERE id = :moderator RETURNING id');
        $statement->execute([
            'actor' => $actor, 'moderator' => $actor, 'user' => (int) $user['id'], 'user_name' => $user['name'],
            'type' => $type, 'comment' => $comment === null ? null : (int) $comment['id'], 'report' => $reportId,
            'ban' => $banId, 'anime' => $comment['anime_title'] ?? null, 'reason' => $reason, 'ends' => $endsAt, 'key' => $key,
        ]);
        $actionId = (int) $statement->fetchColumn();
        if ($notify) {
            $notice = $this->connection->prepare('INSERT INTO moderation_notices (user_id, action_id) VALUES (:user, :action)');
            $notice->execute(['user' => (int) $user['id'], 'action' => $actionId]);
        }
    }

    private function comment(int $commentId, bool $lock = false): ?array
    {
        $statement = $this->connection->prepare('SELECT c.*, a.title AS anime_title FROM anime_comments c JOIN anime a ON a.id = c.anime_id WHERE c.id = :id' . ($lock ? ' FOR UPDATE OF c' : ''));
        $statement->execute(['id' => $commentId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function commentFilters(array $filters): array
    {
        $where = [];
        $parameters = [];
        $this->textFilter($where, $parameters, 'author', $filters['author'] ?? '', 'u.name', 'u.id');
        $this->textFilter($where, $parameters, 'anime', $filters['anime'] ?? '', 'a.title', 'a.id');
        return [$where, $parameters];
    }

    private function textFilter(array &$where, array &$parameters, string $key, mixed $value, string $textColumn, string $idColumn): void
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id !== false) {
            $where[] = $idColumn . ' = :' . $key;
            $parameters[$key] = $id;
        } else {
            $where[] = $textColumn . ' ILIKE :' . $key . " ESCAPE '!'";
            $parameters[$key] = $this->searchPattern($value);
        }
    }

    private function searchPattern(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
    }

    private function page(string $select, string $from, array $where, array $parameters, string $order, int $page, int $perPage, Closure $map): array
    {
        $condition = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->connection->prepare('SELECT count(*) ' . $from . $condition);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $statement = $this->connection->prepare($select . $condition . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset');
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        return ['entries' => array_map($map, $statement->fetchAll()), 'page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => $totalPages];
    }

    private function commentStatus(array $row): string
    {
        return $row['moderated_at'] !== null ? 'removed' : ($row['deleted_at'] !== null ? 'deleted' : 'active');
    }

    private function mapBan(array $row): array
    {
        return ['id' => (int) $row['id'], 'userId' => (int) $row['user_id'],
            'moderatorId' => $row['moderator_id'] === null ? null : (int) $row['moderator_id'],
            'reason' => $row['reason'], 'startsAt' => $row['starts_at'], 'endsAt' => $row['ends_at']];
    }

    private function mapAction(array $row): array
    {
        return ['id' => (int) $row['id'], 'actionType' => $row['action_type'],
            'moderatorId' => $row['moderator_id'] === null ? null : (int) $row['moderator_id'], 'moderatorName' => $row['moderator_name'],
            'userId' => $row['user_id'] === null ? null : (int) $row['user_id'], 'userName' => $row['user_name'],
            'commentId' => $row['comment_id'] === null ? null : (int) $row['comment_id'], 'animeTitle' => $row['anime_title'],
            'reason' => $row['reason'], 'createdAt' => $row['created_at'], 'endsAt' => $row['ends_at']];
    }

    private function mapNotice(array $row): array
    {
        return ['id' => (int) $row['notice_id'], 'actionType' => $row['action_type'], 'reason' => $row['reason'],
            'animeTitle' => $row['anime_title'], 'createdAt' => $row['notice_created_at'], 'readAt' => $row['read_at'],
            'endsAt' => $row['ends_at'], 'commentId' => $row['comment_id'] === null ? null : (int) $row['comment_id']];
    }
}
