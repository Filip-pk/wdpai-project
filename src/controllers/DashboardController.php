<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;
use App\Repository\AnimeRepository;
use App\Repository\RatingRepository;
use App\Repository\UserRepository;
use Closure;

final class DashboardController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private AnimeRepository|Closure $animes,
        private RatingRepository|Closure $ratings,
    ) {
    }

    public function index(): void
    {
        if ($this->animes instanceof Closure) {
            $this->animes = ($this->animes)();
        }
        $animes = $this->animes->findByIds([3, 5]);
        if ($this->ratings instanceof Closure) {
            $this->ratings = ($this->ratings)();
        }
        $user = null;

        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            if ($this->users instanceof Closure) {
                $this->users = ($this->users)();
            }
            $user = $this->currentUser($this->users);
        }

        $this->render('dashboard', [
            'pageTitle' => 'Twój pulpit — AniVibe',
            'user' => $user,
            'personas' => DemoData::personas(),
            'recommendations' => $animes,
            'communityRatings' => $this->ratings->communityForAnimeIds(array_keys($animes)),
        ]);
    }
}
