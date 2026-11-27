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
        $tab = in_array($tab, ['overview', 'diagnostics', 'errors', 'users', 'reports', 'catalog', 'history'], true)
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
            'module' => in_array($this->queryString('module'), ApplicationLogger::MODULES, true)
                ? $this->queryString('module') : '',
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
            'logModules' => ApplicationLogger::MODULES,
            'cleanupCounts' => ApplicationLogger::CLEANUP_COUNTS,
            'userList' => $tab === 'users' ? $this->admins()->users($filters['query'], $page) : [],
            'messageReports' => $tab === 'reports' ? $this->admins()->messageReports($page) : [],
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
        $_SESSION['admin_notice'] = 'admin.notice.diagnostics_saved';
        // As with the mail test, an audit outage must not hide results that were already collected.
        try {
            $this->admins()->audit($user['id'], 'diagnostics_checked');
        } catch (Throwable $exception) {
            $this->logger->recordException($exception, 'admin', '/admin/diagnostics');
            $_SESSION['admin_errors'] = ['admin.error.audit'];
        }
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

    public function dismissMessageReport(int $id): void
    {
        $this->decideMessageReport($id, false);
    }

    public function hideReportedMessage(int $id): void
    {
        $this->decideMessageReport($id, true);
    }

    /** Message reports are decided only here: moderators never see private messages. */
    private function decideMessageReport(int $reportId, bool $hide): void
    {
        $user = $this->authorizedUser();
        if ($user === null || !$this->validCsrf()) {
            return;
        }
        $reason = CommunityService::normalizeReason($this->postString('reason'));
        if (CommunityService::validateReason($reason) !== []) {
            $this->fail(422, 'admin.error.reason');
            return;
        }
        $decided = $hide
            ? $this->admins()->hideReportedMessage($user['id'], $reportId, $reason)
            : $this->admins()->dismissMessageReport($user['id'], $reportId, $reason);
        if (!$decided) {
            $this->fail(409, 'admin.error.report');
            return;
        }
        $_SESSION['admin_notice'] = $hide ? 'admin.notice.message_hidden' : 'admin.notice.report_dismissed';
        $this->redirect('/admin?tab=reports');
    }

    private function authorizedUser(): ?array
    {
        $user = $this->currentUser($this->users());
        if (!$this->admins()->isAdmin($user['id'])) {
            // A link back to /admin would only lead to this page again.
            $this->fail(403, 'admin.error.access', false);
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

    private function fail(int $status, string $key, bool $panelLink = true): void
    {
        $translator = Translator::current();
        http_response_code($status);
        $this->render('community-error', [
            'pageTitle' => $translator->translate('admin.title'),
            'message' => $translator->translate($key),
            'backUrl' => $panelLink ? '/admin' : '/',
            'backLabel' => $translator->translate($panelLink ? 'admin.back_to_panel' : 'view.interface.home'),
            'errorHint' => $translator->translate($panelLink ? 'admin.error.hint' : 'admin.error.access_hint'),
        ]);
    }

    private function queryString(string $key): string
    {
        return is_string($_GET[$key] ?? null) ? $_GET[$key] : '';
    }

    private function users(): UserRepository
    {
        return self::resolveDependency($this->users);
    }

    private function admins(): AdminRepository
    {
        return self::resolveDependency($this->admins);
    }
}
