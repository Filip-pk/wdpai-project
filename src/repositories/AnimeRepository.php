<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\AnimeSearchCriteria;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class AnimeRepository
{
    private const GENRE_LABELS = [
        'Action' => 'Akcja', 'Adventure' => 'Przygoda', 'Avant Garde' => 'Awangarda',
        'Award Winning' => 'Nagradzane', 'Comedy' => 'Komedia', 'Drama' => 'Dramat',
        'Fantasy' => 'Fantasy', 'Gourmet' => 'Kulinaria', 'Horror' => 'Horror',
        'Mystery' => 'Tajemnica', 'Romance' => 'Romans', 'Sci-Fi' => 'Sci-Fi',
        'Slice of Life' => 'Okruchy życia', 'Sports' => 'Sport', 'Supernatural' => 'Nadprzyrodzone',
        'Suspense' => 'Suspens', 'Adult Cast' => 'Dorośli bohaterowie',
        'Anthropomorphic' => 'Antropomorfizm', 'Childcare' => 'Opieka nad dziećmi',
        'Combat Sports' => 'Sporty walki', 'Crossdressing' => 'Przebieranie się',
        'Delinquents' => 'Buntownicy', 'Detective' => 'Detektywistyczne',
        'Educational' => 'Edukacyjne', 'Gag Humor' => 'Humor absurdalny',
        'Gore' => 'Gore', 'Harem' => 'Harem', 'High Stakes Game' => 'Gra o wysoką stawkę',
        'Historical' => 'Historyczne', 'Idols (Female)' => 'Idolki', 'Idols (Male)' => 'Idole',
        'Isekai' => 'Isekai', 'Iyashikei' => 'Relaksujące', 'Love Polygon' => 'Wielokąty miłosne',
        'Magical Sex Shift' => 'Magiczna zmiana płci', 'Mahou Shoujo' => 'Magiczne dziewczyny',
        'Martial Arts' => 'Sztuki walki', 'Mecha' => 'Mecha', 'Medical' => 'Medyczne',
        'Military' => 'Wojskowe', 'Music' => 'Muzyczne', 'Mythology' => 'Mitologia',
        'Organized Crime' => 'Przestępczość zorganizowana', 'Otaku Culture' => 'Kultura otaku',
        'Parody' => 'Parodia', 'Performing Arts' => 'Sztuki sceniczne',
        'Pets' => 'Zwierzęta', 'Psychological' => 'Psychologiczne',
        'Racing' => 'Wyścigi', 'Reincarnation' => 'Reinkarnacja', 'Reverse Harem' => 'Odwrócony harem',
        'Romantic Subtext' => 'Wątek romantyczny', 'Samurai' => 'Samuraje', 'School' => 'Szkolne',
        'Showbiz' => 'Show-biznes', 'Space' => 'Kosmos', 'Strategy Game' => 'Gry strategiczne',
        'Super Power' => 'Supermoce', 'Survival' => 'Przetrwanie', 'Team Sports' => 'Sporty zespołowe',
        'Time Travel' => 'Podróże w czasie', 'Urban Fantasy' => 'Miejskie fantasy',
        'Vampire' => 'Wampiry', 'Video Game' => 'Gry wideo', 'Villainess' => 'Antybohaterka',
        'Visual Arts' => 'Sztuki wizualne', 'Workplace' => 'Miejsce pracy',
    ];

    private const SELECT_ANIME = "SELECT a.*,
        COALESCE((SELECT jsonb_agg(g.name ORDER BY g.name)
            FROM anime_genres ag JOIN genres g ON g.id = ag.genre_id
            WHERE ag.anime_id = a.id), '[]'::jsonb) AS genre_names
        FROM anime a";

    private bool $writeContextActive = false;
    /** @var array<string, PDOStatement> */
    private array $writeStatements = [];
    private array $malIds = [];
    private array $animeMalIds = [];
    private array $externalIds = [];
    private array $genreIds = [];
    private array $seenIdentities = [];
    private int $inserted = 0;
    private int $updated = 0;

    public function __construct(private readonly PDO $database)
    {
    }

    public function count(): int
    {
        return (int) $this->database->query('SELECT COUNT(*) FROM anime')->fetchColumn();
    }

    /** @return list<array{id: int, name: string}> */
    public function genres(): array
    {
        $rows = $this->database->query(
            'SELECT g.id, g.name FROM genres g
             WHERE EXISTS (SELECT 1 FROM anime_genres ag WHERE ag.genre_id = g.id)'
        )->fetchAll(PDO::FETCH_ASSOC);
        $genres = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => self::GENRE_LABELS[$row['name']] ?? $row['name'],
        ], $rows);
        usort($genres, static fn (array $first, array $second): int => strnatcasecmp($first['name'], $second['name']));

        return $genres;
    }

    /** @return array{animes: array<int, array>, total: int, page: int, totalPages: int} */
    public function search(
        string $query = '',
        ?int $genreId = null,
        int $page = 1,
        int $perPage = 20,
        array $filters = [],
        string $sort = AnimeSearchCriteria::DEFAULT_SORT,
    ): array {
        if (!array_key_exists($sort, AnimeSearchCriteria::SORT_OPTIONS)) {
            throw new InvalidArgumentException('Nieprawidłowy sposób sortowania.');
        }
        $conditions = [];
        $parameters = [];

        if ($query !== '') {
            $pattern = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $queryLength = preg_match_all('/./us', $query);
            if ($queryLength !== false && $queryLength >= 3) {
                $conditions[] = "a.search_text ILIKE :indexed_query ESCAPE '!'";
                $parameters['indexed_query'] = $pattern;
            }
            $conditions[] = "(a.title ILIKE :title_query ESCAPE '!'
                OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(a.alternative_titles) AS alias(title)
                    WHERE alias.title ILIKE :alias_query ESCAPE '!'))";
            $parameters['title_query'] = $pattern;
            $parameters['alias_query'] = $pattern;
        }
        if ($genreId !== null) {
            $conditions[] = 'EXISTS (SELECT 1 FROM anime_genres ag WHERE ag.anime_id = a.id AND ag.genre_id = CAST(:genre_id AS BIGINT))';
            $parameters['genre_id'] = $genreId;
        }

        $ranges = [
            'episodes' => ['a.episodes', 1, 2147483647],
            'score' => ['a.score', 0, 10],
            'year' => ['a.release_year', 1900, 2200],
        ];
        foreach ($ranges as $name => [$column, $minimum, $maximum]) {
            $lower = $filters[$name . 'Min'] ?? null;
            $upper = $filters[$name . 'Max'] ?? null;
            foreach (['Min' => $lower, 'Max' => $upper] as $bound => $value) {
                if ($value === null) {
                    continue;
                }
                $numeric = is_int($value) || ($name === 'score' && is_float($value) && is_finite($value));
                if (!$numeric || $value < $minimum || $value > $maximum) {
                    throw new InvalidArgumentException('Nieprawidłowy zakres: ' . $name . '.');
                }
                $parameter = $name . '_' . strtolower($bound);
                $conditions[] = $column . ($bound === 'Min' ? ' >= :' : ' <= :') . $parameter;
                $parameters[$parameter] = $value;
            }
            if ($lower !== null && $upper !== null && $lower > $upper) {
                throw new InvalidArgumentException('Dolna granica zakresu przekracza górną.');
            }
            if ($name === 'episodes' && ($lower !== null || $upper !== null)) {
                $conditions[] = 'a.episodes > 0';
            }
        }
        $type = $filters['type'] ?? '';
        if (!is_string($type) || !array_key_exists($type, AnimeSearchCriteria::TYPE_OPTIONS)) {
            throw new InvalidArgumentException('Nieprawidłowy format anime.');
        }
        if ($type !== '') {
            $conditions[] = "CASE UPPER(TRIM(a.media_type))
                WHEN 'TV SPECIAL' THEN 'SPECIAL' WHEN '' THEN 'UNKNOWN'
                ELSE UPPER(TRIM(a.media_type)) END = :media_type";
            $parameters['media_type'] = $type;
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $count = $this->database->prepare('SELECT COUNT(*) FROM anime a' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $relevance = $sort === 'relevance' && $query !== '';
        $order = match ($sort) {
            'score_asc' => 'a.score ASC NULLS LAST, LOWER(a.title), a.id',
            'title_asc' => 'LOWER(a.title), a.id',
            'title_desc' => 'LOWER(a.title) DESC, a.id',
            'episodes_asc' => 'NULLIF(a.episodes, 0) ASC NULLS LAST, LOWER(a.title), a.id',
            'episodes_desc' => 'NULLIF(a.episodes, 0) DESC NULLS LAST, LOWER(a.title), a.id',
            'year_asc' => 'a.release_year ASC NULLS LAST, LOWER(a.title), a.id',
            'year_desc' => 'a.release_year DESC NULLS LAST, LOWER(a.title), a.id',
            'relevance' => $relevance ? "CASE
                WHEN LOWER(a.title) = LOWER(:exact_title) THEN 0
                WHEN EXISTS (SELECT 1 FROM jsonb_array_elements_text(a.alternative_titles) AS exact_alias(title)
                    WHERE LOWER(exact_alias.title) = LOWER(:exact_alias)) THEN 1
                ELSE 2 END, LOWER(a.title), a.id" : 'a.score DESC NULLS LAST, LOWER(a.title), a.id',
            default => 'a.score DESC NULLS LAST, LOWER(a.title), a.id',
        };
        $statement = $this->database->prepare(
            self::SELECT_ANIME . $where . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        if ($relevance) {
            $statement->bindValue('exact_title', $query);
            $statement->bindValue('exact_alias', $query);
        }
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return ['animes' => $this->mapRows($statement->fetchAll(PDO::FETCH_ASSOC)),
            'total' => $total, 'page' => $page, 'totalPages' => $totalPages];
    }

    public function findById(int $id): ?array
    {
        return $this->findByIds([$id])[$id] ?? null;
    }

    /** @return array<int, array> */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($ids === []) {
            return [];
        }

        $statement = $this->database->prepare(
            self::SELECT_ANIME . ' WHERE a.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY a.id'
        );
        foreach ($ids as $index => $id) {
            $statement->bindValue($index + 1, $id, PDO::PARAM_INT);
        }
        $statement->execute();

        return $this->mapRows($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{inserted: int, updated: int, total: int} */
    public function importBatch(iterable $animes, bool $mergeMissing = false): array
    {
        if ($this->writeContextActive) {
            throw new RuntimeException('Import katalogu jest już uruchomiony na tym połączeniu.');
        }

        return $this->writeScope(function () use ($animes, $mergeMissing): array {
            foreach ($animes as $anime) {
                if (!is_array($anime)) {
                    throw new InvalidArgumentException('Nieprawidłowy rekord anime.');
                }
                $this->upsert($anime, $mergeMissing);
            }

            return ['inserted' => $this->inserted, 'updated' => $this->updated, 'total' => $this->count()];
        });
    }

    public function upsert(array $anime, bool $mergeMissing = false): int
    {
        if (!$this->writeContextActive) {
            return $this->writeScope(fn (): int => $this->upsert($anime, $mergeMissing));
        }

        [$malId, $identities] = $this->identities($anime);
        $matches = [];
        if ($malId !== null && isset($this->malIds[$malId])) {
            $matches[$this->malIds[$malId]] = true;
        }
        foreach ($identities as $key => $identity) {
            if (isset($this->seenIdentities[$key])) {
                throw new InvalidArgumentException('Partia zawiera powtórzony identyfikator anime: ' . $identity['provider'] . ':' . $identity['externalId'] . '.');
            }
            if (isset($this->externalIds[$key])) {
                $matches[$this->externalIds[$key]] = true;
            }
            if ($identity['provider'] === 'mal' && isset($this->malIds[(int) $identity['externalId']])) {
                $matches[$this->malIds[(int) $identity['externalId']]] = true;
            }
        }
        if (count($matches) > 1) {
            throw new RuntimeException('Identyfikatory źródłowe wskazują różne istniejące anime. Import nie scala ich automatycznie.');
        }

        $id = $matches === [] ? null : (int) array_key_first($matches);
        $existingMalId = $id === null ? null : ($this->animeMalIds[$id] ?? null);
        if ($malId !== null && $existingMalId !== null && $malId !== $existingMalId) {
            throw new RuntimeException('Nowy identyfikator MAL jest sprzeczny z dotychczasowym anime.');
        }

        $values = [
            'mal_id' => $malId, 'title' => $anime['title'],
            'alternative_titles' => json_encode($anime['alternativeTitles'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'description' => $anime['description'], 'description_language' => $anime['descriptionLanguage'],
            'cover_url' => $anime['coverUrl'], 'episodes' => $anime['episodes'], 'score' => $anime['score'],
            'studio' => $anime['studio'], 'release_year' => $anime['year'],
            'media_type' => $anime['type'], 'source_url' => $anime['sourceUrl'],
        ];

        if ($id === null) {
            $statement = $this->writeStatement('insert_anime',
                'INSERT INTO anime (mal_id, title, alternative_titles, description, description_language,
                    cover_url, episodes, score, studio, release_year, media_type, source_url)
                 VALUES (:mal_id, :title, :alternative_titles, :description, :description_language,
                    :cover_url, :episodes, :score, :studio, :release_year, :media_type, :source_url)
                 RETURNING id'
            );
            $statement->execute($values);
            $id = (int) $statement->fetchColumn();
            $this->inserted++;
        } else {
            $values['id'] = $id;
            if ($mergeMissing) {
                $values['description_present'] = $anime['description'] !== '' ? 1 : 0;
            }
            $statement = $this->writeStatement($mergeMissing ? 'merge_anime' : 'replace_anime',
                'UPDATE anime SET mal_id = COALESCE(:mal_id, mal_id), title = :title,
                    alternative_titles = ' . ($mergeMissing
                        ? "(SELECT COALESCE(jsonb_agg(value ORDER BY value), '[]'::jsonb)
                            FROM (SELECT DISTINCT value FROM jsonb_array_elements(alternative_titles || CAST(:alternative_titles AS JSONB))) AS aliases)"
                        : ':alternative_titles') . ',
                    description = ' . ($mergeMissing ? "COALESCE(NULLIF(:description, ''), description)" : ':description') . ',
                    description_language = ' . ($mergeMissing
                        ? 'CASE WHEN :description_present = 1 THEN :description_language ELSE description_language END'
                        : ':description_language') . ',
                    cover_url = ' . ($mergeMissing ? 'COALESCE(:cover_url, cover_url)' : ':cover_url') . ',
                    episodes = ' . ($mergeMissing ? 'COALESCE(:episodes, episodes)' : ':episodes') . ',
                    score = ' . ($mergeMissing ? 'COALESCE(:score, score)' : ':score') . ',
                    studio = ' . ($mergeMissing ? "COALESCE(NULLIF(:studio, ''), studio)" : ':studio') . ',
                    release_year = ' . ($mergeMissing ? 'COALESCE(:release_year, release_year)' : ':release_year') . ',
                    media_type = ' . ($mergeMissing ? "COALESCE(NULLIF(NULLIF(:media_type, ''), 'UNKNOWN'), media_type)" : ':media_type') . ',
                    source_url = ' . ($mergeMissing ? 'COALESCE(:source_url, source_url)' : ':source_url') . ',
                    source_updated_at = CURRENT_TIMESTAMP WHERE id = :id RETURNING id'
            );
            $statement->execute($values);
            if ((int) $statement->fetchColumn() !== $id) {
                throw new RuntimeException('Anime zniknęło podczas aktualizacji katalogu.');
            }
            if (!$mergeMissing) {
                $this->writeStatement('delete_genres', 'DELETE FROM anime_genres WHERE anime_id = :anime_id')
                    ->execute(['anime_id' => $id]);
            }
            $this->updated++;
        }

        $effectiveMalId = $malId ?? $existingMalId;
        $this->animeMalIds[$id] = $effectiveMalId;
        if ($effectiveMalId !== null) {
            $this->malIds[$effectiveMalId] = $id;
        }
        foreach ($identities as $key => $identity) {
            if (!isset($this->externalIds[$key])) {
                $mapping = $this->writeStatement('insert_identity',
                    'INSERT INTO anime_external_ids (provider, external_id, anime_id)
                     VALUES (:provider, :external_id, :anime_id)
                     ON CONFLICT (provider, external_id) DO NOTHING RETURNING anime_id'
                );
                $mapping->execute([
                    'provider' => $identity['provider'], 'external_id' => $identity['externalId'], 'anime_id' => $id,
                ]);
                $mappedId = $mapping->fetchColumn();
                if ($mappedId === false) {
                    $lookup = $this->writeStatement('lookup_identity',
                        'SELECT anime_id FROM anime_external_ids WHERE provider = :provider AND external_id = :external_id'
                    );
                    $lookup->execute(['provider' => $identity['provider'], 'external_id' => $identity['externalId']]);
                    $mappedId = $lookup->fetchColumn();
                }
                if ((int) $mappedId !== $id) {
                    throw new RuntimeException('Identyfikator źródłowy został przypisany do innego anime podczas importu.');
                }
                $this->externalIds[$key] = $id;
            }
            $this->seenIdentities[$key] = true;
        }

        $genreIds = [];
        foreach ($anime['genres'] as $genre) {
            $sourceId = $genre['sourceId'];
            $name = $genre['name'];
            if (!isset($this->genreIds[$sourceId]) || $this->genreIds[$sourceId]['name'] !== $name) {
                $genreStatement = $this->writeStatement('upsert_genre',
                    'INSERT INTO genres (source_id, name) VALUES (:source_id, :name)
                     ON CONFLICT (source_id) DO UPDATE SET name = EXCLUDED.name RETURNING id'
                );
                $genreStatement->execute(['source_id' => $sourceId, 'name' => $name]);
                $this->genreIds[$sourceId] = ['id' => (int) $genreStatement->fetchColumn(), 'name' => $name];
            }
            $genreIds[$this->genreIds[$sourceId]['id']] = true;
        }
        if ($genreIds !== []) {
            $count = count($genreIds);
            $links = $this->writeStatement('link_genres_' . $count,
                'INSERT INTO anime_genres (anime_id, genre_id) VALUES '
                    . implode(', ', array_fill(0, $count, '(?, ?)')) . ' ON CONFLICT DO NOTHING'
            );
            $parameters = [];
            foreach (array_keys($genreIds) as $genreId) {
                $parameters[] = $id;
                $parameters[] = $genreId;
            }
            $links->execute($parameters);
        }

        return $id;
    }

    private function identities(array $anime): array
    {
        $malId = $anime['malId'] ?? null;
        if ($malId !== null && (!is_int($malId) || $malId < 1 || $malId > 2147483647)) {
            throw new InvalidArgumentException('Nieprawidłowy identyfikator MAL.');
        }
        $identities = [];
        if ($malId !== null) {
            $identities['mal' . "\0" . $malId] = ['provider' => 'mal', 'externalId' => (string) $malId];
        }
        $externalIds = $anime['externalIds'] ?? [];
        if (!is_array($externalIds) || count($externalIds) > 100) {
            throw new InvalidArgumentException('Nieprawidłowa lista identyfikatorów źródłowych.');
        }
        foreach ($externalIds as $identity) {
            $provider = is_array($identity) ? ($identity['provider'] ?? null) : null;
            $externalId = is_array($identity) ? ($identity['externalId'] ?? null) : null;
            if (!is_string($provider) || strlen($provider) > 32
                || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $provider) !== 1
                || !is_string($externalId) || $externalId === '' || $externalId !== trim($externalId)
                || str_contains($externalId, "\0") || preg_match('/\A.{1,255}\z/us', $externalId) !== 1) {
                throw new InvalidArgumentException('Nieprawidłowy identyfikator źródłowy anime.');
            }
            if ($provider === 'mal') {
                if (!ctype_digit($externalId) || strlen($externalId) > 10
                    || (int) $externalId < 1 || (int) $externalId > 2147483647) {
                    throw new InvalidArgumentException('Nieprawidłowy źródłowy identyfikator MAL.');
                }
                $externalId = (string) (int) $externalId;
            }
            $identities[$provider . "\0" . $externalId] = ['provider' => $provider, 'externalId' => $externalId];
        }
        if ($identities === []) {
            throw new InvalidArgumentException('Anime musi mieć przynajmniej jeden identyfikator źródłowy.');
        }

        return [$malId, $identities];
    }

    private function writeScope(callable $operation): mixed
    {
        $nested = $this->database->inTransaction();
        if ($nested) {
            $this->database->exec('SAVEPOINT anime_catalog_import');
        } else {
            $this->database->beginTransaction();
        }

        try {
            $this->database->query('SELECT pg_advisory_xact_lock(1668178293, 1)')->fetchColumn();
            $this->writeContextActive = true;
            foreach ($this->database->query('SELECT id, mal_id FROM anime') as $row) {
                $id = (int) $row['id'];
                $malId = $row['mal_id'] === null ? null : (int) $row['mal_id'];
                $this->animeMalIds[$id] = $malId;
                if ($malId !== null) {
                    $this->malIds[$malId] = $id;
                }
            }
            foreach ($this->database->query('SELECT provider, external_id, anime_id FROM anime_external_ids') as $row) {
                $this->externalIds[$row['provider'] . "\0" . $row['external_id']] = (int) $row['anime_id'];
            }
            foreach ($this->database->query('SELECT id, source_id, name FROM genres') as $row) {
                $this->genreIds[(int) $row['source_id']] = ['id' => (int) $row['id'], 'name' => $row['name']];
            }

            $result = $operation();
            if ($nested) {
                $this->database->exec('RELEASE SAVEPOINT anime_catalog_import');
            } else {
                $this->database->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($nested) {
                $this->database->exec('ROLLBACK TO SAVEPOINT anime_catalog_import');
                $this->database->exec('RELEASE SAVEPOINT anime_catalog_import');
            } elseif ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        } finally {
            $this->writeContextActive = false;
            $this->writeStatements = [];
            $this->malIds = [];
            $this->animeMalIds = [];
            $this->externalIds = [];
            $this->genreIds = [];
            $this->seenIdentities = [];
            $this->inserted = 0;
            $this->updated = 0;
        }
    }

    private function writeStatement(string $name, string $sql): PDOStatement
    {
        return $this->writeStatements[$name] ??= $this->database->prepare($sql);
    }

    private function mapRows(array $rows): array
    {
        $animes = [];
        foreach ($rows as $row) {
            $genres = json_decode($row['genre_names'], true, 512, JSON_THROW_ON_ERROR);
            $animes[(int) $row['id']] = [
                'title' => $row['title'], 'malId' => $row['mal_id'] === null ? null : (int) $row['mal_id'],
                'alternativeTitles' => json_decode($row['alternative_titles'], true, 512, JSON_THROW_ON_ERROR),
                'description' => $row['description'], 'descriptionLanguage' => $row['description_language'],
                'coverUrl' => $row['cover_url'], 'episodes' => $row['episodes'] === null ? null : (int) $row['episodes'],
                'score' => $row['score'] === null ? null : (float) $row['score'], 'studio' => $row['studio'],
                'year' => $row['release_year'] === null ? null : (int) $row['release_year'],
                'type' => $row['media_type'], 'sourceUrl' => $row['source_url'],
                'genres' => array_map(static fn (string $genre): string => self::GENRE_LABELS[$genre] ?? $genre, $genres),
            ];
        }

        return $animes;
    }
}
