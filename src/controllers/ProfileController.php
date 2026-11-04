<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnimeRepository;
use App\Repository\ExperienceRepository;
use App\Repository\RatingRepository;
use App\Repository\UserRepository;
use App\Repository\WatchlistRepository;
use App\Service\AvatarService;
use App\Service\ExperienceService;
use App\Service\ProfileService;
use Closure;

final class ProfileController extends AppController
{
    private const STATUSES = [
        'planned' => 'Planowane',
        'watching' => 'Oglądam',
        'completed' => 'Ukończone',
        'on_hold' => 'Wstrzymane',
        'dropped' => 'Porzucone',
    ];

    public function __construct(
        private UserRepository|Closure $users,
        private WatchlistRepository|Closure $watchlists,
        private AnimeRepository|Closure $animes,
        private RatingRepository|Closure $ratings,
        private ExperienceRepository|Closure $experience,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $errors = [];
        $form = $user;

        if ($this->isPost()) {
            $form = [
                'name' => trim($this->postString('name')),
                'email' => strtolower(trim($this->postString('email'))),
                'interests' => trim($this->postString('interests')),
                'theme' => $this->postString('theme'),
                'currentPassword' => $this->postString('currentPassword'),
            ];

            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
            } else {
                $errors = (new ProfileService($this->users()))->update($user, $form);

                if ($errors === []) {
                    $this->currentUser($this->users());
                    $this->redirect('/profile?saved=1');
                }

                http_response_code(422);
            }
        }

        $this->renderProfile(false, $user, $errors, $form);
    }

    public function theme(): void
    {
        $this->requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            echo json_encode(['error' => 'Formularz wygasł. Odśwież stronę.']);
            return;
        }

        $theme = $this->postString('theme');
        if (!in_array($theme, ProfileService::THEMES, true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Nieprawidłowy motyw.']);
            return;
        }

        $user = $this->currentUser($this->users());
        $this->users()->updateTheme($user['id'], $theme);
        $_SESSION['user_theme'] = $theme;
        echo json_encode(['theme' => $theme]);
    }

    public function uploadAvatar(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());

        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $errors = ['Formularz wygasł. Odśwież stronę i spróbuj ponownie.'];
        } else {
            $upload = is_array($_FILES['avatar'] ?? null) ? $_FILES['avatar'] : [];
            $errors = (new AvatarService($this->users()))->upload($user['id'], $upload);

            if ($errors === []) {
                $this->currentUser($this->users());
                $this->redirect('/profile?avatarSaved=1');
            }

            http_response_code(422);
        }

        $this->renderProfile(false, $user, $errors, $user);
    }

    public function avatar(int $id): void
    {
        $version = is_string($_GET['v'] ?? null) ? $_GET['v'] : '';
        $contents = $id > 0 && preg_match('/\A[a-f0-9]{32}\z/', $version) === 1
            ? $this->users()->findAvatar($id, $version)
            : null;

        if ($contents === null) {
            http_response_code(404);
            return;
        }

        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($contents));
        echo $contents;
    }

    public function watchlist(): void
    {
        $this->requireLogin();
        $this->renderProfile(true, $this->currentUser($this->users()));
    }

    public function saveWatchlist(): void
    {
        $this->changeWatchlist(false);
    }

    public function removeWatchlist(): void
    {
        $this->changeWatchlist(true);
    }

    private function changeWatchlist(bool $remove): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $errors = [];

        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
        } else {
            $animeId = filter_var($this->postString('animeId'), FILTER_VALIDATE_INT);
            $anime = $animeId === false ? null : $this->animes()->findById($animeId);

            if ($anime === null) {
                $errors[] = 'Wybierz anime z katalogu.';
            } elseif ($remove) {
                $this->watchlists()->removeForUser($user['id'], $animeId);
            } elseif ($this->postString('mode') === 'add') {
                $this->watchlists()->addForUser($user['id'], $animeId);
            } else {
                $status = $this->postString('status');
                $watched = filter_var($this->postString('watched'), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 2147483647],
                ]);
                $episodes = $anime['episodes'] !== null && $anime['episodes'] > 0 ? $anime['episodes'] : null;

                if (!isset(self::STATUSES[$status])) {
                    $errors[] = 'Wybierz poprawny status oglądania.';
                }
                if ($watched === false || $watched < 0 || ($episodes !== null && $watched > $episodes)) {
                    $errors[] = 'Liczba odcinków musi mieścić się w zakresie wybranej serii.';
                }
                if ($errors === []) {
                    $watched = $status === 'completed' && $episodes !== null ? $episodes : $watched;
                    $watched = $status === 'planned' ? 0 : $watched;
                    $this->watchlists()->saveForUser($user['id'], $animeId, $status, $watched);
                }
            }

            if ($errors === []) {
                $page = filter_var($this->postString('listPage'), FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                $status = $this->postString('listStatus');
                $this->redirect($this->watchlistPath(
                    $this->postString('listView') !== 'profile',
                    $page === false ? 1 : $page,
                    isset(self::STATUSES[$status]) ? $status : '',
                ));
            }
            http_response_code(422);
        }

        $this->renderProfile($this->postString('listView') !== 'profile', $user, $errors, $user);
    }

    private function renderProfile(bool $watchlistOnly, array $user, array $errors = [], array $form = []): void
    {
        $requestedStatus = $_POST['listStatus'] ?? $_GET['status'] ?? '';
        $statusFilter = is_string($requestedStatus) && isset(self::STATUSES[$requestedStatus])
            ? $requestedStatus : '';
        $page = filter_var($_POST['listPage'] ?? $_GET['page'] ?? '1', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $result = $this->watchlists()->findPageForUser($user['id'], $page === false ? 1 : $page, status: $statusFilter);
        $animeIds = array_column($result['entries'], 'animeId');

        $this->render('profile', [
            'pageTitle' => ($watchlistOnly ? 'Watchlista' : 'Twój profil') . ' — AniVibe',
            'watchlistOnly' => $watchlistOnly,
            'user' => $user,
            'form' => $form,
            'errors' => $errors,
            'saved' => !$this->isPost() && ($_GET['saved'] ?? null) === '1',
            'avatarSaved' => !$this->isPost() && ($_GET['avatarSaved'] ?? null) === '1',
            'animes' => $this->animes()->findByIds($animeIds),
            'watchlistTotal' => $result['watchlistTotal'],
            'watchlistPagination' => $result + [
                'previousUrl' => $this->watchlistPath($watchlistOnly, $result['page'] - 1, $statusFilter),
                'nextUrl' => $this->watchlistPath($watchlistOnly, $result['page'] + 1, $statusFilter),
            ],
            'userRatings' => $this->ratings()->findByAnimeIdsForUser($user['id'], $animeIds),
            'experience' => ExperienceService::progress($this->experience()->totalForUser($user['id'])),
            'statusFilter' => $statusFilter,
            'visibleWatchlist' => $result['entries'],
            'watchlistStatuses' => self::STATUSES,
            'completedCount' => $result['completedCount'],
        ]);
    }

    private function watchlistPath(bool $watchlistOnly, int $page, string $status): string
    {
        $parameters = [];
        if ($status !== '') {
            $parameters['status'] = $status;
        }
        if ($page > 1) {
            $parameters['page'] = $page;
        }

        return ($watchlistOnly ? '/watchlist' : '/profile')
            . ($parameters === [] ? '' : '?' . http_build_query($parameters));
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }

        return $this->users;
    }

    private function animes(): AnimeRepository
    {
        if ($this->animes instanceof Closure) {
            $this->animes = ($this->animes)();
        }

        return $this->animes;
    }

    private function watchlists(): WatchlistRepository
    {
        if ($this->watchlists instanceof Closure) {
            $this->watchlists = ($this->watchlists)();
        }

        return $this->watchlists;
    }

    private function ratings(): RatingRepository
    {
        if ($this->ratings instanceof Closure) {
            $this->ratings = ($this->ratings)();
        }
        return $this->ratings;
    }

    private function experience(): ExperienceRepository
    {
        if ($this->experience instanceof Closure) {
            $this->experience = ($this->experience)();
        }
        return $this->experience;
    }
}
