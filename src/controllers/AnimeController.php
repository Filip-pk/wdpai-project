<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\AnimeSearchCriteria;
use App\Repository\AnimeRepository;
use Closure;

final class AnimeController extends AppController
{
    public function __construct(private AnimeRepository|Closure $animes)
    {
    }

    public function index(): void
    {
        $criteria = AnimeSearchCriteria::fromQuery($_GET);
        $searchError = $criteria->errors === [] ? null : implode(' ', $criteria->errors);
        if ($searchError !== null) {
            http_response_code(400);
        }

        $result = $this->animes()->search(
            $criteria->query, $criteria->genreId, $criteria->page, 20, $criteria->filters, $criteria->sort,
        );
        $page = $result['page'];
        $totalPages = $result['totalPages'];
        $parameters = $criteria->parameters();
        $url = static fn (int $number): string => '/anime?' . http_build_query(
            [...$parameters, 'page' => $number], '', '&', PHP_QUERY_RFC3986,
        );
        $numbers = array_unique(array_merge([1, $totalPages], range(max(1, $page - 2), min($totalPages, $page + 2))));
        sort($numbers);
        $pagination = array_map(static fn (int $number): array => [
            'page' => $number, 'url' => $url($number), 'current' => $number === $page,
        ], $numbers);

        $this->render('anime-list', [
            'pageTitle' => 'Odkrywaj anime — AniVibe',
            'animes' => $result['animes'],
            'query' => $criteria->query,
            'genres' => $this->animes()->genres(),
            'genreId' => $criteria->genreId,
            'filters' => $criteria->form,
            'sort' => $criteria->sort,
            'sortOptions' => AnimeSearchCriteria::SORT_OPTIONS,
            'typeOptions' => AnimeSearchCriteria::TYPE_OPTIONS,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $result['total'],
            'catalogTotal' => $this->animes()->count(),
            'pagination' => $pagination,
            'previousUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $totalPages ? $url($page + 1) : null,
            'searchError' => $searchError,
        ]);
    }

    public function show(int $id): void
    {
        $anime = $id > 0 ? $this->animes()->findById($id) : null;

        if ($anime === null) {
            http_response_code(404);

            $this->render('not-found', [
                'pageTitle' => 'Nie znaleziono anime — AniVibe',
            ]);

            return;
        }

        $anime['sourceLabel'] = match (parse_url($anime['sourceUrl'] ?? '', PHP_URL_HOST)) {
            'myanimelist.net' => 'MyAnimeList',
            'anilist.co' => 'AniList',
            'anidb.net' => 'AniDB',
            'kitsu.app', 'kitsu.io' => 'Kitsu',
            'anime-planet.com' => 'Anime-Planet',
            'anisearch.com' => 'aniSearch',
            'simkl.com' => 'SIMKL',
            'livechart.me' => 'LiveChart',
            'animenewsnetwork.com' => 'Anime News Network',
            default => 'Strona źródłowa',
        };

        $this->render('anime-details', [
            'pageTitle' => $anime['title'] . ' — AniVibe',
            'anime' => $anime,
            'animeId' => $id,
        ]);
    }

    private function animes(): AnimeRepository
    {
        if ($this->animes instanceof Closure) {
            $this->animes = ($this->animes)();
        }

        return $this->animes;
    }
}
