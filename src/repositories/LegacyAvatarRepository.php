<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** Profile photos saved in users.avatar_data by versions before avatar files (migration 34). */
final class LegacyAvatarRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * Photos that have no file yet, in id order after $afterId.
     *
     * @return list<array{id: int, version: ?string, contents: string}>
     */
    public function withoutFile(int $afterId, int $limit): array
    {
        return $this->batch('avatar_path IS NULL', $afterId, $limit);
    }

    /**
     * Photos that already have a file but still keep their database copy.
     *
     * @return list<array{id: int, version: ?string, path: string, contents: string}>
     */
    public function withFile(int $afterId, int $limit): array
    {
        return $this->batch('avatar_path IS NOT NULL', $afterId, $limit);
    }

    /** Sets the file only if the account still shows the same photo it had when it was read. */
    public function assignPath(int $userId, string $path, ?string $version): bool
    {
        $statement = $this->database->prepare('UPDATE users SET avatar_path = :path
            WHERE id = :id AND avatar_path IS NULL AND avatar_data IS NOT NULL AND avatar_version IS NOT DISTINCT FROM :version');
        $statement->execute(['path' => $path, 'id' => $userId, 'version' => $version]);

        return $statement->rowCount() === 1;
    }

    public function clearDatabaseCopy(int $userId, string $path): bool
    {
        $statement = $this->database->prepare('UPDATE users SET avatar_data = NULL WHERE id = :id AND avatar_path = :path');
        $statement->execute(['id' => $userId, 'path' => $path]);

        return $statement->rowCount() === 1;
    }

    private function batch(string $condition, int $afterId, int $limit): array
    {
        $statement = $this->database->prepare('SELECT id, avatar_version, avatar_path, avatar_data FROM users
            WHERE avatar_data IS NOT NULL AND ' . $condition . ' AND id > :after ORDER BY id LIMIT :limit');
        $statement->bindValue('after', $afterId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $contents = is_resource($row['avatar_data']) ? stream_get_contents($row['avatar_data']) : $row['avatar_data'];
            $rows[] = ['id' => (int) $row['id'], 'version' => $row['avatar_version'], 'path' => $row['avatar_path'],
                'contents' => is_string($contents) ? $contents : ''];
        }

        return $rows;
    }
}
