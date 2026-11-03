<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\AnimeSearchCriteria;
use App\Repository\AnimeRepository;
use App\Repository\CollectionRepository;
use App\Repository\RatingRepository;
use App\Repository\UserRepository;
use App\Service\CollectionService;
use Closure;

final class AnimeController extends AppController
{
    public function __construct(
        private AnimeRepository|Closure $animes,
        private CollectionRepository|Closure $collections,
        private UserRepository|Closure $users,
        private RatingRepository|Closure $ratings,
    ) {
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
        $this->renderAnime($id);
    }

    public function addToCollections(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->renderAnime($id, ['Formularz wygasł. Odśwież stronę i spróbuj ponownie.']);
            return;
        }

        $ids = $_POST['collectionIds'] ?? [];
        $collectionIds = [];
        if (!is_array($ids) || $ids === [] || count($ids) > 100) {
            http_response_code(422);
            $this->renderAnime($id, ['Wybierz od 1 do 100 własnych kolekcji.']);
            return;
        }
        foreach ($ids as $value) {
            $collectionId = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]) : false;
            if ($collectionId === false) {
                http_response_code(422);
                $this->renderAnime($id, ['Wybierz poprawne kolekcje.']);
                return;
            }
            $collectionIds[] = $collectionId;
        }
        $collectionIds = array_values(array_unique($collectionIds));
        if ($this->animes()->findById($id) === null
            || !$this->collections()->addAnimeToManyForUser($user['id'], $collectionIds, $id)) {
            $this->collectionNotFound();
            return;
        }

        $this->redirect('/anime/' . $id . '?collectionSaved=1');
    }

    public function createCollection(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $form = [
            'name' => trim($this->postString('name')),
            'description' => trim($this->postString('description')),
        ];
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->renderAnime($id, ['Formularz wygasł. Odśwież stronę i spróbuj ponownie.'], $form);
            return;
        }

        $errors = CollectionService::validate($form['name'], $form['description']);
        if (!is_string($_POST['description'] ?? '') || !is_string($_POST['name'] ?? '')) {
            $errors[] = 'Podaj nazwę i opis kolekcji jako tekst.';
        }
        if ($errors !== []) {
            http_response_code(422);
            $this->renderAnime($id, $errors, $form);
            return;
        }
        if ($this->animes()->findById($id) === null) {
            $this->collectionNotFound();
            return;
        }
        $this->collections()->ensureDefaultForUser($user['id']);
        if ($this->collections()->createWithAnimeForUser($user['id'], $form['name'], $form['description'], $id) === 0) {
            $this->collectionNotFound();
            return;
        }

        $this->redirect('/anime/' . $id . '?collectionSaved=1');
    }

    public function saveRating(int $id): void
    {
        $this->changeRating($id, false);
    }

    public function removeRating(int $id): void
    {
        $this->changeRating($id, true);
    }

    private function changeRating(int $id, bool $remove): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->renderAnime($id, ratingErrors: ['Formularz wygasł. Odśwież stronę i spróbuj ponownie.']);
            return;
        }

        if ($id <= 0 || $this->animes()->findById($id) === null) {
            $this->renderAnime($id);
            return;
        }

        if ($remove) {
            $this->ratings()->removeForUser($user['id'], $id);
            $this->redirect('/anime/' . $id . '?ratingRemoved=1#anime-rating-title');
        }

        $rating = $this->postString('rating');
        if (preg_match('/\A(?:[1-9]|10)\z/', $rating) !== 1) {
            http_response_code(422);
            $this->renderAnime($id, ratingErrors: ['Wybierz ocenę od 1 do 10, bez części ułamkowej.']);
            return;
        }

        $this->ratings()->saveForUser($user['id'], $id, (int) $rating);
        $this->redirect('/anime/' . $id . '?ratingSaved=1#anime-rating-title');
    }

    private function renderAnime(
        int $id,
        array $errors = [],
        array $collectionForm = [],
        array $ratingErrors = [],
    ): void
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

        $collections = $this->animeCollections();
        $userRating = is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0
            ? $this->ratings()->findForUser($_SESSION['user_id'], $id) : null;

        $this->render('anime-details', [
            'pageTitle' => $anime['title'] . ' — AniVibe',
            'anime' => $anime,
            'animeId' => $id,
            'collections' => $collections,
            'animeCollectionIds' => is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0
                ? $this->collections()->collectionIdsForAnime($_SESSION['user_id'], $id) : [],
            'collectionErrors' => $errors,
            'collectionForm' => $collectionForm + ['name' => '', 'description' => ''],
            'selectedCollectionIds' => [],
            'collectionNotice' => !$this->isPost() && ($_GET['collectionSaved'] ?? null) === '1'
                ? 'Anime zostało dodane do wybranych kolekcji.' : null,
            'userRating' => $userRating,
            'ratingValue' => $ratingErrors === [] ? (string) ($userRating ?? '') : $this->postString('rating'),
            'ratingErrors' => $ratingErrors,
            'ratingNotice' => !$this->isPost() ? match (true) {
                ($_GET['ratingSaved'] ?? null) === '1' => 'Twoja ocena została zapisana.',
                ($_GET['ratingRemoved'] ?? null) === '1' => 'Twoja ocena została usunięta.',
                default => null,
            } : null,
        ]);
    }

    private function animeCollections(): array
    {
        if (!is_int($_SESSION['user_id'] ?? null) || $_SESSION['user_id'] <= 0) {
            return [];
        }
        $user = $this->currentUser($this->users());
        if (!$this->isPost()) {
            $this->collections()->ensureDefaultForUser($user['id']);
        }
        return $this->collections()->findForUser($user['id']);
    }

    private function collectionNotFound(): void
    {
        http_response_code(404);
        $this->render('not-found', ['pageTitle' => 'Nie znaleziono anime lub kolekcji — AniVibe']);
    }

    private function collections(): CollectionRepository
    {
        if ($this->collections instanceof Closure) {
            $this->collections = ($this->collections)();
        }
        return $this->collections;
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

    private function ratings(): RatingRepository
    {
        if ($this->ratings instanceof Closure) {
            $this->ratings = ($this->ratings)();
        }
        return $this->ratings;
    }
}
