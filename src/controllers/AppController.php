<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;

abstract class AppController
{
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
        $accountId = $isLoggedIn ? $_SESSION['user_id'] : null;
        $accountTheme = $isLoggedIn ? ($_SESSION['user_theme'] ?? 'system') : null;
        $csrfToken = $this->csrfToken();

        extract($variables, EXTR_SKIP);
        require $templatePath;
    }
}
