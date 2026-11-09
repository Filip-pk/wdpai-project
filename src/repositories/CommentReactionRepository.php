<?php

declare(strict_types=1);

namespace App\Repository;

use LogicException;
use PDO;

final class CommentReactionRepository
{
    private const SELECT_TARGET = '
        SELECT comments.id, comments.user_id, comments.parent_id
        FROM anime_comments AS comments INNER JOIN users AS actor ON actor.id = :user_id
        WHERE comments.id = :comment_id AND comments.anime_id = :anime_id
          AND comments.user_id <> actor.id
          AND comments.deleted_at IS NULL AND comments.moderated_at IS NULL';

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return array{id: int, userId: int, parentId: ?int}|null */
    public function targetForUser(int $userId, int $animeId, int $commentId, bool $lock = false): ?array
    {
        if ($userId <= 0 || $animeId <= 0 || $commentId <= 0) {
            return null;
        }
        if ($lock && !$this->database->inTransaction()) {
            throw new LogicException('Reaction target locking requires a transaction.');
        }

        $statement = $this->database->prepare(self::SELECT_TARGET . ($lock ? ' FOR UPDATE OF comments' : ''));
        $statement->execute(['user_id' => $userId, 'comment_id' => $commentId, 'anime_id' => $animeId]);
        $target = $statement->fetch(PDO::FETCH_ASSOC);

        return $target === false ? null : [
            'id' => (int) $target['id'], 'userId' => (int) $target['user_id'],
            'parentId' => $target['parent_id'] === null ? null : (int) $target['parent_id'],
        ];
    }

    public function currentForUser(int $userId, int $commentId): int
    {
        $statement = $this->database->prepare('SELECT value FROM comment_reactions WHERE user_id = :user_id AND comment_id = :comment_id');
        $statement->execute(['user_id' => $userId, 'comment_id' => $commentId]);
        $value = $statement->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }

    public function setForUser(int $userId, int $animeId, int $commentId, int $value): bool
    {
        if (!in_array($value, [-1, 0, 1], true) || $userId <= 0 || $animeId <= 0 || $commentId <= 0) {
            return false;
        }

        $target = '
            WITH target AS (
                SELECT comments.id, actor.id AS actor_id FROM anime_comments AS comments
                INNER JOIN users AS actor ON actor.id = :user_id
                WHERE comments.id = :comment_id AND comments.anime_id = :anime_id
                  AND comments.user_id <> actor.id AND comments.deleted_at IS NULL AND comments.moderated_at IS NULL
                FOR UPDATE OF comments
            ), changed AS (';
        $mutation = $value === 0
            ? 'DELETE FROM comment_reactions AS reactions USING target
               WHERE reactions.user_id = target.actor_id AND reactions.comment_id = target.id RETURNING reactions.comment_id'
            : 'INSERT INTO comment_reactions (user_id, comment_id, value)
               SELECT target.actor_id, target.id, :value FROM target
               ON CONFLICT (user_id, comment_id) DO UPDATE
               SET value = EXCLUDED.value, updated_at = clock_timestamp()
               WHERE comment_reactions.value IS DISTINCT FROM EXCLUDED.value RETURNING comment_id';
        $statement = $this->database->prepare($target . $mutation . ') SELECT EXISTS (SELECT 1 FROM target)');
        $parameters = ['user_id' => $userId, 'comment_id' => $commentId, 'anime_id' => $animeId];
        if ($value !== 0) {
            $parameters['value'] = $value;
        }
        $statement->execute($parameters);

        return in_array($statement->fetchColumn(), [true, 1, '1', 't'], true);
    }

    /** @return array<int, array{likes: int, dislikes: int, userReaction: int}> */
    public function countsForComments(array $commentIds, ?int $userId = null): array
    {
        $ids = array_values(array_unique(array_filter($commentIds, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }

        $counts = array_fill_keys($ids, ['likes' => 0, 'dislikes' => 0, 'userReaction' => 0]);
        $placeholders = array_map(static fn (int $index): string => ':comment_' . $index, array_keys($ids));
        $statement = $this->database->prepare(
            'SELECT comments.id, COUNT(*) FILTER (WHERE reactions.value = 1) AS likes,
                    COUNT(*) FILTER (WHERE reactions.value = -1) AS dislikes,
                    COALESCE(MAX(reactions.value) FILTER (WHERE reactions.user_id = :viewer), 0) AS user_reaction
             FROM anime_comments AS comments LEFT JOIN comment_reactions AS reactions ON reactions.comment_id = comments.id
             WHERE comments.id IN (' . implode(', ', $placeholders) . ')
               AND comments.deleted_at IS NULL AND comments.moderated_at IS NULL GROUP BY comments.id'
        );
        $statement->bindValue('viewer', max(0, $userId ?? 0), PDO::PARAM_INT);
        foreach ($ids as $index => $id) {
            $statement->bindValue('comment_' . $index, $id, PDO::PARAM_INT);
        }
        $statement->execute();
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['id']] = [
                'likes' => (int) $row['likes'], 'dislikes' => (int) $row['dislikes'],
                'userReaction' => (int) $row['user_reaction'],
            ];
        }

        return $counts;
    }
}
