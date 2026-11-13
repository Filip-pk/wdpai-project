<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PreferencesRepository;
use App\Repository\PrivacySettingsRepository;
use App\Repository\UserRepository;
use App\Service\PreferencesService;
use Closure;

final class SettingsController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private PreferencesRepository|Closure $preferences,
        private PrivacySettingsRepository|Closure $privacy,
    ) {
    }

    public function index(): void
    {
        $user = $this->currentUser($this->users());
        $form = $this->preferences()->forUser($user['id']);
        $errors = [];

        if ($this->isPost()) {
            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
            } else {
                foreach (PreferencesService::APPEARANCE as $key => $options) {
                    $form[$key] = $this->postString($key);
                }
                $errors = PreferencesService::validate($form, PreferencesService::APPEARANCE);
                if ($errors === []) {
                    $this->preferences()->saveAppearance($user['id'], $form);
                    $_SESSION['user_theme'] = $form['theme'];
                    $_SESSION['settings_notice'] = 'Wygląd został zapisany na Twoim koncie.';
                    $this->redirect('/settings#appearance');
                }
                http_response_code(422);
            }
        }

        $this->renderSettings($user, $form, $errors);
    }

    public function resetAppearance(): void
    {
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->renderSettings($user, $this->preferences()->forUser($user['id']), [
                'Formularz wygasł. Odśwież stronę i spróbuj ponownie.',
            ]);
            return;
        }
        $this->preferences()->saveAppearance($user['id'], PreferencesService::DEFAULTS);
        $_SESSION['user_theme'] = 'system';
        $_SESSION['settings_notice'] = 'Przywrócono domyślny wygląd.';
        $this->redirect('/settings#appearance');
    }

    public function accessibility(): void
    {
        $user = $this->currentUser($this->users());
        $form = $this->preferences()->forUser($user['id']);
        $errors = [];
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
        } else {
            foreach (PreferencesService::ACCESSIBILITY as $key => $options) {
                $form[$key] = $this->postString($key);
            }
            $errors = PreferencesService::validate($form, PreferencesService::ACCESSIBILITY);
            if ($errors === []) {
                $this->preferences()->saveAccessibility($user['id'], $form);
                $_SESSION['settings_notice'] = 'Ustawienia dostępności zostały zapisane.';
                $this->redirect('/settings#accessibility');
            }
            http_response_code(422);
        }
        $this->renderSettings($user, $form, $errors);
    }

    private function renderSettings(array $user, array $form, array $errors): void
    {
        if ($this->privacy instanceof Closure) {
            $this->privacy = ($this->privacy)();
        }
        $this->render('settings', [
            'pageTitle' => 'Ustawienia — AniVibe', 'user' => $user,
            'form' => $form, 'errors' => $errors,
            'appearanceChoices' => PreferencesService::APPEARANCE,
            'accessibilityChoices' => PreferencesService::ACCESSIBILITY,
            'privacySettings' => $this->privacy->findForUser($user['id']),
        ]);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function preferences(): PreferencesRepository
    {
        if ($this->preferences instanceof Closure) {
            $this->preferences = ($this->preferences)();
        }
        return $this->preferences;
    }
}
