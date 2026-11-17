<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminRepository
{
    private const PAGE_SIZE = 20;
    private const DETAIL_KEYS = ['reason', 'service', 'result', 'errorId', 'source', 'durationMs'];

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
            (SELECT COUNT(*) FROM community_reports WHERE target_type = 'comment' AND status = 'open') AS open_reports")
            ->fetch(PDO::FETCH_ASSOC);

        return ['users' => (int) $row['users'], 'anime' => (int) $row['anime'], 'openCommentReports' => (int) $row['open_reports']];
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
        $this->validateReason($reason);
        $reason = trim($reason);
        // Reject protected accounts before requesting a lock held by their HTTP session.
        // The same properties are checked again after all locks have been acquired.
        $target = $this->database->prepare('SELECT is_admin FROM users WHERE id = :id');
        $target->execute(['id' => $targetId]);
        $row = $target->fetch(PDO::FETCH_ASSOC);
        if (!$this->isAdmin($actorId) || $row === false || $this->boolean($row['is_admin'])) {
            return false;
        }

        return $this->transaction(function () use ($actorId, $targetId, $enabled, $reason): bool {
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

        return $this->transaction(function () use ($userId, $enabled): bool {
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
        $this->transaction(function () use ($actorId, $action, $targetId, $details): void {
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
        if (trim($reason) === '' || preg_match('//u', $reason) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1
            || preg_match_all('/./us', $reason) > 1000) {
            throw new InvalidArgumentException('Powód musi zawierać od 1 do 1000 znaków bez znaków sterujących.');
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

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        $savepoint = 'admin_' . bin2hex(random_bytes(6));
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
