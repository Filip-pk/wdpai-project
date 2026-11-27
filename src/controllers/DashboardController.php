<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

use App\Repository\AnimeRepository;
use App\Repository\RatingRepository;
use App\Repository\UserRepository;
use App\Service\RecommendationService;
use App\Service\QuizService;
use Closure;

final class DashboardController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private AnimeRepository|Closure $animes,
        private RatingRepository|Closure $ratings,
        private RecommendationService|Closure $recommendationService,
        private QuizService|Closure $quizService,
    ) {
    }

    public function index(): void
    {
        self::resolveDependency($this->animes);
        $user = null;

        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            self::resolveDependency($this->users);
            $user = $this->currentUser($this->users);
        }

        $recommendationReasons = [];
        $preferredGenres = [];
        $sourceCount = 0;
        $mode = 'guest';
        if ($user === null) {
            $animes = $this->animes->search(perPage: 6)['animes'];
        } else {
            self::resolveDependency($this->recommendationService);
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
        self::resolveDependency($this->ratings);

        $this->render('dashboard', [
            'pageTitle' => Translator::current()->translate('message.page.dashboard'),
            'user' => $user,
            'recommendations' => $animes,
            'recommendationReasons' => $recommendationReasons,
            'preferredGenres' => $preferredGenres,
            'recommendationMode' => $mode,
            'recommendationSourceCount' => $sourceCount,
            'communityRatings' => $this->ratings->communityForAnimeIds(array_keys($animes)),
            'quizStats' => $user === null ? null : $this->quizService()->statistics($user['id']),
        ]);
    }

    private function quizService(): QuizService
    {
        return self::resolveDependency($this->quizService);
    }
}
