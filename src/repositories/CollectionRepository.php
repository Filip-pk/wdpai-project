<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class CollectionRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function ensureDefaultForUser(int $userId): void
    {
        $statement = $this->database->prepare(
            "INSERT INTO anime_collections (user_id, name, is_default)
             VALUES (:user_id, 'moja kolekcja', TRUE)
             ON CONFLICT (user_id) WHERE is_default DO NOTHING"
        );
        $statement->execute(['user_id' => $userId]);
    }

    public function findForUser(int $userId): array
    {
        return $this->fetchForUser($userId);
    }

    /** @return array{collections: list<array>, page: int, totalPages: int, total: int} */
    public function findPageForUser(int $userId, int $page = 1, int $perPage = 12): array
    {
        $statement = $this->database->prepare('SELECT count(*) FROM anime_collections WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $total = (int) $statement->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));

        return [
            'collections' => $this->fetchForUser($userId, $perPage, ($page - 1) * $perPage),
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
        ];
    }

    private function fetchForUser(int $userId, ?int $limit = null, int $offset = 0): array
    {
        $statement = $this->database->prepare(
            "SELECT collections.id, collections.name, collections.description, collections.is_default,
                    (SELECT count(*) FROM anime_collection_items items
                     WHERE items.collection_id = collections.id) AS anime_count,
                    COALESCE((
                        SELECT jsonb_agg(preview ORDER BY preview.added_at DESC, preview.id DESC)
                        FROM (
                            SELECT anime.id, anime.title, anime.cover_url AS \"coverUrl\", items.added_at
                            FROM anime_collection_items items
                            JOIN anime ON anime.id = items.anime_id
                            WHERE items.collection_id = collections.id
                            ORDER BY items.added_at DESC, anime.id DESC LIMIT 4
                        ) preview
                    ), '[]'::jsonb) AS previews
             FROM anime_collections collections WHERE collections.user_id = :user_id
             ORDER BY collections.is_default DESC, collections.id"
            . ($limit === null ? '' : ' LIMIT :limit OFFSET :offset')
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        if ($limit !== null) {
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        }
        $statement->execute();

        return array_map($this->mapCollection(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByIdForUser(int $userId, int $collectionId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT collections.id, collections.name, collections.description, collections.is_default,
                    (SELECT count(*) FROM anime_collection_items items
                     WHERE items.collection_id = collections.id) AS anime_count
             FROM anime_collections collections WHERE collections.user_id = :user_id AND collections.id = :id'
        );
        $statement->execute(['user_id' => $userId, 'id' => $collectionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapCollection($row);
    }

    public function createForUser(int $userId, string $name, string $description): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO anime_collections (user_id, name, description)
             VALUES (:user_id, :name, :description) RETURNING id'
        );
        $statement->execute(['user_id' => $userId, 'name' => $name, 'description' => $description]);

        return (int) $statement->fetchColumn();
    }

    /** Returns zero without creating a collection when the anime does not exist. */
    public function createWithAnimeForUser(int $userId, string $name, string $description, int $animeId): int
    {
        $statement = $this->database->prepare(
            'WITH selected_anime AS (
                SELECT id FROM anime WHERE id = :anime_id
             ), created AS (
                INSERT INTO anime_collections (user_id, name, description)
                SELECT :user_id, :name, :description FROM selected_anime RETURNING id
             ), inserted AS (
                INSERT INTO anime_collection_items (collection_id, anime_id)
                SELECT created.id, selected_anime.id FROM created CROSS JOIN selected_anime
                RETURNING collection_id
             ) SELECT collection_id FROM inserted'
        );
        $statement->execute([
            'user_id' => $userId, 'name' => $name, 'description' => $description, 'anime_id' => $animeId,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function updateForUser(int $userId, int $collectionId, string $name, string $description): bool
    {
        $statement = $this->database->prepare(
            'UPDATE anime_collections SET name = :name, description = :description
             WHERE user_id = :user_id AND id = :id'
        );
        $statement->execute([
            'user_id' => $userId, 'id' => $collectionId, 'name' => $name, 'description' => $description,
        ]);

        return $statement->rowCount() === 1;
    }

    /** @return array{animeIds: list<int>, page: int, totalPages: int, total: int} */
    public function animeIdsForUser(int $userId, int $collectionId, int $page = 1, int $perPage = 24): array
    {
        $statement = $this->database->prepare(
            'SELECT count(*) FROM anime_collection_items items
             JOIN anime_collections collections ON collections.id = items.collection_id
             WHERE collections.user_id = :user_id AND collections.id = :id'
        );
        $statement->execute(['user_id' => $userId, 'id' => $collectionId]);
        $total = (int) $statement->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $statement = $this->database->prepare(
            'SELECT items.anime_id FROM anime_collection_items items
             JOIN anime_collections collections ON collections.id = items.collection_id
             WHERE collections.user_id = :user_id AND collections.id = :id
             ORDER BY items.added_at DESC, items.anime_id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':id', $collectionId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'animeIds' => array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)),
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
        ];
    }

    /** @return list<int> */
    public function collectionIdsForAnime(int $userId, int $animeId): array
    {
        $statement = $this->database->prepare(
            'SELECT collections.id FROM anime_collections collections
             JOIN anime_collection_items items ON items.collection_id = collections.id
             WHERE collections.user_id = :user_id AND items.anime_id = :anime_id
             ORDER BY collections.is_default DESC, collections.id'
        );
        $statement->execute(['user_id' => $userId, 'anime_id' => $animeId]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function addAnimeForUser(int $userId, int $collectionId, int $animeId): bool
    {
        return $this->addAnimeToManyForUser($userId, [$collectionId], $animeId);
    }

    /** Rejects the entire request if any collection or the anime does not exist for this account. */
    public function addAnimeToManyForUser(int $userId, array $collectionIds, int $animeId): bool
    {
        if ($collectionIds === [] || count($collectionIds) > 1000) {
            return false;
        }
        foreach ($collectionIds as $id) {
            if (!is_int($id) || $id <= 0) {
                return false;
            }
        }
        $collectionIds = array_values(array_unique($collectionIds));
        $parameters = ['user_id' => $userId, 'expected' => count($collectionIds), 'anime_id' => $animeId];
        $placeholders = [];
        foreach ($collectionIds as $index => $id) {
            $placeholders[] = ':collection_' . $index;
            $parameters['collection_' . $index] = $id;
        }
        $statement = $this->database->prepare(
            'WITH owned AS (
                SELECT id FROM anime_collections WHERE user_id = :user_id
                AND id IN (' . implode(', ', $placeholders) . ')
             ), valid_anime AS (
                SELECT id FROM anime WHERE id = :anime_id
                AND (SELECT count(*) FROM owned) = :expected
             ), inserted AS (
                INSERT INTO anime_collection_items (collection_id, anime_id)
                SELECT owned.id, valid_anime.id FROM owned CROSS JOIN valid_anime WHERE TRUE
                ON CONFLICT (collection_id, anime_id) DO NOTHING RETURNING collection_id
             ) SELECT EXISTS(SELECT 1 FROM valid_anime) AS valid'
        );
        $statement->execute($parameters);

        return in_array($statement->fetchColumn(), [true, 1, '1', 't'], true);
    }

    public function removeAnimeForUser(int $userId, int $collectionId, int $animeId): bool
    {
        $statement = $this->database->prepare(
            'DELETE FROM anime_collection_items items USING anime_collections collections
             WHERE collections.id = items.collection_id AND collections.user_id = :user_id
             AND collections.id = :id AND items.anime_id = :anime_id'
        );
        $statement->execute(['user_id' => $userId, 'id' => $collectionId, 'anime_id' => $animeId]);

        return $statement->rowCount() === 1;
    }

    private function mapCollection(array $row): array
    {
        $previews = json_decode($row['previews'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'isDefault' => in_array($row['is_default'], [true, 1, '1', 't'], true),
            'animeCount' => (int) $row['anime_count'],
            'previews' => array_map(static fn (array $anime): array => [
                'id' => (int) $anime['id'], 'title' => (string) $anime['title'],
                'coverUrl' => $anime['coverUrl'] === null ? null : (string) $anime['coverUrl'],
            ], $previews),
        ];
    }
}
