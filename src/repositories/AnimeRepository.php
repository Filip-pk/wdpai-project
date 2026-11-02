<?php

declare(strict_types=1);

namespace App\Repository;

use InvalidArgumentException;
use PDO;
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
    public function search(string $query = '', ?int $genreId = null, int $page = 1, int $perPage = 20): array
    {
        $conditions = [];
        $parameters = [];

        if ($query !== '') {
            $pattern = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
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

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $count = $this->database->prepare('SELECT COUNT(*) FROM anime a' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $statement = $this->database->prepare(
            self::SELECT_ANIME . $where . ' ORDER BY LOWER(a.title), a.id LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
    public function importBatch(array $animes): array
    {
        $nested = $this->database->inTransaction();
        if ($nested) {
            $this->database->exec('SAVEPOINT anime_catalog_import');
        } else {
            $this->database->beginTransaction();
        }
        $inserted = 0;
        $updated = 0;
        $seen = [];

        try {
            foreach ($animes as $anime) {
                $malId = $anime['malId'] ?? null;
                if (!is_int($malId) || $malId < 1 || isset($seen[$malId])) {
                    throw new InvalidArgumentException('Nieprawidłowy lub powtórzony identyfikator anime.');
                }
                $seen[$malId] = true;
                $existing = $this->database->prepare('SELECT id FROM anime WHERE mal_id = :mal_id');
                $existing->execute(['mal_id' => $malId]);
                $exists = $existing->fetchColumn() !== false;
                $this->upsert($anime);
                $exists ? $updated++ : $inserted++;
            }

            $total = $this->count();
            if ($nested) {
                $this->database->exec('RELEASE SAVEPOINT anime_catalog_import');
            } else {
                $this->database->commit();
            }

            return ['inserted' => $inserted, 'updated' => $updated, 'total' => $total];
        } catch (Throwable $exception) {
            if ($nested) {
                $this->database->exec('ROLLBACK TO SAVEPOINT anime_catalog_import');
                $this->database->exec('RELEASE SAVEPOINT anime_catalog_import');
            } elseif ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $exception;
        }
    }

    public function upsert(array $anime): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO anime (mal_id, title, alternative_titles, description, description_language,
                cover_url, episodes, score, studio, release_year, media_type, source_url)
             VALUES (:mal_id, :title, :alternative_titles, :description, :description_language,
                :cover_url, :episodes, :score, :studio, :release_year, :media_type, :source_url)
             ON CONFLICT (mal_id) DO UPDATE SET title = EXCLUDED.title,
                alternative_titles = EXCLUDED.alternative_titles, description = EXCLUDED.description,
                description_language = EXCLUDED.description_language, cover_url = EXCLUDED.cover_url,
                episodes = EXCLUDED.episodes, score = EXCLUDED.score, studio = EXCLUDED.studio,
                release_year = EXCLUDED.release_year, media_type = EXCLUDED.media_type,
                source_url = EXCLUDED.source_url, source_updated_at = CURRENT_TIMESTAMP
             RETURNING id'
        );
        $statement->execute([
            'mal_id' => $anime['malId'], 'title' => $anime['title'],
            'alternative_titles' => json_encode($anime['alternativeTitles'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'description' => $anime['description'], 'description_language' => $anime['descriptionLanguage'],
            'cover_url' => $anime['coverUrl'], 'episodes' => $anime['episodes'], 'score' => $anime['score'],
            'studio' => $anime['studio'], 'release_year' => $anime['year'],
            'media_type' => $anime['type'], 'source_url' => $anime['sourceUrl'],
        ]);
        $id = (int) $statement->fetchColumn();
        $delete = $this->database->prepare('DELETE FROM anime_genres WHERE anime_id = :anime_id');
        $delete->execute(['anime_id' => $id]);
        $genreStatement = $this->database->prepare(
            'INSERT INTO genres (source_id, name) VALUES (:source_id, :name)
             ON CONFLICT (source_id) DO UPDATE SET name = EXCLUDED.name RETURNING id'
        );
        $linkStatement = $this->database->prepare(
            'INSERT INTO anime_genres (anime_id, genre_id) VALUES (:anime_id, :genre_id) ON CONFLICT DO NOTHING'
        );
        foreach ($anime['genres'] as $genre) {
            $genreStatement->execute(['source_id' => $genre['sourceId'], 'name' => $genre['name']]);
            $linkStatement->execute(['anime_id' => $id, 'genre_id' => (int) $genreStatement->fetchColumn()]);
        }

        return $id;
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
