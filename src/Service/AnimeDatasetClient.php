<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;
use RuntimeException;

final class AnimeDatasetClient
{
    public const SOURCE_URL = 'https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime';
    private const BASE_URL = 'https://datasets-server.huggingface.co';
    private const DATASET = 'TheRealVigilante/Processed_Top_15k_Anime';
    private const PAGE_SIZE = 100;
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;
    private const GENRES = [
        'Action' => 1,
        'Adventure' => 2,
        'Avant Garde' => 5,
        'Award Winning' => 46,
        'Boys Love' => 28,
        'Comedy' => 4,
        'Drama' => 8,
        'Ecchi' => 9,
        'Erotica' => 49,
        'Fantasy' => 10,
        'Girls Love' => 26,
        'Gourmet' => 47,
        'Hentai' => 12,
        'Horror' => 14,
        'Mystery' => 7,
        'Romance' => 22,
        'Sci-Fi' => 24,
        'Slice of Life' => 36,
        'Sports' => 30,
        'Supernatural' => 37,
        'Suspense' => 41,
    ];

    private float $lastRequestAt = 0.0;

    /** @return array{data: list<array>, pagination: array{has_next_page: bool}} */
    public function page(int $page): array
    {
        if ($page < 1 || $page > 100000) {
            throw new RuntimeException('Nieprawidłowa strona katalogu źródłowego.');
        }

        $offset = ($page - 1) * self::PAGE_SIZE;
        $payload = $this->request('/rows', [
            'offset' => $offset,
            'length' => self::PAGE_SIZE,
        ]);
        $data = $this->rows($payload);
        $total = $this->integer($payload['num_rows_total'] ?? null, true);

        if ($total === null) {
            throw new RuntimeException('Źródło nie podało poprawnej liczby anime.');
        }

        return [
            'data' => $data,
            'pagination' => ['has_next_page' => $offset + count($data) < $total],
        ];
    }

    /** @return array{data: array} */
    public function anime(int $malId): array
    {
        if ($malId < 1 || $malId > 2147483647) {
            throw new RuntimeException('Nieprawidłowy identyfikator anime.');
        }

        $data = $this->rows($this->request('/filter', [
            'where' => '"anime_id"=' . $malId,
            'length' => 1,
        ]));

        if (count($data) !== 1 || $data[0]['mal_id'] !== $malId) {
            throw new RuntimeException('W zbiorze źródłowym nie znaleziono wymaganego anime MAL ' . $malId . '.');
        }

        return ['data' => $data[0]];
    }

    private function rows(array $payload): array
    {
        if (!is_array($payload['rows'] ?? null) || !array_is_list($payload['rows'])
            || count($payload['rows']) > self::PAGE_SIZE) {
            throw new RuntimeException('Źródłowy zbiór zwrócił nieprawidłową listę anime.');
        }

        $data = [];
        foreach ($payload['rows'] as $entry) {
            if (!is_array($entry) || !is_array($entry['row'] ?? null)
                || !is_array($entry['truncated_cells'] ?? null) || $entry['truncated_cells'] !== []) {
                throw new RuntimeException('Źródłowy rekord anime jest niekompletny.');
            }

            $data[] = $this->normalize($entry['row']);
        }

        return $data;
    }

    public function normalize(array $row): array
    {
        $malId = $this->integer($row['anime_id'] ?? null);
        $title = $this->text($row['name'] ?? null);
        if ($malId === null || $title === null) {
            throw new RuntimeException('Anime musi mieć poprawny identyfikator MAL i tytuł.');
        }

        $titles = [['title' => $title]];
        foreach (['english_name', 'japanese_names'] as $field) {
            $alternative = $this->text($row[$field] ?? null);
            if ($alternative !== null && $alternative !== $title) {
                $titles[] = ['title' => $alternative];
            }
        }

        $genres = [];
        $genreText = $this->text($row['genres'] ?? null);
        foreach ($genreText === null ? [] : explode(',', $genreText) as $value) {
            $name = $this->text($value);
            if ($name === null) {
                continue;
            }

            $matched = false;
            foreach (self::GENRES as $canonicalName => $id) {
                if (strcasecmp($name, $canonicalName) === 0) {
                    $genres[$id] = ['mal_id' => $id, 'name' => $canonicalName];
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new RuntimeException('Nieznany gatunek w zbiorze źródłowym: ' . $name . '.');
            }
        }

        $studios = [];
        $studioText = $this->text($row['studios'] ?? null);
        foreach ($studioText === null ? [] : explode(',', $studioText) as $value) {
            $name = $this->text($value);
            if ($name !== null) {
                $studios[] = ['name' => $name];
            }
        }

        $premiered = $this->text($row['premiered'] ?? null);
        $year = $premiered !== null && preg_match('/\b(\d{4})\b/', $premiered, $match) === 1
            ? (int) $match[1]
            : null;

        return [
            'mal_id' => $malId,
            'title' => $title,
            'titles' => $titles,
            'synopsis' => $this->text($row['synopsis'] ?? null),
            'images' => ['jpg' => ['large_image_url' => $this->text($row['image_url'] ?? null)]],
            'episodes' => $this->integer($row['episodes'] ?? null),
            'score' => $this->score($row['score'] ?? null),
            'studios' => $studios,
            'year' => $year,
            'type' => $this->text($row['type'] ?? null) ?? 'UNKNOWN',
            'genres' => array_values($genres),
            'themes' => [],
        ];
    }

    private function integer(mixed $value, bool $allowZero = false): ?int
    {
        if (is_string($value)) {
            $value = $this->text($value);
            if ($value === null || !is_numeric($value)) {
                return null;
            }
            $value = (float) $value;
        }

        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
            || $value < ($allowZero ? 0 : 1) || $value > 2147483647
            || floor((float) $value) !== (float) $value) {
            return null;
        }

        return (int) $value;
    }

    private function score(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = $this->text($value);
            if ($value === null || !is_numeric($value)) {
                return null;
            }
            $value = (float) $value;
        }

        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && $value >= 0 && $value <= 10 ? (float) $value : null;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            throw new RuntimeException('Nieprawidłowy tekst w źródłowym zbiorze anime.');
        }

        $value = trim((string) preg_replace('/\h+/u', ' ', $value));

        return $value === '' || in_array(strtolower($value), ['unknown', 'n/a'], true)
            ? null
            : $value;
    }

    private function request(string $path, array $query): array
    {
        $url = self::BASE_URL . $path . '?' . http_build_query(array_merge([
            'dataset' => self::DATASET,
            'config' => 'default',
            'split' => 'train',
        ], $query), '', '&', PHP_QUERY_RFC3986);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $wait = 1.1 - (microtime(true) - $this->lastRequestAt);
            if ($wait > 0) {
                usleep((int) ceil($wait * 1000000));
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 20,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'header' => "Accept: application/json\r\nUser-Agent: AniVibe-Catalog-Importer/1.0\r\n",
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);

            $this->lastRequestAt = microtime(true);
            $http_response_header = [];
            $body = @file_get_contents($url, false, $context, 0, self::MAX_RESPONSE_BYTES + 1);
            $status = 0;
            foreach ($http_response_header as $header) {
                if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $header, $match) === 1) {
                    $status = (int) $match[1];
                }
            }

            if ($body !== false && $status === 200) {
                if (strlen($body) > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Odpowiedź źródła przekracza dopuszczalny rozmiar.');
                }

                try {
                    $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new RuntimeException('Źródłowy zbiór zwrócił nieprawidłowe dane JSON.');
                }
                if (!is_array($payload)) {
                    throw new RuntimeException('Źródłowy zbiór zwrócił nieprawidłową strukturę odpowiedzi.');
                }

                return $payload;
            }

            if (($status !== 0 && $status !== 429 && $status < 500) || $attempt === 3) {
                throw new RuntimeException($status > 0
                    ? 'Nie udało się pobrać zbioru anime (HTTP ' . $status . ').'
                    : 'Nie udało się połączyć ze źródłem anime. Możesz użyć wcześniej pobranego pliku --file.');
            }

            $backoff = min(8, 2 ** ($attempt + 1));
            foreach ($http_response_header as $header) {
                if (preg_match('/\ARetry-After:\s*(\d+)\s*\z/i', $header, $match) === 1) {
                    $backoff = max($backoff, min(15, (int) $match[1]));
                }
            }
            usleep($backoff * 1000000);
        }

        throw new RuntimeException('Nie udało się pobrać katalogu anime.');
    }
}
