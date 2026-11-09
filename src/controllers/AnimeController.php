<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\AnimeSearchCriteria;
use App\Repository\AnimeRepository;
use App\Repository\CollectionRepository;
use App\Repository\CommentRepository;
use App\Repository\CommunityRepository;
use App\Repository\RatingRepository;
use App\Repository\ReplyNotificationRepository;
use App\Repository\UserRepository;
use App\Service\CollectionService;
use App\Service\CommentService;
use App\Service\CommunityService;
use App\Service\RateLimitException;
use Closure;

final class AnimeController extends AppController
{
    public function __construct(
        private AnimeRepository|Closure $animes,
        private CollectionRepository|Closure $collections,
        private UserRepository|Closure $users,
        private RatingRepository|Closure $ratings,
        private CommentRepository|Closure $comments,
        private CommunityRepository|Closure $community,
        private ReplyNotificationRepository|Closure $replyNotifications,
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

    public function createComment(int $id): void
    {
        $this->changeComment($id, 'create');
    }

    public function showCommentThread(int $id, int $commentId): void
    {
        $this->renderCommentThread($id, $commentId);
    }

    public function replyComment(int $id, int $commentId): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $parent = $this->comments()->findByIdForAnime($id, $commentId);
        if ($parent === null || $parent['parentId'] !== null || $parent['isRemoved'] || $parent['isDeleted']) {
            $this->commentNotFound();
            return;
        }
        $body = trim(str_replace(["\r\n", "\r"], "\n", $this->postString('body')), " \t\n\r");
        $errors = [];
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
        } else {
            $errors = CommentService::validate($body);
            if ($errors !== []) {
                http_response_code(422);
            }
        }
        if ($errors === []) {
            try {
                $replyId = (new CommunityService($this->community()))->runLimited(
                    $user['id'], 'comment', $body,
                    function () use ($user, $id, $commentId, $body): ?int {
                        $replyId = $this->comments()->createReplyForUser($user['id'], $id, $commentId, $body);
                        if ($replyId !== null) {
                            $this->replyNotifications()->createForReply($replyId);
                        }
                        return $replyId;
                    },
                    $parent['userId'],
                );
                if ($replyId === null) {
                    $this->commentNotFound();
                    return;
                }
                $savedReplies = $this->comments()->findRepliesPageForAnime($id, $commentId, PHP_INT_MAX);
                if ($savedReplies === null) {
                    $this->redirect($this->commentsPath($id, 1));
                }
                $lastPage = $savedReplies['page'];
                $this->redirect($this->threadPath($id, $commentId, $lastPage, 'replySaved', 'comment-' . $replyId));
            } catch (RateLimitException $exception) {
                http_response_code(429);
                header('Retry-After: ' . $exception->retryAfter);
                $errors[] = $exception->getMessage();
            }
        }
        $this->renderCommentThread($id, $commentId, $errors, $body);
    }

    public function updateComment(int $id, int $commentId): void
    {
        $this->changeComment($id, 'edit', $commentId);
    }

    public function deleteComment(int $id, int $commentId): void
    {
        $this->changeComment($id, 'delete', $commentId);
    }

    private function changeComment(int $id, string $action, ?int $commentId = null): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());

        if ($id <= 0 || $this->animes()->findById($id) === null) {
            $this->renderAnime($id);
            return;
        }

        $comment = $commentId === null ? null : $this->comments()->findByIdForUser($user['id'], $id, $commentId);
        if ($action !== 'create' && ($comment === null || $comment['isDeleted'] || ($action === 'edit' && $comment['isRemoved']))) {
            $this->commentNotFound();
            return;
        }

        $page = $this->commentPage($_POST['commentsPage'] ?? '1');
        $threadId = $comment['parentId'] ?? (
            $comment !== null && $this->postString('returnThread') === (string) $comment['id'] ? $comment['id'] : null
        );
        $body = trim(str_replace(["\r\n", "\r"], "\n", $this->postString('body')), " \t\n\r");
        $errors = [];
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
        } elseif ($action !== 'delete') {
            $errors = CommentService::validate($body);
            if ($errors !== []) {
                http_response_code(422);
            }
        }

        if ($errors !== []) {
            if ($threadId !== null) {
                $this->renderCommentThread($id, $threadId, $errors, $body, $action === 'edit' ? $comment : null);
                return;
            }
            $this->renderAnime(
                $id,
                commentErrors: $errors,
                commentBody: $body,
                editingComment: $action === 'edit' ? $comment : null,
                commentsPage: $page,
            );
            return;
        }

        try {
            if ($action === 'create') {
                (new CommunityService($this->community()))->runLimited(
                    $user['id'], 'comment', $body,
                    fn (): int => $this->comments()->createForUser($user['id'], $id, $body),
                );
                $this->redirect($this->commentsPath($id, 1, 'commentSaved'));
            }
            $changed = $action === 'edit'
                ? (new CommunityService($this->community()))->runLimited(
                    $user['id'], 'comment', $body,
                    fn (): bool => $this->comments()->updateForUser($user['id'], $id, $commentId, $body),
                )
                : $this->comments()->removeForUser($user['id'], $id, $commentId);
        } catch (RateLimitException $exception) {
            http_response_code(429);
            header('Retry-After: ' . $exception->retryAfter);
            if ($threadId !== null) {
                $this->renderCommentThread($id, $threadId, [$exception->getMessage()], $body, $action === 'edit' ? $comment : null);
                return;
            }
            $this->renderAnime(
                $id, commentErrors: [$exception->getMessage()], commentBody: $body,
                editingComment: $action === 'edit' ? $comment : null, commentsPage: $page,
            );
            return;
        }
        if (!$changed) {
            $this->commentNotFound();
            return;
        }

        if ($threadId !== null && $this->comments()->findByIdForAnime($id, $threadId) !== null) {
            $savedReplies = $this->comments()->findRepliesPageForAnime($id, $threadId, $this->commentPage($_POST['repliesPage'] ?? '1'));
            if ($savedReplies !== null) {
                $this->redirect($this->threadPath($id, $threadId, $savedReplies['page'], $action === 'edit' ? 'commentUpdated' : 'commentRemoved'));
            }
        }

        $page = $this->comments()->findPageForAnime($id, $page)['page'];
        $this->redirect($this->commentsPath($id, $page, $action === 'edit' ? 'commentUpdated' : 'commentRemoved'));
    }

    private function renderCommentThread(
        int $animeId,
        int $rootId,
        array $errors = [],
        string $body = '',
        ?array $editingComment = null,
    ): void {
        $anime = $this->animes()->findById($animeId);
        $thread = $this->comments()->findByIdForAnime($animeId, $rootId);
        if ($anime === null || $thread === null || $thread['parentId'] !== null) {
            $this->commentNotFound();
            return;
        }
        $replies = $this->comments()->findRepliesPageForAnime(
            $animeId, $rootId, $this->commentPage($_POST['repliesPage'] ?? $_GET['repliesPage'] ?? '1'),
        );
        if ($replies === null) {
            $this->commentNotFound();
            return;
        }
        if (!$this->isPost() && array_key_exists('editComment', $_GET)) {
            $this->requireLogin();
            $user = $this->currentUser($this->users());
            $editingId = filter_var($_GET['editComment'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $editingComment = $editingId === false ? null : $this->comments()->findByIdForUser($user['id'], $animeId, $editingId);
            if ($editingComment === null || $editingComment['isRemoved'] || $editingComment['isDeleted']
                || ($editingComment['id'] !== $rootId && $editingComment['parentId'] !== $rootId)) {
                $this->commentNotFound();
                return;
            }
            $body = $editingComment['body'];
        }
        $mainPage = $this->commentPage($_POST['commentsPage'] ?? $_GET['commentsPage'] ?? '1');
        $this->render('comment-thread', [
            'pageTitle' => 'Dyskusja: ' . $anime['title'] . ' — AniVibe',
            'anime' => $anime, 'animeId' => $animeId, 'thread' => $thread, 'threadMode' => true,
            'threadPath' => '/anime/' . $animeId . '/comments/' . $rootId,
            'replies' => $replies + [
                'previousUrl' => $replies['page'] > 1 ? $this->threadPath($animeId, $rootId, $replies['page'] - 1) : null,
                'nextUrl' => $replies['page'] < $replies['totalPages'] ? $this->threadPath($animeId, $rootId, $replies['page'] + 1) : null,
            ],
            'commentFormPage' => $mainPage, 'commentBody' => $body, 'commentErrors' => $errors,
            'editingComment' => $editingComment,
            'commentNotice' => !$this->isPost() ? match (true) {
                ($_GET['replySaved'] ?? null) === '1' => 'Odpowiedź została dodana.',
                ($_GET['commentUpdated'] ?? null) === '1' => 'Wpis został zaktualizowany.',
                ($_GET['commentRemoved'] ?? null) === '1' => 'Wpis został usunięty.',
                ($_GET['reportSaved'] ?? null) === '1' => 'Zgłoszenie zostało zapisane.',
                default => null,
            } : null,
        ]);
    }

    private function threadPath(int $animeId, int $rootId, int $page = 1, string $notice = '', string $anchor = 'replies'): string
    {
        $parameters = ['repliesPage' => $page];
        $mainPage = $this->commentPage($_POST['commentsPage'] ?? $_GET['commentsPage'] ?? '1');
        if ($mainPage > 1) {
            $parameters['commentsPage'] = $mainPage;
        }
        if ($notice !== '') {
            $parameters[$notice] = '1';
        }
        return '/anime/' . $animeId . '/comments/' . $rootId . '?' . http_build_query($parameters) . '#' . $anchor;
    }

    private function commentPage(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $page === false ? 1 : $page;
    }

    private function commentsPath(int $animeId, int $page, string $notice = ''): string
    {
        $parameters = [];
        if ($page > 1) {
            $parameters['commentsPage'] = $page;
        }
        if ($notice !== '') {
            $parameters[$notice] = '1';
        }

        return '/anime/' . $animeId
            . ($parameters === [] ? '' : '?' . http_build_query($parameters)) . '#comments';
    }

    private function commentNotFound(): void
    {
        http_response_code(404);
        $this->render('not-found', ['pageTitle' => 'Nie znaleziono komentarza — AniVibe']);
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
        array $commentErrors = [],
        string $commentBody = '',
        ?array $editingComment = null,
        ?int $commentsPage = null,
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

        if (!$this->isPost() && array_key_exists('editComment', $_GET)) {
            $this->requireLogin();
            $commentId = filter_var($_GET['editComment'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $editingComment = $commentId === false ? null
                : $this->comments()->findByIdForUser($_SESSION['user_id'], $id, $commentId);
            if ($editingComment === null || $editingComment['isRemoved'] || $editingComment['isDeleted']) {
                $this->commentNotFound();
                return;
            }
            if ($editingComment['parentId'] !== null) {
                $this->redirect('/anime/' . $id . '/comments/' . $editingComment['parentId'] . '?editComment=' . $editingComment['id'] . '#reply-form');
            }
            $commentBody = $editingComment['body'];
        }

        $comments = $this->comments()->findPageForAnime($id, $commentsPage ?? $this->commentPage($_GET['commentsPage'] ?? '1'));

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
            'comments' => $comments + [
                'previousUrl' => $this->commentsPath($id, $comments['page'] - 1),
                'nextUrl' => $this->commentsPath($id, $comments['page'] + 1),
            ],
            'commentErrors' => $commentErrors,
            'commentBody' => $commentBody,
            'editingComment' => $editingComment,
            'commentFormPage' => $comments['page'],
            'commentNotice' => !$this->isPost() ? match (true) {
                ($_GET['commentSaved'] ?? null) === '1' => 'Komentarz został dodany.',
                ($_GET['commentUpdated'] ?? null) === '1' => 'Komentarz został zaktualizowany.',
                ($_GET['commentRemoved'] ?? null) === '1' => 'Komentarz został usunięty.',
                ($_GET['reportSaved'] ?? null) === '1' => 'Zgłoszenie zostało zapisane.',
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

    private function community(): CommunityRepository
    {
        if ($this->community instanceof Closure) {
            $this->community = ($this->community)();
        }
        return $this->community;
    }

    private function replyNotifications(): ReplyNotificationRepository
    {
        if ($this->replyNotifications instanceof Closure) {
            $this->replyNotifications = ($this->replyNotifications)();
        }
        return $this->replyNotifications;
    }

    private function comments(): CommentRepository
    {
        if ($this->comments instanceof Closure) {
            $this->comments = ($this->comments)();
        }

        return $this->comments;
    }
}
