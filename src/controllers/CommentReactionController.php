<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CommentReactionRepository;
use App\Repository\CommentRepository;
use App\Repository\CommunityRepository;
use App\Repository\UserRepository;
use App\Service\CommentReactionService;
use App\Service\RateLimitException;
use Closure;

final class CommentReactionController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private CommentRepository|Closure $comments,
        private CommunityRepository|Closure $community,
        private CommentReactionRepository|Closure $reactions,
    ) {
    }

    public function react(int $id, int $commentId): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $comment = $this->comments()->findByIdForAnime($id, $commentId);
        $backUrl = $this->backUrl($id, $commentId, $comment);
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.', $backUrl);
            return;
        }
        $value = $this->postString('value');
        if (!in_array($value, ['-1', '0', '1'], true)) {
            $this->fail(422, 'Wybierz like, dislike lub cofnięcie swojego głosu.', $backUrl);
            return;
        }
        try {
            $changed = (new CommentReactionService($this->community(), $this->reactions()))->setForUser($user['id'], $id, $commentId, (int) $value);
        } catch (RateLimitException $exception) {
            header('Retry-After: ' . $exception->retryAfter);
            $this->fail(429, $exception->getMessage(), $backUrl);
            return;
        }
        if (!$changed) {
            $this->fail(404, 'Nie znaleziono wpisu, na który możesz zagłosować.', $backUrl);
            return;
        }
        $this->redirect($backUrl);
    }

    private function backUrl(int $animeId, int $commentId, ?array $comment): string
    {
        $rootId = $comment['parentId'] ?? (
            $comment !== null && $this->postString('returnThread') === (string) $commentId ? $commentId : null
        );
        if ($rootId !== null) {
            $requested = $this->page($_POST['repliesPage'] ?? '1');
            $page = $this->comments()->findRepliesPageForAnime($animeId, $rootId, $requested)['page'] ?? 1;
            return '/anime/' . $animeId . '/comments/' . $rootId . '?repliesPage=' . $page
                . '&commentsPage=' . $this->page($_POST['commentsPage'] ?? '1') . '#comment-' . $commentId;
        }
        $page = $this->comments()->findPageForAnime($animeId, $this->page($_POST['commentsPage'] ?? '1'))['page'];
        return '/anime/' . $animeId . '?commentsPage=' . $page . '#comment-' . $commentId;
    }

    private function fail(int $status, string $message, string $backUrl): void
    {
        http_response_code($status);
        $this->render('community-error', ['pageTitle' => 'Reakcja na komentarz — AniVibe', 'message' => $message, 'backUrl' => $backUrl]);
    }

    private function page(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $page === false ? 1 : $page;
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function comments(): CommentRepository
    {
        if ($this->comments instanceof Closure) {
            $this->comments = ($this->comments)();
        }
        return $this->comments;
    }

    private function community(): CommunityRepository
    {
        if ($this->community instanceof Closure) {
            $this->community = ($this->community)();
        }
        return $this->community;
    }

    private function reactions(): CommentReactionRepository
    {
        if ($this->reactions instanceof Closure) {
            $this->reactions = ($this->reactions)();
        }
        return $this->reactions;
    }
}
