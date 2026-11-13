<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SecuritySettingsRepository;
use App\Repository\UserRepository;
use App\Service\AccountSecurityService;
use App\Service\MailService;
use App\Service\Translator;
use Closure;
use PDOException;
use RuntimeException;

final class SecuritySettingsController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private SecuritySettingsRepository|Closure $security,
        private readonly MailService $mailer,
    ) {
    }

    public function changePassword(): void
    {
        $user = $this->currentUser($this->users());
        if (!$this->validCsrf()) {
            return;
        }

        $oldHash = $this->security()->passwordHashForUser($user['id']);
        $password = $this->postString('newPassword');
        $errors = [];
        if (!AccountSecurityService::verifyPassword($this->postString('currentPassword'), $oldHash)) {
            $errors[] = Translator::message('Podaj poprawne obecne hasło.');
        }
        if (!AccountSecurityService::isPasswordValid($password)) {
            $errors[] = Translator::message('Nowe hasło musi mieć co najmniej 8 znaków i maksymalnie 72 bajty, bez znaku NUL.');
        }
        if ($password !== $this->postString('newPasswordConfirmation')) {
            $errors[] = Translator::message('Nowe hasła nie są takie same.');
        }

        if ($errors === [] && !$this->security()->changePassword(
            $user['id'], $oldHash, password_hash($password, PASSWORD_DEFAULT),
        )) {
            $errors[] = Translator::message('Dane konta zmieniły się w międzyczasie. Podaj aktualne hasło i spróbuj ponownie.');
        }
        if ($errors !== []) {
            $this->renderErrors($errors);
            return;
        }

        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Nie udało się odnowić sesji.');
        }
        $_SESSION['security_success'] = 'Hasło zostało zmienione. Przy kolejnym logowaniu użyj nowego hasła.';
        $this->redirect('/settings#security');
    }

    public function requestEmailChange(): void
    {
        $user = $this->currentUser($this->users());
        if (!$this->validCsrf()) {
            return;
        }

        $email = strtolower(trim($this->postString('newEmail')));
        $hash = $this->security()->passwordHashForUser($user['id']);
        $errors = [];
        if (!AccountSecurityService::verifyPassword($this->postString('currentPassword'), $hash)) {
            $errors[] = Translator::message('Potwierdź zmianę e-maila poprawnym obecnym hasłem.');
        }
        if (!AccountSecurityService::isEmailValid($email)) {
            $errors[] = Translator::message('Podaj poprawny nowy adres e-mail (maksymalnie 255 znaków).');
        }
        if ($email !== strtolower(trim($this->postString('newEmailConfirmation')))) {
            $errors[] = Translator::message('Nowe adresy e-mail nie są takie same.');
        }
        if ($email === strtolower($user['email'])) {
            $errors[] = Translator::message('Podaj adres e-mail inny niż obecny.');
        }
        if ($errors === [] && $this->users()->findByEmail($email) !== null) {
            $errors[] = Translator::message('Konto dla tego adresu już istnieje.');
        }
        if ($errors !== []) {
            $this->renderErrors($errors, email: $email);
            return;
        }

        $code = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $code);
        if (!$this->security()->requestEmailChange($user['id'], $email, $tokenHash, $hash)) {
            $this->renderErrors([Translator::message('Przed wysłaniem kolejnego kodu odczekaj minutę.')], 429, $email);
            return;
        }
        try {
            $this->mailer->sendEmailChangeConfirmation($email, $code, $user['language'] ?? 'pl');
        } catch (RuntimeException) {
            $this->security()->cancelEmailChange($user['id'], $tokenHash);
            $this->renderErrors([Translator::message('Nie udało się wysłać kodu potwierdzenia. Adres nie został zmieniony. Spróbuj ponownie.')], 503, $email);
            return;
        }

        $_SESSION['security_success'] = 'Kod potwierdzenia został wysłany na nowy adres. Dotychczasowy e-mail pozostaje aktywny do zatwierdzenia kodu.';
        $this->redirect('/settings/email/confirm');
    }

    public function confirmEmail(): void
    {
        $user = $this->currentUser($this->users());
        $errors = [];
        if ($this->isPost()) {
            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = Translator::message('Formularz wygasł. Odśwież stronę i spróbuj ponownie.');
            } else {
                $code = trim($this->postString('confirmationCode'));
                $email = null;
                try {
                    if (preg_match('/\A[a-f0-9]{32}\z/', $code) === 1) {
                        $email = $this->security()->confirmEmailChange($user['id'], hash('sha256', $code));
                    }
                } catch (PDOException $exception) {
                    if ($exception->getCode() !== '23505') {
                        throw $exception;
                    }
                    $errors[] = Translator::message('Ten adres jest już zajęty. Wybierz inny adres e-mail w ustawieniach.');
                }
                if ($email !== null) {
                    $_SESSION['user_email'] = $email;
                    $_SESSION['security_success'] = 'Nowy adres e-mail został potwierdzony i zapisany.';
                    $this->redirect('/settings#security');
                }
                if ($errors === []) {
                    $errors[] = Translator::message('Kod jest niepoprawny, wygasł albo został już użyty. Po zmianie hasła poproś o nowy kod.');
                }
                http_response_code(422);
            }
        }
        $this->render('settings-confirm-email', [
            'pageTitle' => Translator::message('Potwierdź e-mail — AniVibe'), 'securityErrors' => $errors,
            'pendingEmail' => $this->security()->pendingEmailForUser($user['id']),
        ]);
    }

    private function validCsrf(): bool
    {
        if ($this->isPost() && $this->isCsrfTokenValid($this->postString('csrfToken'))) {
            return true;
        }
        $this->renderErrors([Translator::message('Formularz wygasł. Odśwież stronę i spróbuj ponownie.')], 403);
        return false;
    }

    private function renderErrors(array $errors, int $status = 422, string $email = ''): void
    {
        http_response_code($status);
        $this->render('settings-security', [
            'pageTitle' => Translator::message('Konto i bezpieczeństwo — AniVibe'),
            'securityErrors' => $errors, 'newEmail' => $email,
        ]);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function security(): SecuritySettingsRepository
    {
        if ($this->security instanceof Closure) {
            $this->security = ($this->security)();
        }
        return $this->security;
    }
}
