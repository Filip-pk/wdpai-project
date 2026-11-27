<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\AllowedMethods;
use App\Repository\UserRepository;
use App\Service\AccountSecurityService;
use App\Service\ApplicationLogger;
use App\Service\LoginThrottle;
use App\Service\MailService;
use App\Service\Translator;
use Closure;
use PDOException;
use RuntimeException;

final class AuthController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private readonly MailService $mailer,
        private LoginThrottle|Closure|null $throttle = null,
        private readonly ?ApplicationLogger $logger = null,
    ) {
    }

    /** GET only renders the form; credentials are read only from a POST. */
    #[AllowedMethods('GET', 'POST')]
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
                $errors[] = Translator::message('Formularz wygasł. Odśwież stronę i spróbuj ponownie.');
            } else {
                $retryAfter = $this->throttle()?->retryAfter($email, $this->clientAddress()) ?? 0;
                $user = $retryAfter === 0 && $this->isEmailValid($email) ? $this->users()->findByEmail($email) : null;

                if ($retryAfter > 0) {
                    // The limit applies before the password check, so a correct password does not reveal itself.
                    http_response_code(429);
                    header('Retry-After: ' . $retryAfter);
                    $this->auditSignIn('admin.log.login_throttled', $email);
                    $errors[] = Translator::message(
                        'Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za {minutes} min.',
                        ['minutes' => (int) ceil($retryAfter / 60)],
                    );
                } elseif ($user === null || !AccountSecurityService::isPasswordValid($password)
                    || !password_verify($password, $user['password_hash'])) {
                    $this->throttle()?->recordFailure($email, $this->clientAddress());
                    $this->auditSignIn('admin.log.login_failed', $email);
                    http_response_code(401);
                    $errors[] = Translator::message('Nieprawidłowy e-mail lub hasło.');
                } else {
                    $this->throttle()?->recordSuccess($email, $this->clientAddress());
                    if (!session_regenerate_id(true)) {
                        throw new \RuntimeException('Nie udało się odnowić sesji.');
                    }

                    $_SESSION = [
                        'user_id' => (int) $user['id'],
                        'user_email' => (string) $user['email'],
                        'user_name' => (string) $user['name'],
                        'user_theme' => (string) $user['theme'],
                        'user_language' => (string) ($user['language'] ?? 'pl'),
                        'user_avatar_url' => $user['avatar_url'],
                        'logged_in_at' => time(),
                    ];
                    $this->csrfToken();
                    if ($this->accountModeration()?->activeBanForUser((int) $user['id']) !== null) {
                        $this->redirect('/account/banned');
                    }
                    $this->redirect('/projects');
                }
            }
        }

        $registered = !$this->isPost() && ($_GET['registered'] ?? null) === '1';
        $mailStatus = $registered && in_array($_GET['mail'] ?? null, ['sent', 'failed'], true)
            ? $_GET['mail'] : null;

        $this->render('login', [
            'pageTitle' => Translator::message('Logowanie — AniVibe'),
            'errors' => $errors,
            'email' => $email,
            'registered' => $registered,
            'mailStatus' => $mailStatus,
        ]);
    }

    /** GET only renders the form; the account is created only from a POST. */
    #[AllowedMethods('GET', 'POST')]
    public function register(): void
    {
        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            $this->redirect('/');
        }

        $name = trim($this->postString('name'));
        $email = strtolower(trim($this->postString('email')));
        $language = Translator::current()->locale();
        $errors = [];

        if ($this->isPost()) {
            $password = $this->postString('password');

            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = Translator::message('Formularz wygasł. Odśwież stronę i spróbuj ponownie.');
            } else {
                $nameLength = preg_match_all('/./us', $name);

                if ($nameLength === false || $nameLength < 1 || $nameLength > 120
                    || str_contains($name, "\0")) {
                    $errors[] = Translator::message('Podaj nazwę użytkownika o długości od 1 do 120 znaków.');
                }
                if (!$this->isEmailValid($email)) {
                    $errors[] = Translator::message('Podaj poprawny adres e-mail (maksymalnie 255 znaków).');
                }
                if (!AccountSecurityService::isPasswordStrong($password, $email)) {
                    $errors[] = Translator::message('Hasło musi mieć co najmniej 8 znaków i maksymalnie 72 bajty, zawierać literę i cyfrę, nie może zawierać znaku NUL ani być adresem e-mail.');
                }
                if ($password !== $this->postString('passwordConfirmation')) {
                    $errors[] = Translator::message('Hasła nie są takie same.');
                }

                if ($errors === []) {
                    // The response is the same whether or not the address already has an account, so the form
                    // cannot be used to discover accounts; the password is hashed in both cases to match timing.
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $existingAccount = $this->users()->findByEmail($email);
                    if ($existingAccount === null) {
                        try {
                            $this->users()->create($name, $email, $passwordHash, $language);
                        } catch (PDOException $exception) {
                            if ($exception->getCode() !== '23505') {
                                throw $exception;
                            }

                            $existingAccount = $this->users()->findByEmail($email);
                        }
                    }

                    try {
                        if ($existingAccount === null) {
                            $this->mailer->sendWelcomeEmail($email, $name, $language);
                        } else {
                            $this->mailer->sendExistingAccountNotice(
                                $existingAccount['email'], $existingAccount['name'], $existingAccount['language'],
                            );
                        }
                        $mailStatus = 'sent';
                    } catch (RuntimeException $exception) {
                        $this->logger()->recordException($exception, 'mail', '/register');
                        $mailStatus = 'failed';
                    }

                    $this->redirect('/login?registered=1&mail=' . $mailStatus);
                }

                http_response_code(422);
            }
        }

        $this->render('register', [
            'pageTitle' => Translator::message('Rejestracja — AniVibe'),
            'errors' => $errors,
            'form' => ['name' => $name, 'email' => $email],
        ]);
    }

    #[AllowedMethods('POST')]
    public function logout(): void
    {
        if (!$this->isPost() || !$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->render('login', [
                'pageTitle' => Translator::message('Logowanie — AniVibe'),
                'errors' => [Translator::message('Formularz wygasł. Odśwież stronę i spróbuj ponownie.')],
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

    private function throttle(): ?LoginThrottle
    {
        if ($this->throttle instanceof Closure) {
            $this->throttle = ($this->throttle)();
        }

        return $this->throttle;
    }

    private function logger(): ApplicationLogger
    {
        return $this->logger ?? new ApplicationLogger(dirname(__DIR__, 2) . '/storage/logs');
    }

    private function clientAddress(): string
    {
        return is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
    }

    /** Records a sign-in event with shortened hashes only: no password, e-mail or IP address. */
    private function auditSignIn(string $messageKey, string $email): void
    {
        $this->logger()->record('warning', 'auth', $messageKey, [
            'path' => '/login',
            'account' => substr(LoginThrottle::fingerprint($email), 0, 16),
            'client' => substr(LoginThrottle::fingerprint($this->clientAddress()), 0, 16),
        ]);
    }

    private function isEmailValid(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
