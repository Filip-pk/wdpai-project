<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PreferencesRepository;
use App\Service\Translator;
use Closure;

final class LanguageController extends AppController
{
    public function __construct(private PreferencesRepository|Closure $preferences)
    {
    }

    public function save(): void
    {
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo Translator::current()->translate('language.expired');
            return;
        }

        $language = $this->postString('language');
        if (!Translator::isSupported($language)) {
            http_response_code(422);
            header('Content-Type: text/plain; charset=UTF-8');
            echo Translator::current()->translate('language.invalid');
            return;
        }

        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            if ($this->preferences instanceof Closure) {
                $this->preferences = ($this->preferences)();
            }
            $this->preferences->saveLanguage($_SESSION['user_id'], $language);
            $_SESSION['user_language'] = $language;
        }

        setcookie('anivibe_language', $language, [
            'expires' => time() + 365 * 24 * 60 * 60,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $returnTo = $this->postString('returnTo');
        $decoded = rawurldecode($returnTo);
        if (!str_starts_with($decoded, '/') || str_starts_with($decoded, '//')
            || preg_match('/[\\\\\x00-\x1f\x7f]/', $decoded) === 1
            || preg_match('/[\x00-\x20\x7f]/', $returnTo) === 1) {
            $returnTo = '/';
        }
        $this->redirect($returnTo);
    }
}
