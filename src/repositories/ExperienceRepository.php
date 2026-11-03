<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class ExperienceRepository
{
    private const RANKING_QUERY = '
        WITH totals AS (
            SELECT user_id, SUM(watched::BIGINT * 5 + CASE WHEN completed THEN 100 ELSE 0 END) AS total_exp
            FROM user_anime_experience GROUP BY user_id
        ), ranked AS (
            SELECT users.id AS user_id, users.name, users.avatar_version,
                   COALESCE(totals.total_exp, 0) AS total_exp,
                   RANK() OVER (ORDER BY COALESCE(totals.total_exp, 0) DESC) AS user_rank,
                   ROW_NUMBER() OVER (ORDER BY COALESCE(totals.total_exp, 0) DESC, users.id ASC) AS listing_position
            FROM users LEFT JOIN totals ON totals.user_id = users.id
        )
        SELECT user_id, name, avatar_version, total_exp, user_rank, listing_position FROM ranked';

    public function __construct(private readonly PDO $database)
    {
    }

    public function totalForUser(int $userId): int
    {
        $statement = $this->database->prepare(
            'SELECT COALESCE(SUM(watched::BIGINT * 5 + CASE WHEN completed THEN 100 ELSE 0 END), 0)
             FROM user_anime_experience WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /** @return list<array{userId: int, name: string, avatarUrl: ?string, totalExp: int, rank: int}> */
    public function topUsers(): array
    {
        $statement = $this->database->query(self::RANKING_QUERY . ' ORDER BY total_exp DESC, user_id ASC LIMIT 100');

        return array_map($this->mapUser(...), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{userId: int, name: string, avatarUrl: ?string, totalExp: int, rank: int}|null */
    public function rankForUser(int $userId): ?array
    {
        $statement = $this->database->prepare(self::RANKING_QUERY . ' WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUser($row);
    }

    /** @return array{leaders: list<array{userId: int, name: string, avatarUrl: ?string, totalExp: int, rank: int}>, ownRanking: ?array} */
    public function rankingForUser(int $userId): array
    {
        $statement = $this->database->prepare(
            self::RANKING_QUERY . ' WHERE listing_position <= 100 OR user_id = :user_id ORDER BY listing_position'
        );
        $statement->execute(['user_id' => $userId]);
        $leaders = [];
        $ownRanking = null;

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $user = $this->mapUser($row);
            if ((int) $row['listing_position'] <= 100) {
                $leaders[] = $user;
            }
            if ($user['userId'] === $userId) {
                $ownRanking = $user;
            }
        }

        return ['leaders' => $leaders, 'ownRanking' => $ownRanking];
    }

    private function mapUser(array $row): array
    {
        $version = $row['avatar_version'];

        return [
            'userId' => (int) $row['user_id'],
            'name' => (string) $row['name'],
            'avatarUrl' => is_string($version) && preg_match('/\A[a-f0-9]{32}\z/', $version) === 1
                ? '/avatars/' . (int) $row['user_id'] . '?v=' . $version : null,
            'totalExp' => (int) $row['total_exp'],
            'rank' => (int) $row['user_rank'],
        ];
    }
}
