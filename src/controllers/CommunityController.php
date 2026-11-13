<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

use App\Repository\CommunityRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\CommunityService;
use App\Service\RateLimitException;
use Closure;

final class CommunityController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private CommunityRepository|Closure $community,
        private MessageRepository|Closure $messages,
    ) {
    }

    public function notifications(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $state = $this->community()->navigationStateForUser($user['id']);
        $this->json(['unreadCount' => $state['unreadCount'], 'unreadReplyCount' => $state['unreadReplyCount']]);
    }

    public function read(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, Translator::current()->translate('message.csrf_expired'));
            return;
        }
        $lastMessageId = filter_var($this->postString('lastMessageId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($lastMessageId === false || !$this->community()->markReadForUser($user['id'], $id, $lastMessageId)) {
            $this->fail(404, Translator::current()->translate('message.message_missing'));
            return;
        }
        if ($this->wantsJson()) {
            $this->json([
                'unreadCount' => $this->community()->unreadCountForUser($user['id']),
                'conversationId' => $id,
                'conversationUnreadCount' => $this->community()->conversationUnreadCountForUser($user['id'], $id),
            ]);
            return;
        }
        $this->redirect('/messages/' . $id . '?page=' . $this->page($_POST['page'] ?? '1') . '#message-history');
    }

    public function block(int $id): void
    {
        $this->changeBlock($id, true);
    }

    public function unblock(int $id): void
    {
        $this->changeBlock($id, false);
    }

    private function changeBlock(int $id, bool $block): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, Translator::current()->translate('message.csrf_expired'));
            return;
        }
        if ($id <= 0 || $id === $user['id'] || $this->users()->findPublicById($id) === null) {
            $this->fail(404, Translator::current()->translate('message.user_missing'));
            return;
        }
        $changed = $block ? $this->community()->blockForUser($user['id'], $id)
            : $this->community()->unblockForUser($user['id'], $id);
        if (!$changed) {
            $this->fail(404, Translator::current()->translate('message.user_missing'));
            return;
        }
        $notice = $block ? 'blocked=1' : 'unblocked=1';
        if ($this->postString('returnTo') === 'blocked') {
            $this->redirect('/messages/blocked?' . $notice);
        }
        $conversationId = filter_var($this->postString('conversationId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($conversationId !== false) {
            $conversation = $this->messages()->findForUser($user['id'], $conversationId);
            if ($conversation !== null && $conversation['recipientId'] === $id) {
                $this->redirect('/messages/' . $conversationId . '?' . $notice);
            }
        }
        $this->redirect('/users/' . $id . '?' . $notice);
    }

    public function blocked(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $blocked = $this->community()->blockedPageForUser($user['id'], $this->page($_GET['page'] ?? '1'));
        $this->render('blocked-users', [
            'pageTitle' => Translator::current()->translate('message.page.blocked'),
            'blocked' => $blocked + [
                'previousUrl' => $blocked['page'] > 1 ? '/messages/blocked?page=' . ($blocked['page'] - 1) : null,
                'nextUrl' => $blocked['page'] < $blocked['totalPages'] ? '/messages/blocked?page=' . ($blocked['page'] + 1) : null,
            ],
            'errors' => [],
            'notice' => ($_GET['unblocked'] ?? null) === '1' ? Translator::current()->translate('message.user_unblocked') : null,
        ]);
    }

    public function reportComment(int $id): void
    {
        $this->report('comment', $id);
    }

    public function reportMessage(int $id): void
    {
        $this->report('message', $id);
    }

    private function report(string $type, int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $target = $id > 0 ? $this->community()->reportTargetForUser($user['id'], $type, $id) : null;
        if ($target === null) {
            $this->fail(404, Translator::current()->translate('message.report.missing_content'));
            return;
        }
        $page = $this->page($_POST['returnPage'] ?? $_GET['page'] ?? '1');
        $requestedThread = filter_var($_POST['returnThread'] ?? $_GET['thread'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $returnThread = $target['parentId'] ?? ($type === 'comment' && $requestedThread === $id ? $id : null);
        $mainCommentsPage = $this->page($_POST['commentsPage'] ?? $_GET['commentsPage'] ?? ($target['parentId'] === null ? $page : '1'));
        $repliesPage = $target['parentId'] === null
            ? $this->page($_POST['repliesPage'] ?? $_GET['repliesPage'] ?? '1') : $page;
        $backUrl = $type === 'comment'
            ? ($returnThread === null
                ? '/anime/' . $target['animeId'] . '?commentsPage=' . $page . '#comments'
                : '/anime/' . $target['animeId'] . '/comments/' . $returnThread . '?repliesPage=' . $repliesPage . '&commentsPage=' . $mainCommentsPage . '#comment-' . $id)
            : '/messages/' . $target['conversationId'] . '?page=' . $page . '#message-history';
        $reason = $this->isPost() ? CommunityService::normalizeReason($this->postString('reason')) : '';
        $errors = [];
        if ($this->isPost()) {
            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = Translator::current()->translate('message.csrf_expired');
            } else {
                $errors = CommunityService::validateReason($reason);
                if ($errors !== []) {
                    http_response_code(422);
                }
            }
            if ($errors === []) {
                try {
                    $reportId = (new CommunityService($this->community()))->report($user['id'], $type, $id, $reason);
                    if ($reportId === null) {
                        $this->fail(404, Translator::current()->translate('message.report.missing_content'));
                        return;
                    }
                    $this->redirect(str_replace('#', '&reportSaved=1#', $backUrl));
                } catch (RateLimitException $exception) {
                    http_response_code(429);
                    header('Retry-After: ' . $exception->retryAfter);
                    $errors[] = $exception->getMessage();
                }
            }
        }
        $this->render('report', [
            'pageTitle' => Translator::current()->translate('message.page.report'), 'target' => $target,
            'reason' => $reason, 'errors' => $errors, 'returnPage' => $page, 'backUrl' => $backUrl,
            'returnThread' => $returnThread, 'mainCommentsPage' => $mainCommentsPage, 'repliesPage' => $repliesPage,
        ]);
    }

    public function moderation(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $reports = $this->community()->reportsPageForModerator($user['id'], $this->page($_GET['page'] ?? '1'));
        if ($reports === null) {
            $this->fail(403, Translator::current()->translate('message.moderation.section'));
            return;
        }
        $this->render('moderation', [
            'pageTitle' => Translator::current()->translate('message.page.moderation'),
            'reports' => $reports + [
                'previousUrl' => $reports['page'] > 1 ? '/moderation?page=' . ($reports['page'] - 1) : null,
                'nextUrl' => $reports['page'] < $reports['totalPages'] ? '/moderation?page=' . ($reports['page'] + 1) : null,
            ],
            'errors' => [],
            'notice' => ($_GET['resolved'] ?? null) === '1' ? Translator::current()->translate('message.report.resolved') : null,
        ]);
    }

    public function resolve(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->community()->navigationStateForUser($user['id'])['isModerator']) {
            $this->fail(403, Translator::current()->translate('message.moderation.operation'));
            return;
        }
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, Translator::current()->translate('message.csrf_expired'));
            return;
        }
        $action = $this->postString('action');
        if (!in_array($action, ['remove', 'dismiss'], true)) {
            $this->fail(422, Translator::current()->translate('message.moderation.choose'));
            return;
        }
        if (!$this->community()->resolveReportForModerator($user['id'], $id, $action)) {
            $this->fail(404, Translator::current()->translate('message.report.no_open'));
            return;
        }
        $this->redirect('/moderation?page=' . $this->page($_POST['page'] ?? '1') . '&resolved=1');
    }

    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        if ($this->wantsJson()) {
            $this->json(['error' => $message]);
            return;
        }
        $this->render('community-error', ['pageTitle' => Translator::current()->translate('message.page.operation_failed'), 'message' => $message]);
    }

    private function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_THROW_ON_ERROR);
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

    private function community(): CommunityRepository
    {
        if ($this->community instanceof Closure) {
            $this->community = ($this->community)();
        }
        return $this->community;
    }

    private function messages(): MessageRepository
    {
        if ($this->messages instanceof Closure) {
            $this->messages = ($this->messages)();
        }
        return $this->messages;
    }
}
