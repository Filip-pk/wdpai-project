<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\MailService;
use Closure;
use PDOException;
use RuntimeException;

final class AuthController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private readonly MailService $mailer,
    ) {
    }

    public function login(): void
    {
        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            $this->redirect('/');
        }

        $email = strtolower(trim($this->postString('email')));
        $errors = [];

        if ($this->isPost()) {
            $password = $this->postString('password');

            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
            } else {
                $user = $this->isEmailValid($email) ? $this->users()->findByEmail($email) : null;

                if ($user === null || !$this->isPasswordValid($password)
                    || !password_verify($password, $user['password_hash'])) {
                    http_response_code(401);
                    $errors[] = 'Nieprawidłowy e-mail lub hasło.';
                } else {
                    if (!session_regenerate_id(true)) {
                        throw new \RuntimeException('Nie udało się odnowić sesji.');
                    }

                    $_SESSION = [
                        'user_id' => (int) $user['id'],
                        'user_email' => (string) $user['email'],
                        'user_name' => (string) $user['name'],
                        'user_theme' => (string) $user['theme'],
                        'logged_in_at' => time(),
                    ];
                    $this->csrfToken();
                    $this->redirect('/projects');
                }
            }
        }

        $registered = !$this->isPost() && ($_GET['registered'] ?? null) === '1';
        $mailStatus = $registered && in_array($_GET['mail'] ?? null, ['sent', 'failed'], true)
            ? $_GET['mail'] : null;

        $this->render('login', [
            'pageTitle' => 'Logowanie — AniVibe',
            'errors' => $errors,
            'email' => $email,
            'registered' => $registered,
            'mailStatus' => $mailStatus,
        ]);
    }

    public function register(): void
    {
        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            $this->redirect('/');
        }

        $name = trim($this->postString('name'));
        $email = strtolower(trim($this->postString('email')));
        $errors = [];

        if ($this->isPost()) {
            $password = $this->postString('password');

            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = 'Formularz wygasł. Odśwież stronę i spróbuj ponownie.';
            } else {
                $nameLength = preg_match_all('/./us', $name);

                if ($nameLength === false || $nameLength < 1 || $nameLength > 120
                    || str_contains($name, "\0")) {
                    $errors[] = 'Podaj nazwę użytkownika o długości od 1 do 120 znaków.';
                }
                if (!$this->isEmailValid($email)) {
                    $errors[] = 'Podaj poprawny adres e-mail (maksymalnie 255 znaków).';
                }
                if (!$this->isPasswordValid($password)) {
                    $errors[] = 'Hasło musi mieć co najmniej 8 znaków i maksymalnie 72 bajty, bez znaku NUL.';
                }
                if ($password !== $this->postString('passwordConfirmation')) {
                    $errors[] = 'Hasła nie są takie same.';
                }

                if ($errors === []) {
                    if ($this->users()->findByEmail($email) !== null) {
                        $errors[] = 'Konto dla tego adresu już istnieje.';
                    } else {
                        try {
                            $this->users()->create($name, $email, password_hash($password, PASSWORD_DEFAULT));
                        } catch (PDOException $exception) {
                            if ($exception->getCode() !== '23505') {
                                throw $exception;
                            }

                            $errors[] = 'Konto dla tego adresu już istnieje.';
                        }
                    }
                }

                if ($errors === []) {
                    try {
                        $this->mailer->sendWelcomeEmail($email, $name);
                        $mailStatus = 'sent';
                    } catch (RuntimeException $exception) {
                        error_log('Nie udało się wysłać wiadomości powitalnej: ' . $exception->getMessage());
                        $mailStatus = 'failed';
                    }

                    $this->redirect('/login?registered=1&mail=' . $mailStatus);
                }

                http_response_code(422);
            }
        }

        $this->render('register', [
            'pageTitle' => 'Rejestracja — AniVibe',
            'errors' => $errors,
            'form' => ['name' => $name, 'email' => $email],
        ]);
    }

    public function logout(): void
    {
        if (!$this->isPost() || !$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->render('login', [
                'pageTitle' => 'Logowanie — AniVibe',
                'errors' => ['Formularz wygasł. Odśwież stronę i spróbuj ponownie.'],
            ]);

            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => $parameters['secure'],
                'httponly' => $parameters['httponly'],
                'samesite' => $parameters['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
        $this->redirect('/login');
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }

        return $this->users;
    }

    private function isEmailValid(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isPasswordValid(string $password): bool
    {
        $length = preg_match_all('/./us', $password);

        return $length !== false && $length >= 8 && strlen($password) <= 72
            && !str_contains($password, "\0");
    }
}
