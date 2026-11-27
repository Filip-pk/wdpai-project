<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Profile photos saved in users.avatar_data by versions before avatar files (migration 34).
 * Migration 35 drops that column once every photo has a file; after that there is nothing to read.
 */
final class LegacyAvatarRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function hasLegacyColumn(): bool
    {
        // 'users'::regclass resolves the table the same way the queries below do.
        return DatabaseValue::toBool($this->database->query("SELECT EXISTS (SELECT 1 FROM pg_attribute
            WHERE attrelid = 'users'::regclass AND attname = 'avatar_data' AND NOT attisdropped)")->fetchColumn());
    }

    /**
     * Photos that have no file yet, in id order after $afterId.
     *
     * @return list<array{id: int, version: ?string, contents: string}>
     */
    public function withoutFile(int $afterId, int $limit): array
    {
        $statement = $this->database->prepare('SELECT id, avatar_version, avatar_data FROM users
            WHERE avatar_data IS NOT NULL AND avatar_path IS NULL AND id > :after ORDER BY id LIMIT :limit');
        $statement->bindValue('after', $afterId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contents = is_resource($row['avatar_data']) ? stream_get_contents($row['avatar_data']) : $row['avatar_data'];
            $rows[] = ['id' => (int) $row['id'], 'version' => $row['avatar_version'],
                'contents' => is_string($contents) ? $contents : ''];
        }

        return $rows;
    }

    /** Sets the file only if the account still shows the same photo it had when it was read. */
    public function assignPath(int $userId, string $path, ?string $version): bool
    {
        $statement = $this->database->prepare('UPDATE users SET avatar_path = :path
            WHERE id = :id AND avatar_path IS NULL AND avatar_data IS NOT NULL AND avatar_version IS NOT DISTINCT FROM :version');
        $statement->execute(['path' => $path, 'id' => $userId, 'version' => $version]);

        return $statement->rowCount() === 1;
    }
}
