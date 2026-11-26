<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

final class RecommendationChatRepository
{
    private const RANGES = [
        'minEpisodes' => 'a.episodes >= :minEpisodes',
        'maxEpisodes' => 'a.episodes <= :maxEpisodes',
        'minYear' => 'a.release_year >= :minYear',
        'maxYear' => 'a.release_year <= :maxYear',
        'minScore' => 'a.score >= :minScore',
        'maxScore' => 'a.score <= :maxScore',
        'status' => 'a.airing_status = :status',
        'format' => "CASE UPPER(TRIM(a.media_type)) WHEN 'TV SPECIAL' THEN 'SPECIAL'
            ELSE UPPER(TRIM(a.media_type)) END = :format",
    ];

    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->database->query('SELECT DISTINCT name FROM genres ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<int> $seen
     * @return array{items: array<int, array>, references: list<array>, issue?: string, value?: string}
     */
    public function search(array $criteria, array $seen, int $limit): array
    {
        $ownsTransaction = !$this->database->inTransaction();
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        }
        try {
            if ($ownsTransaction) {
                $this->database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $result = $this->searchSnapshot($criteria, $seen, $limit);
            if ($ownsTransaction) {
                $this->database->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    private function searchSnapshot(array $criteria, array $seen, int $limit): array
    {
        $tags = array_map(mb_strtolower(...), $this->tags());
        foreach ([...($criteria['requireTags'] ?? []), ...($criteria['excludeTags'] ?? [])] as $tag) {
            if (!in_array(mb_strtolower($tag), $tags, true)) {
                return ['items' => [], 'references' => [], 'issue' => 'unknown_tag', 'value' => $tag];
            }
        }

        $references = [];
        foreach ($criteria['references'] ?? [] as $title) {
            $reference = $this->reference($title);
            if ($reference === null) {
                return ['items' => [], 'references' => [], 'issue' => 'reference', 'value' => $title];
            }
            $references[(int) $reference['id']] = ['id' => (int) $reference['id'], 'title' => $reference['title']];
        }

        $rows = $this->candidateRows($criteria, array_keys($references), $seen, $limit);
        $catalogue = (new AnimeRepository($this->database))->findByIds(array_map('intval', array_column($rows, 'id')));
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $items[$id] = [
                'anime' => $catalogue[$id],
                'sharedTags' => array_map(AnimeRepository::genreLabel(...),
                    json_decode($row['shared_tags'], true, 8, JSON_THROW_ON_ERROR)),
                'similarDescription' => (float) $row['description_rank'] > 0.0,
            ];
        }
        return ['items' => $items, 'references' => array_values($references)];
    }

    private function reference(string $title): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT a.id, a.title, CASE WHEN LOWER(a.title) = LOWER(:title) THEN 0 ELSE 1 END AS priority
            FROM anime a
            WHERE a.search_text ILIKE :pattern ESCAPE '!'
              AND (LOWER(a.title) = LOWER(:exact)
                OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(a.alternative_titles) AS alias(name)
                    WHERE LOWER(alias.name) = LOWER(:alias)))
            ORDER BY priority, a.id
            LIMIT 2
            SQL);
        $statement->execute([
            'title' => $title, 'exact' => $title, 'alias' => $title,
            'pattern' => '%' . strtr($title, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%',
        ]);
        $matches = $statement->fetchAll(PDO::FETCH_ASSOC);

        return isset($matches[0]) && ($matches[0]['priority'] !== ($matches[1]['priority'] ?? null)) ? $matches[0] : null;
    }

    private function candidateRows(array $criteria, array $references, array $seen, int $limit): array
    {
        $where = [
            'a.id NOT IN (SELECT value::BIGINT FROM jsonb_array_elements_text(CAST(:omit AS JSONB)))',
        ];
        $parameters = [
            'omit' => json_encode(array_values(array_unique([...$references, ...$seen])), JSON_THROW_ON_ERROR),
            'references' => json_encode($references, JSON_THROW_ON_ERROR),
            'themes' => json_encode($criteria['themes'] ?? [], JSON_THROW_ON_ERROR),
        ];
        foreach (self::RANGES as $key => $comparison) {
            if (isset($criteria[$key])) {
                $where[] = $comparison;
                $parameters[$key] = $criteria[$key];
            }
        }
        if (isset($criteria['minEpisodes']) || isset($criteria['maxEpisodes'])) {
            $where[] = 'a.episodes > 0';
        }
        foreach (['requireTags' => 'EXISTS', 'excludeTags' => 'NOT EXISTS'] as $kind => $operator) {
            foreach ($criteria[$kind] ?? [] as $index => $tag) {
                $key = $kind . $index;
                $where[] = "$operator (SELECT 1 FROM anime_genres ag JOIN genres g ON g.id = ag.genre_id
                    WHERE ag.anime_id = a.id AND LOWER(g.name) = LOWER(:$key))";
                $parameters[$key] = $tag;
            }
        }
        if ($references !== []) {
            $where[] = '(s.id IS NOT NULL OR d.description_rank > 0 OR d.theme_rank > 0)';
        }
        $conditions = implode(' AND ', $where);
        $lengthOrder = match ($criteria['length'] ?? null) {
            'short' => 'NULLIF(a.episodes, 0) ASC NULLS LAST, ',
            'long' => 'NULLIF(a.episodes, 0) DESC NULLS LAST, ',
            default => '',
        };
        $statement = $this->database->prepare(<<<SQL
            WITH reference_ids AS (
                SELECT value::BIGINT AS id FROM jsonb_array_elements_text(CAST(:references AS JSONB))
            ), reference_words AS (
                SELECT DISTINCT word FROM reference_ids r JOIN anime ref ON ref.id = r.id
                CROSS JOIN LATERAL unnest(tsvector_to_array(ref.chat_synopsis)) AS term(word)
                WHERE length(word) >= 4 AND word ~ '[[:alpha:]]'
            ), queries AS (
                SELECT
                    (SELECT string_agg(quote_literal(word), ' | ')::TSQUERY FROM reference_words) AS description_query,
                    (SELECT string_agg(term::TEXT, ' | ')::TSQUERY FROM (
                        SELECT plainto_tsquery('english', value) AS term
                        FROM jsonb_array_elements_text(CAST(:themes AS JSONB))
                    ) AS terms WHERE numnode(term) > 0) AS theme_query
            ), description_matches AS (
                SELECT a.id,
                    COALESCE(ts_rank(a.chat_synopsis, q.description_query, 32), 0) AS description_rank,
                    COALESCE(ts_rank(a.chat_synopsis, q.theme_query, 32), 0) AS theme_rank
                FROM queries q JOIN anime a
                    ON a.chat_synopsis @@ q.description_query OR a.chat_synopsis @@ q.theme_query
            ), reference_tags AS (
                SELECT DISTINCT ag.genre_id FROM anime_genres ag JOIN reference_ids r ON r.id = ag.anime_id
            ), shared AS (
                SELECT ag.anime_id AS id, COUNT(*) AS tag_count, jsonb_agg(g.name ORDER BY g.name) AS tags
                FROM reference_tags r JOIN anime_genres ag USING (genre_id) JOIN genres g ON g.id = ag.genre_id
                GROUP BY ag.anime_id
            )
            SELECT a.id, COALESCE(d.description_rank, 0) AS description_rank,
                COALESCE(s.tags, '[]'::JSONB) AS shared_tags
            FROM anime a LEFT JOIN description_matches d ON d.id = a.id LEFT JOIN shared s ON s.id = a.id
            WHERE $conditions
            ORDER BY COALESCE(d.theme_rank, 0) DESC,
                (COALESCE(d.description_rank, 0) + COALESCE(s.tag_count, 0) * 0.14) DESC,
                {$lengthOrder}a.score DESC NULLS LAST, LOWER(a.title), a.id
            LIMIT :limit
            SQL);
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
