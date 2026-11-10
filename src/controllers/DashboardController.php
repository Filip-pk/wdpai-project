<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;
use App\Repository\AnimeRepository;
use App\Repository\RatingRepository;
use App\Repository\UserRepository;
use App\Service\RecommendationService;
use Closure;

final class DashboardController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private AnimeRepository|Closure $animes,
        private RatingRepository|Closure $ratings,
        private RecommendationService|Closure $recommendationService,
    ) {
    }

    public function index(): void
    {
        if ($this->animes instanceof Closure) {
            $this->animes = ($this->animes)();
        }
        $user = null;

        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            if ($this->users instanceof Closure) {
                $this->users = ($this->users)();
            }
            $user = $this->currentUser($this->users);
        }

        $recommendationReasons = [];
        $preferredGenres = [];
        $sourceCount = 0;
        $mode = 'guest';
        if ($user === null) {
            $animes = $this->animes->search(perPage: 6)['animes'];
        } else {
            if ($this->recommendationService instanceof Closure) {
                $this->recommendationService = ($this->recommendationService)();
            }
            $result = $this->recommendationService->recommendForUser($user['id']);
            $mode = $result['mode'];
            $sourceCount = $result['sourceCount'];
            $found = $this->animes->findByIds(array_column($result['entries'], 'animeId'));
            $genres = array_column($this->animes->genres(), null, 'id');
            $animes = [];
            foreach ($result['entries'] as $entry) {
                $id = $entry['animeId'];
                if (!isset($found[$id])) {
                    continue;
                }
                $animes[$id] = $found[$id];
                $reason = $entry['reason'] ?? null;
                $genreNames = [];
                foreach ($reason['sharedGenreIds'] ?? $entry['matchedGenreIds'] as $genreId) {
                    if (isset($genres[$genreId])) {
                        $genreNames[] = $genres[$genreId]['name'];
                    }
                }
                $recommendationReasons[$id] = [
                    'seed' => $reason === null ? null : [
                        'animeId' => $reason['animeId'],
                        'title' => $reason['title'],
                        'rating' => $reason['rating'],
                        'status' => $reason['status'],
                        'sharedStudio' => $reason['sharedStudio'],
                    ],
                    'genres' => array_slice($genreNames, 0, 2),
                ];
            }
            foreach ($result['preferredGenreIds'] as $genreId) {
                if (isset($genres[$genreId])) {
                    $preferredGenres[] = $genres[$genreId];
                }
            }
        }
        if ($this->ratings instanceof Closure) {
            $this->ratings = ($this->ratings)();
        }

        $this->render('dashboard', [
            'pageTitle' => 'Twój pulpit — AniVibe',
            'user' => $user,
            'personas' => DemoData::personas(),
            'recommendations' => $animes,
            'recommendationReasons' => $recommendationReasons,
            'preferredGenres' => $preferredGenres,
            'recommendationMode' => $mode,
            'recommendationSourceCount' => $sourceCount,
            'communityRatings' => $this->ratings->communityForAnimeIds(array_keys($animes)),
        ]);
    }
}
