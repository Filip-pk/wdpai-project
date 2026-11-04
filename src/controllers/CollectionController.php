<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnimeRepository;
use App\Repository\CollectionRepository;
use App\Repository\UserRepository;
use App\Repository\WatchlistRepository;
use App\Service\CollectionService;
use Closure;

final class CollectionController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private CollectionRepository|Closure $collections,
        private AnimeRepository|Closure $animes,
        private WatchlistRepository|Closure $watchlists,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $this->collections()->ensureDefaultForUser($user['id']);
        $this->renderCollections($user);
    }

    public function create(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $form = $this->collectionForm();
        $errors = $this->validateForm($form);

        if ($errors === []) {
            $this->collections()->ensureDefaultForUser($user['id']);
            $id = $this->collections()->createForUser($user['id'], $form['name'], $form['description']);
            $this->redirect('/profile/collections/' . $id);
        }

        $this->renderCollections($user, collectionForm: $form, errors: $errors);
    }

    public function show(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $collection = $this->collections()->findByIdForUser($user['id'], $id);

        if ($collection === null) {
            $this->notFound();
            return;
        }

        $this->renderCollections($user, $collection);
    }

    public function update(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $collection = $this->collections()->findByIdForUser($user['id'], $id);

        if ($collection === null) {
            $this->notFound();
            return;
        }

        $form = $this->collectionForm();
        $errors = $this->validateForm($form);
        if ($errors === []) {
            if (!$this->collections()->updateForUser($user['id'], $id, $form['name'], $form['description'])) {
                $this->notFound();
                return;
            }
            $this->redirect($this->collectionUrl($id));
        }

        $this->renderCollections($user, $collection, errors: $errors, editForm: $form);
    }

    public function removeAnime(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $collection = $this->collections()->findByIdForUser($user['id'], $id);

        if ($collection === null) {
            $this->notFound();
            return;
        }

        $errors = [];
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
        } else {
            $animeId = filter_var($this->postString('animeId'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($animeId === false) {
                http_response_code(422);
                $errors[] = 'Wybierz poprawne anime do usunięcia z kolekcji.';
            } else {
                if (!$this->collections()->removeAnimeForUser($user['id'], $id, $animeId)) {
                    $this->notFound();
                    return;
                }
                $this->redirect($this->collectionUrl($id));
            }
        }

        $this->renderCollections($user, $collection, errors: $errors);
    }

    private function collectionForm(): array
    {
        return [
            'name' => trim($this->postString('name')),
            'description' => trim($this->postString('description')),
        ];
    }

    private function validateForm(array $form): array
    {
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            return ['Formularz wygasł. Odśwież stronę i spróbuj ponownie.'];
        }

        $errors = CollectionService::validate($form['name'], $form['description']);
        if (!is_string($_POST['description'] ?? '') || !is_string($_POST['name'] ?? '')) {
            $errors[] = 'Podaj nazwę i opis kolekcji jako tekst.';
        }
        if ($errors !== []) {
            http_response_code(422);
        }
        return $errors;
    }

    private function renderCollections(
        array $user,
        ?array $collection = null,
        array $collectionForm = [],
        array $errors = [],
        array $editForm = [],
    ): void {
        $page = $this->requestedPage();
        $collectionPage = $collection === null
            ? $this->collections()->findPageForUser($user['id'], $page)
            : ['collections' => [], 'page' => 1, 'totalPages' => 1, 'total' => 0];
        $result = $collection === null
            ? ['animeIds' => [], 'page' => 1, 'totalPages' => 1, 'total' => 0]
            : $this->collections()->animeIdsForUser($user['id'], $collection['id'], $page);
        $watchlist = [];
        if ($collection !== null) {
            foreach ($this->watchlists()->findByAnimeIdsForUser($user['id'], $result['animeIds']) as $entry) {
                $watchlist[$entry['animeId']] = $entry;
            }
        }

        $this->render('collections', [
            'pageTitle' => ($collection['name'] ?? 'Moje kolekcje') . ' — AniVibe',
            'collections' => $collectionPage['collections'],
            'collectionsPagination' => $collectionPage,
            'selectedCollection' => $collection,
            'collectionAnimes' => $this->animes()->findByIds($result['animeIds']),
            'collectionWatchlist' => $watchlist,
            'pagination' => $result,
            'collectionForm' => $collectionForm + ['name' => '', 'description' => ''],
            'editForm' => $editForm + [
                'name' => $collection['name'] ?? '',
                'description' => $collection['description'] ?? '',
            ],
            'errors' => $errors,
            'notice' => null,
        ]);
    }

    private function requestedPage(): int
    {
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $page === false ? 1 : $page;
    }

    private function collectionUrl(int $id): string
    {
        $page = $this->requestedPage();

        return '/profile/collections/' . $id . ($page > 1 ? '?page=' . $page : '');
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('not-found', ['pageTitle' => 'Nie znaleziono kolekcji — AniVibe']);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function collections(): CollectionRepository
    {
        if ($this->collections instanceof Closure) {
            $this->collections = ($this->collections)();
        }
        return $this->collections;
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
}
