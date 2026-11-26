<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;
use App\Repository\RecommendationChatRepository;
use App\Repository\UserRepository;
use App\Service\AccountAccessGuard;
use App\Service\OllamaClient;
use App\Service\RecommendationChatService;
use App\Service\Translator;
use Closure;
use Throwable;

final class ChatController extends AppController
{
    private const STATE = 'catalog_chat';
    private const PENDING = 'catalog_chat_pending';
    private const CANCELLED = 'catalog_chat_cancelled';

    public function __construct(private readonly Closure $connect)
    {
    }

    public function index(): void
    {
        $this->assistant();
    }

    public function submit(): void
    {
        $this->requireLogin();
        if (!$this->isCsrfTokenValid($this->postString('_csrf'))) {
            $this->assistant('csrf', 403);
            return;
        }
        $intent = $this->postString('intent');
        if ($intent === 'reset') {
            unset($_SESSION[self::STATE], $_SESSION[self::PENDING], $_SESSION[self::CANCELLED]);
            $this->redirect('/chat');
        }

        $requestId = $this->postString('requestId');
        if (preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1) {
            $this->assistant('input', 422);
            return;
        }
        $pending = $_SESSION[self::PENDING] ?? null;
        if ($intent === 'cancel') {
            if ($pending === null || $pending['id'] === $requestId) {
                $_SESSION[self::CANCELLED] = $requestId;
                unset($_SESSION[self::PENDING]);
            }
            http_response_code(204);
            return;
        }

        $more = $intent === 'more';
        $message = $more ? Translator::current()->translate('chat.more') : trim($this->postString('message'));
        if ((!$more && $intent !== 'send') || $message === '' || strlen($message) > 4800
            || preg_match('//u', $message) !== 1 || mb_strlen($message) > 1200 || str_contains($message, "\0")) {
            $this->assistant('input', 422);
            return;
        }
        if (($_SESSION[self::CANCELLED] ?? null) === $requestId) {
            $this->assistant('cancelled', 409, $more ? '' : $message);
            return;
        }
        if ($pending !== null && $pending['until'] > time()) {
            $this->assistant('busy', 409, $more ? '' : $message);
            return;
        }

        $database = ($this->connect)();
        $userId = $_SESSION['user_id'];
        if ((new UserRepository($database))->findById($userId) === null) {
            $_SESSION = [];
            $this->redirect('/login');
        }
        $sessionId = session_id();
        $state = $_SESSION[self::STATE] ?? [];
        $_SESSION[self::PENDING] = ['id' => $requestId, 'until' => time() + 60];
        AccountAccessGuard::finish();
        session_write_close();

        $cancelled = $this->cancellationCheck($sessionId, $userId, $requestId);
        $failure = null;
        try {
            $service = new RecommendationChatService(new RecommendationChatRepository($database), OllamaClient::fromEnvironment());
            $state = $service->respond($message, $more, $state, $cancelled);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        if (!$this->resumeSession($sessionId) || ($_SESSION['user_id'] ?? null) !== $userId) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            http_response_code(409);
            return;
        }
        if (AccountAccessGuard::begin($database, $userId) !== null) {
            if (($_SESSION[self::PENDING]['id'] ?? null) === $requestId) {
                unset($_SESSION[self::PENDING]);
            }
            AccountAccessGuard::finish(false);
            $this->redirect('/account/banned');
        }
        if (($_SESSION[self::PENDING]['id'] ?? null) !== $requestId) {
            $this->assistant('cancelled', 409, $more ? '' : $message);
            return;
        }
        unset($_SESSION[self::PENDING]);
        if ($failure !== null) {
            throw $failure;
        }
        $_SESSION[self::STATE] = $state;
        $this->redirect('/chat');
    }

    private function assistant(?string $error = null, int $status = 200, string $draft = ''): void
    {
        http_response_code($status);
        $this->render('chat-assistant', [
            'pageTitle' => Translator::current()->translate('chat.title') . ' — AniVibe',
            'chatState' => $_SESSION[self::STATE] ?? [],
            'chatError' => $error,
            'chatDraft' => $draft,
            'chatRequestId' => bin2hex(random_bytes(16)),
        ]);
    }

    private function cancellationCheck(string $sessionId, int $userId, string $requestId): Closure
    {
        $lastCheck = 0;
        $cancelled = false;
        return function () use ($sessionId, $userId, $requestId, &$lastCheck, &$cancelled): bool {
            $now = hrtime(true);
            if ($cancelled || $now - $lastCheck < 500_000_000) {
                return $cancelled;
            }
            $lastCheck = $now;
            $cancelled = !$this->resumeSession($sessionId, true) || ($_SESSION['user_id'] ?? null) !== $userId
                || ($_SESSION[self::PENDING]['id'] ?? null) !== $requestId;
            return $cancelled;
        };
    }

    private function resumeSession(string $id, bool $readOnly = false): bool
    {
        session_id($id);
        return session_start(['use_cookies' => false, 'cache_limiter' => '', 'read_and_close' => $readOnly])
            && session_id() === $id;
    }

    public function show(int $id): void
    {
        $personas = DemoData::personas();

        if ($id < 1 || !isset($personas[$id])) {
            http_response_code(404);

            $this->render('not-found', [
                'pageTitle' => Translator::current()->translate('message.page.persona_missing'),
            ]);

            return;
        }

        $this->render('chat-persona', [
            'pageTitle' => Translator::current()->translate('message.page.chat_prefix') . $personas[$id]['name'] . ' — AniVibe',
            'persona' => $personas[$id],
            'isNaruto' => $id === 1,
        ]);
    }
}
