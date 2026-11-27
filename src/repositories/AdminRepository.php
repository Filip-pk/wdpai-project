<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\CommunityService;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class AdminRepository
{
    private const PAGE_SIZE = 20;
    private const DETAIL_KEYS = ['reason', 'service', 'result', 'errorId', 'source', 'durationMs', 'reportId'];

    public function __construct(private readonly PDO $database)
    {
    }

    public function isAdmin(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $statement = $this->database->prepare('SELECT u.is_admin AND NOT EXISTS (
            SELECT 1 FROM user_bans b WHERE b.user_id = u.id AND b.revoked_at IS NULL
                AND b.starts_at <= clock_timestamp() AND b.ends_at > clock_timestamp()
        ) FROM users u WHERE u.id = :id');
        $statement->execute(['id' => $userId]);

        return $this->boolean($statement->fetchColumn());
    }

    public function overview(): array
    {
        $row = $this->database->query("SELECT (SELECT COUNT(*) FROM users) AS users,
            (SELECT COUNT(*) FROM anime) AS anime,
            (SELECT COUNT(*) FROM community_reports WHERE target_type = 'comment' AND status = 'open') AS open_reports,
            (SELECT COUNT(*) FROM community_reports WHERE target_type = 'message' AND status = 'open') AS open_message_reports")
            ->fetch(PDO::FETCH_ASSOC);

        return ['users' => (int) $row['users'], 'anime' => (int) $row['anime'], 'openCommentReports' => (int) $row['open_reports'],
            'openMessageReports' => (int) $row['open_message_reports']];
    }

    /**
     * Open reports of private messages, oldest first. Only the copy saved with the report is
     * returned: the conversation and the sender's other messages stay private.
     */
    public function messageReports(int $page = 1): array
    {
        $total = (int) $this->database->query("SELECT COUNT(*) FROM community_reports
            WHERE target_type = 'message' AND status = 'open'")->fetchColumn();
        $pagination = $this->pagination($total, $page);
        $statement = $this->database->prepare("SELECT r.id, r.snapshot, r.reason, r.created_at,
                reporter.name AS reporter_name, reported.id AS reported_id, reported.name AS reported_name,
                COALESCE(reported.is_admin, false) AS reported_is_admin,
                m.id IS NOT NULL AND m.moderated_at IS NULL AS message_visible
            FROM community_reports r
            JOIN users reporter ON reporter.id = r.reporter_id
            LEFT JOIN users reported ON reported.id = r.reported_user_id
            LEFT JOIN private_messages m ON m.id = r.target_id AND m.sender_id = r.reported_user_id
            WHERE r.target_type = 'message' AND r.status = 'open'
            ORDER BY r.created_at ASC, r.id ASC LIMIT :limit OFFSET :offset");
        $this->executePage($statement, [], $pagination);

        return ['items' => array_map(fn (array $row): array => [
            'id' => (int) $row['id'], 'snapshot' => $row['snapshot'], 'reason' => $row['reason'],
            'createdAt' => $row['created_at'], 'reporterName' => $row['reporter_name'],
            'reportedId' => $row['reported_id'] === null ? null : (int) $row['reported_id'],
            'reportedName' => $row['reported_name'],
            'reportedIsAdmin' => $this->boolean($row['reported_is_admin']),
            'canHide' => !$this->boolean($row['reported_is_admin']) && $this->boolean($row['message_visible']),
        ], $statement->fetchAll(PDO::FETCH_ASSOC)), 'pagination' => $pagination];
    }

    /** Closes a message report without touching the message; the report stays in the history. */
    public function dismissMessageReport(int $actorId, int $reportId, string $reason): bool
    {
        return $this->decideMessageReport($actorId, $reportId, $reason, false);
    }

    /** Hides the reported message for both participants and closes its open reports. */
    public function hideReportedMessage(int $actorId, int $reportId, string $reason): bool
    {
        return $this->decideMessageReport($actorId, $reportId, $reason, true);
    }

    public function users(string $query = '', int $page = 1): array
    {
        $query = $this->searchTerm($query);
        $where = $query === '' ? '' : "WHERE (u.name ILIKE :name ESCAPE '!' OR u.email ILIKE :email ESCAPE '!' OR CAST(u.id AS text) = :id)";
        $parameters = $query === '' ? [] : ['name' => $this->pattern($query), 'email' => $this->pattern($query), 'id' => $query];
        $count = $this->database->prepare('SELECT COUNT(*) FROM users u ' . $where);
        $count->execute($parameters);
        $pagination = $this->pagination((int) $count->fetchColumn(), $page);
        $statement = $this->database->prepare('SELECT u.id, u.name, u.email, u.is_admin, u.is_moderator,
            (SELECT MAX(b.ends_at) FROM user_bans b WHERE b.user_id = u.id AND b.revoked_at IS NULL
                AND b.starts_at <= clock_timestamp() AND b.ends_at > clock_timestamp()) AS ban_ends_at
            FROM users u ' . $where . ' ORDER BY u.id DESC LIMIT :limit OFFSET :offset');
        $this->executePage($statement, $parameters, $pagination);

        return ['items' => array_map(fn (array $row): array => [
            'id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'],
            'isAdmin' => $this->boolean($row['is_admin']), 'isModerator' => $this->boolean($row['is_moderator']),
            'banEndsAt' => $row['ban_ends_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)), 'pagination' => $pagination];
    }

    public function changeModerator(int $actorId, int $targetId, bool $enabled, string $reason): bool
    {
        if ($actorId <= 0 || $targetId <= 0 || $actorId === $targetId) {
            return false;
        }
        $reason = CommunityService::normalizeReason($reason);
        $this->validateReason($reason);
        // Reject protected accounts before requesting a lock held by their HTTP session.
        // The same properties are checked again after all locks have been acquired.
        $target = $this->database->prepare('SELECT is_admin FROM users WHERE id = :id');
        $target->execute(['id' => $targetId]);
        $row = $target->fetch(PDO::FETCH_ASSOC);
        if (!$this->isAdmin($actorId) || $row === false || $this->boolean($row['is_admin'])) {
            return false;
        }

        return Transaction::run($this->database, function () use ($actorId, $targetId, $enabled, $reason): bool {
            $this->accountLock($actorId, true);
            $this->accountLock($targetId, false);
            $users = $this->lockUsers([$actorId, $targetId]);
            $target = $users[$targetId] ?? null;
            if (!$this->isAdmin($actorId) || $target === null || $this->boolean($target['is_admin'])
                || $this->hasActiveBan($targetId) || $this->boolean($target['is_moderator']) === $enabled) {
                return false;
            }
            $statement = $this->database->prepare('UPDATE users SET is_moderator = :enabled WHERE id = :id');
            $statement->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
            $statement->bindValue(':id', $targetId, PDO::PARAM_INT);
            $statement->execute();
            $this->insertAudit($actorId, $users[$actorId]['name'], $enabled ? 'moderator_granted' : 'moderator_revoked',
                $targetId, $target['name'], ['reason' => $reason]);

            return true;
        });
    }

    /** CLI-only role change; the first administrator must be granted outside HTTP. */
    public function setAdmin(int $userId, bool $enabled): bool
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Rolę administratora można zmienić wyłącznie przez CLI.');
        }
        if ($userId <= 0) {
            return false;
        }

        return Transaction::run($this->database, function () use ($userId, $enabled): bool {
            // Serialize grants/revocations so two revocations cannot remove the last two administrators.
            $this->database->query('SELECT pg_advisory_xact_lock(746562, 29)')->fetchColumn();
            $this->accountLock($userId, false);
            $users = $this->lockUsers([$userId]);
            $user = $users[$userId] ?? null;
            if ($user === null || ($enabled && $this->hasActiveBan($userId))) {
                return false;
            }
            if ($this->boolean($user['is_admin']) === $enabled) {
                return true;
            }
            if (!$enabled) {
                $remaining = $this->database->prepare('SELECT COUNT(*) FROM users u WHERE u.is_admin AND u.id <> :id
                    AND NOT EXISTS (SELECT 1 FROM user_bans b WHERE b.user_id = u.id AND b.revoked_at IS NULL
                        AND b.starts_at <= clock_timestamp() AND b.ends_at > clock_timestamp())');
                $remaining->execute(['id' => $userId]);
                if ((int) $remaining->fetchColumn() === 0) {
                    throw new RuntimeException('Nie można odebrać roli ostatniemu aktywnemu administratorowi.');
                }
            }
            $statement = $this->database->prepare('UPDATE users SET is_admin = :enabled WHERE id = :id');
            $statement->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
            $statement->bindValue(':id', $userId, PDO::PARAM_INT);
            $statement->execute();
            $this->insertAudit(null, 'CLI', $enabled ? 'admin_granted' : 'admin_revoked', $userId, $user['name'], ['source' => 'cli']);

            return true;
        });
    }

    public function audit(int $actorId, string $action, ?int $targetId = null, array $details = []): void
    {
        $this->validateAudit($action, $details);
        if ($actorId <= 0 || ($targetId !== null && $targetId <= 0)) {
            throw new InvalidArgumentException('Nieprawidłowy użytkownik historii administracji.');
        }
        Transaction::run($this->database, function () use ($actorId, $action, $targetId, $details): void {
            $this->accountLock($actorId, true);
            $users = $this->lockUsers($targetId === null ? [$actorId] : [$actorId, $targetId]);
            if (!$this->isAdmin($actorId)) {
                throw new RuntimeException('Brak uprawnień administratora.');
            }
            if ($targetId !== null && !isset($users[$targetId])) {
                throw new InvalidArgumentException('Nie znaleziono konta w historii administracji.');
            }
            $this->insertAudit($actorId, $users[$actorId]['name'], $action, $targetId, $users[$targetId]['name'] ?? null, $details);
        });
    }

    public function history(int $page = 1): array
    {
        $pagination = $this->pagination((int) $this->database->query('SELECT COUNT(*) FROM admin_actions')->fetchColumn(), $page);
        $statement = $this->database->prepare('SELECT id, actor_id, actor_name, target_id, target_name, action, details, created_at
            FROM admin_actions ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset');
        $this->executePage($statement, [], $pagination);

        return ['items' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'actorId' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'actorName' => $row['actor_name'], 'targetId' => $row['target_id'] === null ? null : (int) $row['target_id'],
            'targetName' => $row['target_name'], 'action' => $row['action'],
            'details' => json_decode($row['details'], true, 16, JSON_THROW_ON_ERROR), 'createdAt' => $row['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)), 'pagination' => $pagination];
    }

    public function catalog(string $query = '', string $missing = '', int $page = 1): array
    {
        $query = $this->searchTerm($query);
        $missing = $missing === 'all' ? '' : $missing;
        if (!in_array($missing, ['', 'description', 'image'], true)) {
            throw new InvalidArgumentException('Nieprawidłowy filtr braków w katalogu.');
        }
        $conditions = [];
        $parameters = [];
        if ($query !== '') {
            $conditions[] = "(title ILIKE :query ESCAPE '!' OR CAST(id AS text) = :id)";
            $parameters = ['query' => $this->pattern($query), 'id' => $query];
        }
        if ($missing === 'description') {
            $conditions[] = "btrim(description) = ''";
        } elseif ($missing === 'image') {
            $conditions[] = "cover_url IS NULL OR btrim(cover_url) = ''";
        }
        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', array_map(static fn (string $condition): string => '(' . $condition . ')', $conditions));
        $summary = $this->database->query("SELECT COUNT(*) AS total,
            COUNT(*) FILTER (WHERE btrim(description) = '') AS missing_description,
            COUNT(*) FILTER (WHERE cover_url IS NULL OR btrim(cover_url) = '') AS missing_image,
            MAX(source_updated_at) AS last_updated_at FROM anime")->fetch(PDO::FETCH_ASSOC);
        $count = $this->database->prepare('SELECT COUNT(*) FROM anime ' . $where);
        $count->execute($parameters);
        $pagination = $this->pagination((int) $count->fetchColumn(), $page);
        $statement = $this->database->prepare("SELECT id, title, btrim(description) <> '' AS has_description,
            cover_url IS NOT NULL AND btrim(cover_url) <> '' AS has_image, source_updated_at
            FROM anime " . $where . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $this->executePage($statement, $parameters, $pagination);

        return ['items' => array_map(fn (array $row): array => [
            'id' => (int) $row['id'], 'title' => $row['title'], 'hasDescription' => $this->boolean($row['has_description']),
            'hasImage' => $this->boolean($row['has_image']), 'sourceUpdatedAt' => $row['source_updated_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC)), 'pagination' => $pagination, 'summary' => [
            'total' => (int) $summary['total'], 'missingDescription' => (int) $summary['missing_description'],
            'missingImage' => (int) $summary['missing_image'], 'lastUpdatedAt' => $summary['last_updated_at'],
        ]];
    }

    private function decideMessageReport(int $actorId, int $reportId, string $reason, bool $hide): bool
    {
        if ($actorId <= 0 || $reportId <= 0) {
            return false;
        }
        $reason = CommunityService::normalizeReason($reason);
        $this->validateReason($reason);
        $lookup = $this->database->prepare("SELECT reported_user_id FROM community_reports
            WHERE id = :id AND target_type = 'message' AND status = 'open'");
        $lookup->execute(['id' => $reportId]);
        $reported = $lookup->fetchColumn();
        if ($reported === false || !$this->isAdmin($actorId)) {
            return false;
        }
        $reportedId = $reported === null ? null : (int) $reported;

        return Transaction::run($this->database, function () use ($actorId, $reportId, $reason, $hide, $reportedId): bool {
            $this->accountLock($actorId, true);
            // Lock both accounts first, as report creation does, then re-check every condition.
            $users = $this->lockUsers($reportedId === null ? [$actorId] : [$actorId, $reportedId]);
            if (!$this->isAdmin($actorId)) {
                return false;
            }
            $report = $this->database->prepare("SELECT target_id, reported_user_id, status FROM community_reports
                WHERE id = :id AND target_type = 'message' FOR UPDATE");
            $report->execute(['id' => $reportId]);
            $row = $report->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['status'] !== 'open'
                || ($row['reported_user_id'] === null ? null : (int) $row['reported_user_id']) !== $reportedId) {
                return false;
            }
            $sender = $reportedId === null ? null : ($users[$reportedId] ?? null);
            if ($hide) {
                // An administrator's messages are never moderated; such an old report can only be dismissed.
                if ($sender === null || $this->boolean($sender['is_admin'])) {
                    return false;
                }
                $message = $this->database->prepare('UPDATE private_messages SET moderated_at = clock_timestamp()
                    WHERE id = :id AND sender_id = :sender AND moderated_at IS NULL');
                $message->execute(['id' => (int) $row['target_id'], 'sender' => $reportedId]);
                if ($message->rowCount() !== 1) {
                    return false;
                }
                $close = $this->database->prepare("UPDATE community_reports SET status = 'removed', resolved_by = :actor,
                    resolved_at = clock_timestamp() WHERE target_type = 'message' AND target_id = :target AND status = 'open'");
                $close->execute(['actor' => $actorId, 'target' => (int) $row['target_id']]);
            } else {
                $close = $this->database->prepare("UPDATE community_reports SET status = 'dismissed', resolved_by = :actor,
                    resolved_at = clock_timestamp() WHERE id = :id");
                $close->execute(['actor' => $actorId, 'id' => $reportId]);
            }
            $this->insertAudit($actorId, $users[$actorId]['name'], $hide ? 'message_hidden' : 'message_report_dismissed',
                $sender === null ? null : $reportedId, $sender['name'] ?? null, ['reason' => $reason, 'reportId' => $reportId]);

            return true;
        });
    }

    private function insertAudit(?int $actorId, string $actorName, string $action, ?int $targetId, ?string $targetName, array $details): void
    {
        $this->validateAudit($action, $details);
        $statement = $this->database->prepare('INSERT INTO admin_actions (actor_id, actor_name, target_id, target_name, action, details)
            VALUES (:actor, :actorName, :target, :targetName, :action, CAST(:details AS jsonb))');
        $statement->execute(['actor' => $actorId, 'actorName' => $actorName, 'target' => $targetId,
            'targetName' => $targetName, 'action' => $action, 'details' => json_encode((object) $details, JSON_THROW_ON_ERROR)]);
    }

    private function validateAudit(string $action, array $details): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $action) !== 1) {
            throw new InvalidArgumentException('Nieprawidłowy typ operacji administracyjnej.');
        }
        foreach ($details as $key => $value) {
            if (!in_array($key, self::DETAIL_KEYS, true) || !is_scalar($value) || is_float($value)
                || (is_string($value) && (preg_match('//u', $value) !== 1 || strlen($value) > 4000
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1))) {
                throw new InvalidArgumentException('Nieprawidłowe szczegóły operacji administracyjnej.');
            }
        }
        if (strlen(json_encode((object) $details, JSON_THROW_ON_ERROR)) > 8192) {
            throw new InvalidArgumentException('Zbyt długie szczegóły operacji administracyjnej.');
        }
    }

    private function validateReason(string $reason): void
    {
        if (CommunityService::validateReason($reason) !== []) {
            throw new InvalidArgumentException('Powód musi zawierać od 1 do 1000 znaków bez niedozwolonych znaków sterujących.');
        }
    }

    private function hasActiveBan(int $userId): bool
    {
        $statement = $this->database->prepare('SELECT EXISTS (SELECT 1 FROM user_bans WHERE user_id = :id
            AND revoked_at IS NULL AND starts_at <= clock_timestamp() AND ends_at > clock_timestamp())');
        $statement->execute(['id' => $userId]);

        return $this->boolean($statement->fetchColumn());
    }

    private function accountLock(int $userId, bool $shared): void
    {
        $statement = $this->database->prepare('SELECT pg_advisory_xact_lock' . ($shared ? '_shared' : '') . '(-CAST(:id AS bigint))');
        $statement->execute(['id' => $userId]);
        $statement->fetchColumn();
    }

    private function lockUsers(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $users = [];
        $statement = $this->database->prepare('SELECT id, name, is_admin, is_moderator FROM users WHERE id = :id FOR UPDATE');
        foreach ($ids as $id) {
            $statement->execute(['id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                $users[(int) $row['id']] = $row;
            }
        }

        return $users;
    }

    private function executePage(\PDOStatement $statement, array $parameters, array $pagination): void
    {
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $pagination['perPage'], PDO::PARAM_INT);
        $statement->bindValue(':offset', ($pagination['page'] - 1) * $pagination['perPage'], PDO::PARAM_INT);
        $statement->execute();
    }

    private function pagination(int $total, int $page): array
    {
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));

        return ['page' => max(1, min($page, $totalPages)), 'perPage' => self::PAGE_SIZE,
            'total' => $total, 'totalPages' => $totalPages];
    }

    private function searchTerm(string $query): string
    {
        $query = trim($query);
        if (preg_match('//u', $query) !== 1 || mb_strlen($query, 'UTF-8') > 200 || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            throw new InvalidArgumentException('Nieprawidłowa fraza wyszukiwania.');
        }

        return $query;
    }

    private function pattern(string $query): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    }

    private function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
