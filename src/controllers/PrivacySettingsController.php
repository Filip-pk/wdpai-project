<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PrivacySettingsRepository;
use App\Repository\UserRepository;
use Closure;

final class PrivacySettingsController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private PrivacySettingsRepository|Closure $privacy,
    ) {
    }

    public function save(): void
    {
        $this->requireLogin();
        $users = $this->users instanceof Closure ? ($this->users)() : $this->users;
        $user = $this->currentUser($users);
        $errors = [];

        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
        } else {
            $policy = $this->postString('messagePolicy');
            if (!in_array($policy, PrivacySettingsRepository::MESSAGE_POLICIES, true)) {
                $errors[] = 'Wybierz, kto może wysyłać do Ciebie wiadomości.';
            }
            foreach (['publicRatings', 'publicStatistics'] as $field) {
                if (!in_array($this->postString($field), ['0', '1'], true)) {
                    $errors[] = 'Wybierz widoczność ocen i statystyk.';
                    break;
                }
            }
            if ($errors === []) {
                $privacy = $this->privacy instanceof Closure ? ($this->privacy)() : $this->privacy;
                $privacy->saveForUser($user['id'], $policy,
                    $this->postString('publicRatings') === '1', $this->postString('publicStatistics') === '1');
            }
        }

        $_SESSION['privacy_feedback'] = $errors === []
            ? ['success' => 'Ustawienia prywatności zostały zapisane.']
            : ['errors' => $errors];
        $this->redirect('/settings#privacy');
    }
}
