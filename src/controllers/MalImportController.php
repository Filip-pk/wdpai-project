<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

use App\Repository\UserRepository;
use App\Service\MalImportChangedException;
use App\Service\MalImportService;
use App\Service\MalXmlParser;
use Closure;
use InvalidArgumentException;

final class MalImportController extends AppController
{
    private const PREVIEW_LIFETIME = 1800;
    private const PAGE_SIZE = 50;

    public function __construct(
        private UserRepository|Closure $users,
        private MalImportService|Closure $imports,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $pending = $_SESSION['mal_import_pending'] ?? null;
        $expired = is_array($pending) && ($pending['userId'] ?? null) === $user['id']
            && ($pending['expiresAt'] ?? 0) <= time();
        $this->renderImport($user['id'], notice: $expired ? Translator::current()->translate('message.mal.preview_expired') : null);
    }

    public function preview(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->renderImport($user['id'], [Translator::current()->translate('message.csrf_expired')]);
            return;
        }

        unset($_SESSION['mal_import_pending'], $_SESSION['mal_import_report']);
        try {
            $policy = $_POST['updateExisting'] ?? '0';
            if (!is_string($policy) || !in_array($policy, ['0', '1'], true)) {
                throw new InvalidArgumentException(Translator::current()->translate('message.mal.choose_mode'));
            }
            $bytes = $this->readUpload();
            $parsed = (new MalXmlParser())->parse($bytes);
            unset($bytes);
            $updateExisting = $policy === '1';
            $preview = $this->imports()->previewForUser($user['id'], $parsed, $updateExisting);
            $this->storePreview($user['id'], $parsed, $preview, $updateExisting);
            $this->redirect('/profile/import');
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->renderImport($user['id'], [$exception->getMessage()]);
        }
    }

    public function confirm(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->renderImport($user['id'], [Translator::current()->translate('message.csrf_expired')]);
            return;
        }
        $pending = $this->pendingForUser($user['id']);
        if (!$this->isImportTokenValid($pending)) {
            http_response_code(409);
            $this->renderImport($user['id'], [Translator::current()->translate('message.mal.preview_used')]);
            return;
        }

        try {
            $report = $this->imports()->importForUser(
                $user['id'], $pending['parsed'], $pending['updateExisting'], $pending['preview']['fingerprint'],
            );
        } catch (MalImportChangedException) {
            $preview = $this->imports()->previewForUser($user['id'], $pending['parsed'], $pending['updateExisting']);
            $this->storePreview($user['id'], $pending['parsed'], $preview, $pending['updateExisting']);
            http_response_code(409);
            $this->renderImport($user['id'], [Translator::current()->translate('message.mal.changed_updated')]);
            return;
        }

        unset($_SESSION['mal_import_pending']);
        $_SESSION['mal_import_report'] = ['userId' => $user['id'], 'report' => $report];
        $this->redirect('/profile/import?imported=1');
    }

    public function cancel(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->renderImport($user['id'], [Translator::current()->translate('message.csrf_expired')]);
            return;
        }
        if (!$this->isImportTokenValid($this->pendingForUser($user['id']))) {
            http_response_code(409);
            $this->renderImport($user['id'], [Translator::current()->translate('message.mal.preview_unavailable')]);
            return;
        }
        unset($_SESSION['mal_import_pending']);
        $this->redirect('/profile/import');
    }

    private function readUpload(): string
    {
        $upload = $_FILES['malFile'] ?? null;
        if (!is_array($upload) || !is_int($upload['error'] ?? null)
            || $upload['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.choose_xml'));
        }
        if (!is_string($upload['name'] ?? null) || strlen($upload['name']) > 512
            || preg_match('/\.(?:xml|gz)\z/i', $upload['name']) !== 1) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.supported'));
        }
        if (!is_int($upload['size'] ?? null) || $upload['size'] < 1 || $upload['size'] > MalXmlParser::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.file_size'));
        }
        if (!is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.upload_failed'));
        }
        $bytes = file_get_contents($upload['tmp_name'], false, null, 0, MalXmlParser::MAX_UPLOAD_BYTES + 1);
        if ($bytes === false || $bytes === '' || strlen($bytes) > MalXmlParser::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.read_limit'));
        }
        return $bytes;
    }

    private function storePreview(int $userId, array $parsed, array $preview, bool $updateExisting): void
    {
        $_SESSION['mal_import_pending'] = [
            'userId' => $userId,
            'parsed' => $parsed,
            'preview' => $preview,
            'updateExisting' => $updateExisting,
            'token' => bin2hex(random_bytes(32)),
            'expiresAt' => time() + self::PREVIEW_LIFETIME,
        ];
    }

    private function pendingForUser(int $userId): ?array
    {
        $pending = $_SESSION['mal_import_pending'] ?? null;
        if (!is_array($pending) || ($pending['userId'] ?? null) !== $userId
            || !is_int($pending['expiresAt'] ?? null) || $pending['expiresAt'] <= time()) {
            unset($_SESSION['mal_import_pending']);
            return null;
        }
        return $pending;
    }

    private function isImportTokenValid(?array $pending): bool
    {
        $token = $this->postString('importToken');
        return $pending !== null && $token !== '' && hash_equals($pending['token'], $token);
    }

    private function renderImport(int $userId, array $errors = [], ?string $notice = null): void
    {
        $pending = $this->pendingForUser($userId);
        $storedReport = $_SESSION['mal_import_report'] ?? null;
        $report = is_array($storedReport) && ($storedReport['userId'] ?? null) === $userId
            ? $storedReport['report'] : null;
        $preview = $pending['preview'] ?? null;
        $rows = $preview['rows'] ?? $report['rows'] ?? [];
        $totalPages = max(1, (int) ceil(count($rows) / self::PAGE_SIZE));
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = min($totalPages, $page === false ? 1 : $page);
        $visibleRows = array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        $this->render('mal-import', [
            'pageTitle' => Translator::current()->translate('message.page.mal'),
            'errors' => $errors,
            'notice' => $notice,
            'preview' => $preview,
            'previewRows' => $preview === null ? [] : $visibleRows,
            'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => count($rows)],
            'importToken' => $pending['token'] ?? '',
            'expiresInMinutes' => $pending === null ? 0 : (int) ceil(($pending['expiresAt'] - time()) / 60),
            'report' => $report,
            'reportRows' => $report === null ? [] : $visibleRows,
            'updateExisting' => $pending['updateExisting'] ?? ($this->postString('updateExisting') === '1'),
        ]);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function imports(): MalImportService
    {
        if ($this->imports instanceof Closure) {
            $this->imports = ($this->imports)();
        }
        return $this->imports;
    }
}
