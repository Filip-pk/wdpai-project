<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

use App\Model\AnimeRankingCriteria;
use App\Repository\AnimeRankingRepository;
use App\Repository\AnimeRepository;
use Closure;

final class AnimeRankingController extends AppController
{
    public function __construct(
        private AnimeRepository|Closure $animes,
        private AnimeRankingRepository|Closure $rankings,
    ) {
    }

    public function index(): void
    {
        $criteria = AnimeRankingCriteria::fromQuery($_GET);
        if ($criteria->errors !== []) {
            http_response_code(400);
            $ranking = ['entries' => [], 'page' => 1, 'total' => 0, 'totalPages' => 1];
        } else {
            $ranking = $this->rankings()->findPage($criteria);
        }
        $animes = $this->animes()->findByIds(array_column($ranking['entries'], 'id'));
        $communityRatings = [];
        foreach ($ranking['entries'] as $entry) {
            $communityRatings[$entry['id']] = ['average' => $entry['communityAverage'], 'count' => $entry['communityCount']];
        }
        $parameters = $criteria->parameters();
        $url = static fn (int $page): string => '/anime/ranking?' . http_build_query(
            [...$parameters, 'page' => $page], '', '&', PHP_QUERY_RFC3986,
        );
        $modeUrls = [];
        foreach (AnimeRankingCriteria::MODES as $mode => $label) {
            $modeUrls[$mode] = '/anime/ranking?' . http_build_query(
                [...$parameters, 'mode' => $mode], '', '&', PHP_QUERY_RFC3986,
            );
        }
        $this->render('anime-ranking', [
            'pageTitle' => Translator::current()->translate('message.page.anime_rankings'),
            'criteria' => $criteria, 'ranking' => $ranking,
            'animes' => $animes, 'communityRatings' => $communityRatings,
            'genres' => $this->animes()->genres(), 'modeUrls' => $modeUrls,
            'previousUrl' => $ranking['page'] > 1 ? $url($ranking['page'] - 1) : null,
            'nextUrl' => $ranking['page'] < $ranking['totalPages'] ? $url($ranking['page'] + 1) : null,
        ]);
    }

    private function animes(): AnimeRepository
    {
        if ($this->animes instanceof Closure) {
            $this->animes = ($this->animes)();
        }
        return $this->animes;
    }

    private function rankings(): AnimeRankingRepository
    {
        if ($this->rankings instanceof Closure) {
            $this->rankings = ($this->rankings)();
        }
        return $this->rankings;
    }
}
