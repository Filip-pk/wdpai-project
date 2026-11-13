<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

use App\Repository\ReplyNotificationRepository;
use App\Repository\UserRepository;
use Closure;

final class ReplyNotificationController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private ReplyNotificationRepository|Closure $notifications,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $page = $this->page($_GET['page'] ?? '1');
        $notifications = $this->notifications()->findPageForUser($user['id'], $page);
        $this->render('reply-notifications', [
            'pageTitle' => Translator::current()->translate('message.page.notifications'),
            'notifications' => $notifications + [
                'previousUrl' => $notifications['page'] > 1 ? '/notifications/comments?page=' . ($notifications['page'] - 1) : null,
                'nextUrl' => $notifications['page'] < $notifications['totalPages'] ? '/notifications/comments?page=' . ($notifications['page'] + 1) : null,
            ],
            'notice' => ($_GET['read'] ?? null) === '1' ? Translator::current()->translate('message.notification.read_all') : null,
        ]);
    }

    public function read(int $id): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, Translator::current()->translate('message.csrf_expired'));
            return;
        }
        $notification = $this->notifications()->findByIdForUser($user['id'], $id);
        if ($notification === null || !$this->notifications()->markReadForUser($user['id'], $id)) {
            $this->fail(404, Translator::current()->translate('message.notification.missing'));
            return;
        }
        $this->redirect('/anime/' . $notification['animeId'] . '/comments/' . $notification['parentId']
            . '?repliesPage=' . $notification['replyPage'] . '#comment-' . $notification['replyId']);
    }

    public function readAll(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, Translator::current()->translate('message.csrf_expired'));
            return;
        }
        $snapshotId = filter_var($this->postString('lastNotificationId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($snapshotId === false || !$this->notifications()->markAllReadForUser($user['id'], $snapshotId)) {
            $this->fail(404, Translator::current()->translate('message.notification.nothing_to_mark'));
            return;
        }
        $this->redirect('/notifications/comments?page=' . $this->page($_POST['page'] ?? '1') . '&read=1');
    }

    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        $this->render('community-error', ['pageTitle' => Translator::current()->translate('message.page.notifications'), 'message' => $message]);
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

    private function notifications(): ReplyNotificationRepository
    {
        if ($this->notifications instanceof Closure) {
            $this->notifications = ($this->notifications)();
        }
        return $this->notifications;
    }
}
