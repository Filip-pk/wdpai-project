<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ModerationRepository;
use App\Repository\UserRepository;
use App\Service\CommunityService;
use App\Service\Translator;
use Closure;

final class ModerationController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private ModerationRepository|Closure $moderation,
    ) {
    }

    public function index(): void
    {
        $user = $this->currentUser($this->users());
        $tab = $this->queryString('tab');
        $tab = in_array($tab, ['reports', 'comments', 'users', 'history'], true) ? $tab : 'reports';
        $filters = [
            'author' => mb_substr(trim($this->queryString('author')), 0, 200),
            'anime' => mb_substr(trim($this->queryString('anime')), 0, 200),
            'status' => $this->queryString('status') ?: ($tab === 'reports' ? 'open' : 'all'),
        ];
        $page = $this->page($_GET['page'] ?? '1');
        $method = match ($tab) {
            'comments' => 'commentsPageForModerator',
            'users' => 'usersPageForModerator',
            'history' => 'actionsPageForModerator',
            default => 'reportsPageForModerator',
        };
        $listing = $this->moderation()->$method($user['id'], $page, $filters);
        if ($listing === null) {
            $this->fail(403, 'moderation.forbidden');
            return;
        }
        $query = ['tab' => $tab] + $filters;
        $listing['previousUrl'] = $listing['page'] > 1
            ? '/moderation?' . http_build_query($query + ['page' => $listing['page'] - 1]) : null;
        $listing['nextUrl'] = $listing['page'] < $listing['totalPages']
            ? '/moderation?' . http_build_query($query + ['page' => $listing['page'] + 1]) : null;

        $this->render('moderation', [
            'pageTitle' => Translator::current()->translate('moderation.title'),
            'tab' => $tab,
            'filters' => $filters,
            'listing' => $listing,
            'notice' => $this->queryString('saved') === '1' ? Translator::current()->translate('moderation.saved') : null,
        ]);
    }

    public function removeComment(int $id): void
    {
        $this->decide(function (int $actor, string $reason, string $key) use ($id): bool {
            $reportId = $this->positiveInt($this->postString('reportId'));
            return $this->moderation()->removeComment($actor, $id, $reason, $key, $reportId);
        }, 'comments');
    }

    public function warnComment(int $id): void
    {
        $this->decide(function (int $actor, string $reason, string $key) use ($id): bool {
            $userId = $this->positiveInt($this->postString('userId'));
            return $userId !== null && $this->moderation()->warnUser($actor, $userId, $reason, $key, $id);
        }, 'comments');
    }

    public function banUser(int $id): void
    {
        $duration = $this->positiveInt($this->postString('duration'));
        $this->decide(
            fn (int $actor, string $reason, string $key): bool => $duration !== null && $this->moderation()->banUser($actor, $id, $reason, $duration, $key),
            'users',
            in_array($duration, ModerationRepository::BAN_DURATIONS, true),
        );
    }

    public function revokeBan(int $id): void
    {
        $this->decide(fn (int $actor, string $reason, string $key): bool => $this->moderation()->revokeBan($actor, $id, $reason, $key), 'users');
    }

    public function dismissReport(int $id): void
    {
        $this->decide(fn (int $actor, string $reason, string $key): bool => $this->moderation()->dismissReport($actor, $id, $reason, $key), 'reports');
    }

    public function resolve(int $id): void
    {
        $this->decide(
            fn (int $actor, string $reason, string $key): bool => $this->moderation()->resolveReportForModerator($actor, $id, $this->postString('action'), $reason, $key),
            'reports',
            in_array($this->postString('action'), ['remove', 'dismiss'], true),
        );
    }

    public function notices(): void
    {
        $user = $this->currentUser($this->users());
        $notices = $this->moderation()->noticesPageForUser($user['id'], $this->page($_GET['page'] ?? '1'));
        $notices['previousUrl'] = $notices['page'] > 1 ? '/account/notices?page=' . ($notices['page'] - 1) : null;
        $notices['nextUrl'] = $notices['page'] < $notices['totalPages'] ? '/account/notices?page=' . ($notices['page'] + 1) : null;
        $this->render('moderation-notices', [
            'pageTitle' => Translator::current()->translate('moderation.notices.title'),
            'notices' => $notices,
            'restrictedAccount' => $this->moderation()->activeBanForUser($user['id']) !== null,
        ]);
    }

    public function readNotice(int $id): void
    {
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, 'message.csrf_expired');
            return;
        }
        if (!$this->moderation()->acknowledgeNoticeForUser($user['id'], $id)) {
            $this->fail(404, 'moderation.missing');
            return;
        }
        $this->redirect('/account/notices?page=' . $this->page($_POST['page'] ?? '1'));
    }

    public function banned(): void
    {
        $user = $this->currentUser($this->users());
        $ban = $this->moderation()->activeBanForUser($user['id']);
        if ($ban === null) {
            $this->redirect('/dashboard');
        }
        $this->render('account-banned', [
            'pageTitle' => Translator::current()->translate('moderation.banned.title'),
            'ban' => $ban,
            'restrictedAccount' => true,
        ]);
    }

    private function decide(Closure $decision, string $tab, bool $valid = true): void
    {
        $user = $this->currentUser($this->users());
        if (!$this->moderation()->isModerator($user['id'])) {
            $this->fail(403, 'moderation.forbidden');
            return;
        }
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, 'message.csrf_expired');
            return;
        }
        $reason = CommunityService::normalizeReason($this->postString('reason'));
        $key = $this->postString('requestKey');
        if (!$valid || CommunityService::validateReason($reason) !== [] || preg_match('/\A[a-f0-9]{32,64}\z/', $key) !== 1) {
            $this->fail(422, 'moderation.invalid');
            return;
        }
        if (!$decision($user['id'], $reason, $key)) {
            $this->fail(409, 'moderation.missing');
            return;
        }
        $this->redirect('/moderation?tab=' . $tab . '&saved=1');
    }

    private function fail(int $status, string $key): void
    {
        http_response_code($status);
        $message = Translator::current()->translate($key);
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => $message], JSON_THROW_ON_ERROR);
            return;
        }
        $this->render('community-error', [
            'pageTitle' => Translator::current()->translate('moderation.title'),
            'message' => $message,
        ]);
    }

    private function positiveInt(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    private function queryString(string $key): string
    {
        return is_string($_GET[$key] ?? null) ? $_GET[$key] : '';
    }

    private function page(mixed $value): int
    {
        return $this->positiveInt($value) ?? 1;
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function moderation(): ModerationRepository
    {
        if ($this->moderation instanceof Closure) {
            $this->moderation = ($this->moderation)();
        }
        return $this->moderation;
    }
}
