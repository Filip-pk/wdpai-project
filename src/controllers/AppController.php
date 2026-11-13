<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CommunityRepository;
use App\Repository\PreferencesRepository;
use App\Repository\UserRepository;
use App\Service\Translator;
use Closure;

abstract class AppController
{
    private CommunityRepository|Closure|null $communityRepository = null;
    private PreferencesRepository|Closure|null $preferencesRepository = null;
    private ?array $loadedPreferences = null;

    public function setPreferencesRepository(PreferencesRepository|Closure $repository): void
    {
        $this->preferencesRepository = $repository;
    }

    public function setCommunityRepository(CommunityRepository|Closure $repository): void
    {
        $this->communityRepository = $repository;
    }

    public function initializeLanguage(bool $loadAccount = true): void
    {
        $language = $_COOKIE['anivibe_language'] ?? 'pl';
        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            $preferences = $loadAccount ? $this->accountPreferences() : [];
            $language = $preferences['language'] ?? $_SESSION['user_language'] ?? 'pl';
            $_SESSION['user_language'] = $language;
        }
        Translator::setCurrent(new Translator(is_string($language) ? $language : 'pl'));
    }

    private function accountPreferences(): array
    {
        if ($this->loadedPreferences !== null) {
            return $this->loadedPreferences;
        }
        if (!is_int($_SESSION['user_id'] ?? null) || $this->preferencesRepository === null) {
            return [];
        }
        if ($this->preferencesRepository instanceof Closure) {
            $this->preferencesRepository = ($this->preferencesRepository)();
        }
        return $this->loadedPreferences = $this->preferencesRepository->forUser($_SESSION['user_id']);
    }

    protected function currentUser(UserRepository $users): array
    {
        $this->requireLogin();
        $user = $users->findById($_SESSION['user_id']);

        if ($user === null) {
            $_SESSION = [];
            $this->redirect('/login');
        }

        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_theme'] = $user['theme'];
        $_SESSION['user_language'] = $user['language'] ?? 'pl';
        Translator::setCurrent(new Translator($_SESSION['user_language']));
        $_SESSION['user_avatar_url'] = $user['avatar_url'];

        return $user;
    }

    protected function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    protected function postString(string $name): string
    {
        return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
    }

    protected function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }

    protected function requireLogin(): void
    {
        if (!is_int($_SESSION['user_id'] ?? null) || $_SESSION['user_id'] <= 0) {
            $this->redirect('/login');
        }
    }

    protected function csrfToken(): string
    {
        if (!is_string($_SESSION['csrf_token'] ?? null) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    protected function isCsrfTokenValid(string $token): bool
    {
        return $token !== ''
            && is_string($_SESSION['csrf_token'] ?? null)
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    protected function render(string $template, array $variables = []): void
    {
        $templatePath = dirname(__DIR__, 2) . '/public/views/' . $template . '.html.php';

        if (!is_file($templatePath)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        $isLoggedIn = is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0;
        $currentUserName = $isLoggedIn ? (string) ($_SESSION['user_name'] ?? '') : '';
        $currentUserAvatarUrl = $isLoggedIn ? ($_SESSION['user_avatar_url'] ?? null) : null;
        $accountId = $isLoggedIn ? $_SESSION['user_id'] : null;
        $accountTheme = $isLoggedIn ? ($_SESSION['user_theme'] ?? 'system') : null;
        $accountPreferences = $isLoggedIn ? $this->accountPreferences() : [];
        if ($accountPreferences !== []) {
            $accountTheme = $accountPreferences['theme'] ?? $accountTheme;
            $_SESSION['user_theme'] = $accountTheme;
        }
        $language = $isLoggedIn
            ? ($accountPreferences['language'] ?? $_SESSION['user_language'] ?? 'pl')
            : ($_COOKIE['anivibe_language'] ?? 'pl');
        $translator = new Translator(is_string($language) ? $language : 'pl');
        Translator::setCurrent($translator);
        $locale = $translator->locale();
        $t = $translator->translate(...);
        $translateText = $translator->translateText(...);
        $languages = Translator::LANGUAGES;
        $csrfToken = $this->csrfToken();
        $unreadMessageCount = 0;
        $unreadReplyCount = 0;
        $isModerator = false;
        if ($isLoggedIn && $this->communityRepository !== null) {
            if ($this->communityRepository instanceof Closure) {
                $this->communityRepository = ($this->communityRepository)();
            }
            $communityState = $this->communityRepository->navigationStateForUser($accountId);
            $unreadMessageCount = $communityState['unreadCount'];
            $unreadReplyCount = $communityState['unreadReplyCount'];
            $isModerator = $communityState['isModerator'];
        }

        extract($variables, EXTR_SKIP);
        require $templatePath;
    }
}
