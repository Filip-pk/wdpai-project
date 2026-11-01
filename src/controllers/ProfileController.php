<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;
use App\Repository\UserRepository;
use App\Repository\WatchlistRepository;
use App\Service\ProfileService;
use Closure;

final class ProfileController extends AppController
{
    private const STATUSES = [
        'planned' => 'Planowane',
        'watching' => 'Oglądam',
        'completed' => 'Ukończone',
    ];

    public function __construct(
        private UserRepository|Closure $users,
        private WatchlistRepository|Closure $watchlists,
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
            $animes = DemoData::animes();

            if ($animeId === false || !isset($animes[$animeId])) {
                $errors[] = 'Wybierz anime z katalogu.';
            } elseif ($remove) {
                $this->watchlists()->removeForUser($user['id'], $animeId);
            } elseif ($this->postString('mode') === 'add') {
                $this->watchlists()->addForUser($user['id'], $animeId);
            } else {
                $status = $this->postString('status');
                $watched = filter_var($this->postString('watched'), FILTER_VALIDATE_INT);

                if (!isset(self::STATUSES[$status])) {
                    $errors[] = 'Wybierz poprawny status oglądania.';
                }
                if ($watched === false || $watched < 0 || $watched > $animes[$animeId]['episodes']) {
                    $errors[] = 'Liczba odcinków musi mieścić się w zakresie wybranej serii.';
                }
                if ($errors === []) {
                    $watched = $status === 'completed' ? $animes[$animeId]['episodes'] : $watched;
                    $watched = $status === 'planned' ? 0 : $watched;
                    $this->watchlists()->saveForUser($user['id'], $animeId, $status, $watched);
                }
            }

            if ($errors === []) {
                $this->redirect('/watchlist');
            }
            http_response_code(422);
        }

        $this->renderProfile(true, $user, $errors);
    }

    private function renderProfile(bool $watchlistOnly, array $user, array $errors = [], array $form = []): void
    {
        $watchlist = $this->watchlists()->findForUser($user['id']);
        $animes = DemoData::animes();
        $watchlist = array_values(array_filter(
            $watchlist, static fn (array $entry): bool => isset($animes[$entry['animeId']]),
        ));
        $statusFilter = is_string($_GET['status'] ?? null) && isset(self::STATUSES[$_GET['status']])
            ? $_GET['status'] : '';

        $this->render('profile', [
            'pageTitle' => ($watchlistOnly ? 'Watchlista' : 'Twój profil') . ' — AniVibe',
            'watchlistOnly' => $watchlistOnly,
            'user' => $user,
            'form' => $form,
            'errors' => $errors,
            'saved' => !$this->isPost() && ($_GET['saved'] ?? null) === '1',
            'animes' => $animes,
            'watchlist' => $watchlist,
            'statusFilter' => $statusFilter,
            'visibleWatchlist' => $statusFilter === '' ? $watchlist : array_values(array_filter(
                $watchlist, static fn (array $entry): bool => $entry['status'] === $statusFilter,
            )),
            'watchlistStatuses' => self::STATUSES,
            'completedCount' => count(array_filter(
                $watchlist, static fn (array $entry): bool => $entry['status'] === 'completed',
            )),
        ]);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }

        return $this->users;
    }

    private function watchlists(): WatchlistRepository
    {
        if ($this->watchlists instanceof Closure) {
            $this->watchlists = ($this->watchlists)();
        }

        return $this->watchlists;
    }
}
