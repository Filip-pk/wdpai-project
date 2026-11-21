<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AdminRepository;
use App\Repository\UserRepository;
use App\Service\ApplicationLogger;
use App\Service\CommunityService;
use App\Service\DiagnosticService;
use App\Service\MailService;
use App\Service\Translator;
use Closure;
use Throwable;

final class AdminController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private AdminRepository|Closure $admins,
        private readonly DiagnosticService $diagnostics,
        private readonly ApplicationLogger $logger,
        private readonly MailService $mail,
    ) {
    }

    public function index(): void
    {
        $user = $this->authorizedUser();
        if ($user === null) {
            return;
        }
        $tab = $this->queryString('tab');
        $tab = in_array($tab, ['overview', 'diagnostics', 'errors', 'users', 'catalog', 'history'], true)
            ? $tab : 'overview';
        $query = $this->queryString('query');
        if (preg_match('//u', $query) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $query) === 1) {
            $this->fail(422, 'admin.error.user');
            return;
        }
        $filters = [
            'query' => mb_substr(trim($query), 0, 200),
            'missing' => in_array($this->queryString('missing'), ['description', 'image'], true)
                ? $this->queryString('missing') : 'all',
            'level' => in_array($this->queryString('level'), ['error', 'warning', 'info'], true)
                ? $this->queryString('level') : '',
            'module' => mb_substr(trim($this->queryString('module')), 0, 40),
        ];
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $notice = $_SESSION['admin_notice'] ?? null;
        $errors = $_SESSION['admin_errors'] ?? [];
        unset($_SESSION['admin_notice'], $_SESSION['admin_errors']);
        $this->render('admin', [
            'pageTitle' => Translator::current()->translate('admin.title'),
            'tab' => $tab,
            'adminUserId' => $user['id'],
            'filters' => $filters,
            'notice' => is_string($notice) ? Translator::current()->translate($notice) : null,
            'errors' => array_map(Translator::current()->translate(...), $errors),
            'overview' => $tab === 'overview' ? $this->admins()->overview() : [],
            'diagnostics' => $_SESSION['admin_diagnostics'] ?? [],
            'logEntries' => $tab === 'errors' ? $this->logger->recent($filters['level'], $filters['module'], $page) : [],
            'userList' => $tab === 'users' ? $this->admins()->users($filters['query'], $page) : [],
            'catalog' => $tab === 'catalog' ? $this->admins()->catalog($filters['query'], $filters['missing'], $page) : [],
            'history' => $tab === 'history' ? $this->admins()->history($page) : [],
        ]);
    }

    public function checkDiagnostics(): void
    {
        $user = $this->authorizedUser();
        if ($user === null || !$this->validCsrf()) {
            return;
        }
        if (!$this->allowOperation('diagnostics', 10)) {
            return;
        }
        $_SESSION['admin_diagnostics'] = $this->diagnostics->checkAll();
        $this->admins()->audit($user['id'], 'diagnostics_checked');
        $_SESSION['admin_notice'] = 'admin.notice.diagnostics_saved';
        $this->redirect('/admin?tab=diagnostics');
    }

    public function testMail(): void
    {
        $user = $this->authorizedUser();
        if ($user === null || !$this->validCsrf()) {
            return;
        }
        if (!$this->allowOperation('mail', 60)) {
            return;
        }
        // The recipient comes exclusively from the authenticated account.
        $details = ['result' => 'accepted'];
        try {
            $this->mail->sendDiagnosticEmail($user['email'], $user['language'] ?? 'pl');
            $_SESSION['admin_notice'] = 'admin.notice.mail_sent';
        } catch (Throwable $exception) {
            $errorId = $this->logger->recordException($exception, 'mail', '/admin/mail-test');
            $details = ['result' => 'error', 'errorId' => $errorId];
            $_SESSION['admin_errors'] = ['admin.error.mail'];
        }
        // An audit outage must never turn accepted SMTP delivery into a mail failure.
        try {
            $this->admins()->audit($user['id'], 'mail_test', null, $details);
        } catch (Throwable $exception) {
            $this->logger->recordException($exception, 'admin', '/admin/mail-test');
            $_SESSION['admin_errors'][] = 'admin.error.audit';
        }
        $this->redirect('/admin?tab=diagnostics');
    }

    public function changeModerator(int $id): void
    {
        $user = $this->authorizedUser();
        if ($user === null || !$this->validCsrf()) {
            return;
        }
        $enabled = $this->postString('enabled');
        $reason = CommunityService::normalizeReason($this->postString('reason'));
        if (!in_array($enabled, ['0', '1'], true) || CommunityService::validateReason($reason) !== []) {
            $this->fail(422, 'admin.error.reason');
            return;
        }
        if (!$this->admins()->changeModerator($user['id'], $id, $enabled === '1', $reason)) {
            $this->fail(409, 'admin.error.role_change');
            return;
        }
        $_SESSION['admin_notice'] = 'admin.notice.roles_saved';
        $this->redirect('/admin?tab=users');
    }

    private function authorizedUser(): ?array
    {
        $user = $this->currentUser($this->users());
        if (!$this->admins()->isAdmin($user['id'])) {
            $this->fail(403, 'admin.error.access');
            return null;
        }
        return $user;
    }

    private function validCsrf(): bool
    {
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $this->fail(403, 'admin.error.csrf');
            return false;
        }
        return true;
    }

    private function allowOperation(string $operation, int $seconds): bool
    {
        $previous = $_SESSION['admin_operation_times'][$operation] ?? 0;
        $remaining = $seconds - (time() - (is_int($previous) ? $previous : 0));
        if ($remaining > 0) {
            header('Retry-After: ' . $remaining);
            $this->fail(429, 'admin.error.rate_limit');
            return false;
        }
        $_SESSION['admin_operation_times'][$operation] = time();
        return true;
    }

    private function fail(int $status, string $key): void
    {
        http_response_code($status);
        $this->render('community-error', [
            'pageTitle' => Translator::current()->translate('admin.title'),
            'message' => Translator::current()->translate($key),
            'backUrl' => '/admin',
        ]);
    }

    private function queryString(string $key): string
    {
        return is_string($_GET[$key] ?? null) ? $_GET[$key] : '';
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function admins(): AdminRepository
    {
        if ($this->admins instanceof Closure) {
            $this->admins = ($this->admins)();
        }
        return $this->admins;
    }
}
