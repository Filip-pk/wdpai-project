<?php

declare(strict_types=1);

use App\Service\MailService;
use App\Service\Translator;

function smtpAssert(bool $condition, string $message = 'Warunek testu nie został spełniony.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smtpThrows(callable $action, ?string $messagePattern = null): void
{
    try {
        $action();
    } catch (RuntimeException $exception) {
        if ($messagePattern !== null) {
            smtpAssert(preg_match($messagePattern, $exception->getMessage()) === 1);
        }

        return;
    }

    throw new RuntimeException('Oczekiwano wyjątku RuntimeException.');
}

// Osobny proces pozwala testować prawdziwe gniazdo bez pcntl i zależności.
if (($argv[1] ?? '') === '--smtp') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    smtpAssert($server !== false, 'Nie udało się uruchomić testowego SMTP.');
    echo stream_socket_get_name($server, false) . PHP_EOL;
    fflush(STDOUT);
    $socket = stream_socket_accept($server, 5);
    smtpAssert($socket !== false, 'Klient nie połączył się z testowym SMTP.');
    stream_set_timeout($socket, 4);
    $scenario = $argv[2];
    $message = '';

    try {
        $expectCommand = static function (string $pattern) use ($socket): void {
            $line = fgets($socket);
            smtpAssert(is_string($line) && preg_match($pattern, $line) === 1, 'Nieprawidłowe polecenie SMTP.');
        };
        fwrite($socket, "220 fake.local ready\r\n");
        $expectCommand('/^EHLO [^\r\n]+\r\n$/D');

        if ($scenario === 'timeout') {
            sleep(2);
        } elseif ($scenario === 'inconsistent-reply') {
            fwrite($socket, "250-fake.local\r\n550 inconsistent final line\r\n");
        } elseif ($scenario === 'malformed-reply') {
            fwrite($socket, "250missing separator\r\n");
        } elseif ($scenario !== 'disconnect') {
            fwrite($socket, "250-fake.local\r\n250-8BITMIME\r\n250 SIZE 1048576\r\n");
            $expectCommand('/^MAIL FROM:<noreply@anivibe.local>\r\n$/D');
            fwrite($socket, "250 sender accepted\r\n");
            $expectCommand('/^RCPT TO:<lukasz@example.test>\r\n$/D');
            fwrite($socket, $scenario === 'reject-recipient' ? "550 recipient rejected\r\n" : "251 recipient forwarded\r\n");

            if ($scenario !== 'reject-recipient') {
                $expectCommand('/^DATA\r\n$/D');
                fwrite($socket, "354 send message\r\n");

                while (($line = fgets($socket)) !== false && $line !== ".\r\n") {
                    $message .= $line;
                }

                smtpAssert($line === ".\r\n", 'Brak zakończenia danych SMTP.');
                fwrite($socket, $scenario === 'reject-message' ? "550 message rejected\r\n" : "250 message queued\r\n");

                if (!in_array($scenario, ['reject-message', 'quit-disconnect'], true)) {
                    $expectCommand('/^QUIT\r\n$/D');
                    fwrite($socket, $scenario === 'quit-rejected' ? "500 quit rejected\r\n" : "221 bye\r\n");
                }
            }
        }

        echo json_encode(['message' => $message], JSON_THROW_ON_ERROR);
    } finally {
        fclose($socket);
        fclose($server);
    }

    exit(0);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

function withSmtp(string $scenario, callable $action): array
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--smtp', $scenario],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    smtpAssert(is_resource($process), 'Nie udało się uruchomić procesu testowego SMTP.');

    try {
        stream_set_timeout($pipes[1], 5);
        $address = trim((string) fgets($pipes[1]));
        smtpAssert(preg_match('/^127\.0\.0\.1:(\d+)$/D', $address, $matches) === 1, 'Brak portu testowego SMTP.');
        $mailer = new MailService('127.0.0.1', (int) $matches[1], 'noreply@anivibe.local', 'Zespół AniVibe', 1);
        $action($mailer);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $status = proc_close($process);
        $process = null;
        smtpAssert($status === 0, 'Testowy SMTP zakończył się błędem: ' . $errors);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

$passed = 0;
$failed = 0;
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
};

$run('Dialog SMTP, wieloliniowe EHLO, kod 251 i wiadomość UTF-8', static function (): void {
    $result = withSmtp('success', static fn (MailService $mailer) =>
        $mailer->sendWelcomeEmail('lukasz@example.test', 'Łukasz Żółć'));
    $message = $result['message'];
    smtpAssert(str_contains($message, "To: <lukasz@example.test>\r\n"));
    smtpAssert(str_contains($message, "MIME-Version: 1.0\r\n"));
    smtpAssert(str_contains($message, "Content-Type: text/plain; charset=UTF-8\r\n"));
    $headers = explode("\r\n\r\n", $message, 2)[0];
    $headers = preg_replace('/\r\n[ \t]+/', ' ', $headers) . "\r\n";
    $decodeHeader = static function (string $name) use ($headers): string {
        smtpAssert(preg_match('/^' . $name . ': ([^\r\n]*)\r\n/m', $headers, $match) === 1);
        smtpAssert(str_contains($match[1], '=?UTF-8?B?'));
        $value = preg_replace('/(\?=)[ \t]+(=\?UTF-8\?B\?)/i', '$1$2', $match[1]);

        return preg_replace_callback('/=\?UTF-8\?B\?([^?]+)\?=/i', static function (array $word): string {
            $decoded = base64_decode($word[1], true);
            smtpAssert($decoded !== false, 'Nagłówek musi zawierać poprawne Base64.');

            return $decoded;
        }, $value);
    };
    smtpAssert($decodeHeader('From') === 'Zespół AniVibe <noreply@anivibe.local>');
    smtpAssert($decodeHeader('Subject') === 'Witaj w AniVibe — cześć!');
    smtpAssert(str_contains($message, 'Łukasz Żółć'));
    smtpAssert(str_contains($message, "\r\n\r\n"));
    smtpAssert(preg_match('/(?<!\r)\n|\r(?!\n)/', $message) === 0, 'Wiadomość musi używać CRLF.');
});

$run('Potwierdzenie zmiany e-maila zawiera kod i instrukcję bez adresu obcego hosta', static function (): void {
    $code = str_repeat('a', 32);
    $result = withSmtp('success', static fn (MailService $mailer) =>
        $mailer->sendEmailChangeConfirmation('lukasz@example.test', $code));
    smtpAssert(str_contains($result['message'], 'Kod potwierdzenia: ' . $code));
    smtpAssert(str_contains($result['message'], '30 minut'));
    smtpAssert(!str_contains($result['message'], 'http://') && !str_contains($result['message'], 'https://'));
});

$mailLocales = [
    'pl' => ['Witaj w AniVibe — cześć!', 'Potwierdź zmianę adresu e-mail w AniVibe.'],
    'en' => ['Welcome to AniVibe — hello!', 'Confirm your email address change in AniVibe.'],
    'de' => ['Willkommen bei AniVibe — hallo!', 'Bestätige die Änderung deiner E-Mail-Adresse bei AniVibe.'],
    'fr' => ['Bienvenue sur AniVibe — bonjour !', 'Confirmez le changement de votre adresse e-mail sur AniVibe.'],
    'es' => ['¡Te damos la bienvenida a AniVibe!', 'Confirma el cambio de tu correo electrónico en AniVibe.'],
    'ru' => ['Добро пожаловать в AniVibe!', 'Подтвердите смену адреса почты в AniVibe.'],
];
foreach ($mailLocales as $locale => [$welcomeSubject, $confirmationIntro]) {
    $run('Obie wiadomości używają języka odbiorcy zamiast globalnego: ' . $locale, static function () use (
        $locale, $welcomeSubject, $confirmationIntro,
    ): void {
        $previous = Translator::current();
        try {
            Translator::setCurrent(new Translator($locale === 'pl' ? 'ru' : 'pl'));
            $name = 'Ustawienia <Ada> {code} Łucja';
            $welcome = withSmtp('success', static fn (MailService $mailer) =>
                $mailer->sendWelcomeEmail('lukasz@example.test', $name, $locale))['message'];
            $headers = explode("\r\n\r\n", $welcome, 2)[0];
            $headers = preg_replace('/\r\n[ \t]+/', ' ', $headers);
            smtpAssert(preg_match('/^Subject: (.*)$/m', $headers, $subject) === 1);
            $encoded = preg_replace('/(\?=)[ \t]+(=\?UTF-8\?B\?)/i', '$1$2', $subject[1]);
            $decoded = preg_replace_callback('/=\?UTF-8\?B\?([^?]+)\?=/i',
                static fn (array $part): string => (string) base64_decode($part[1], true), $encoded);
            smtpAssert(trim($decoded) === $welcomeSubject, 'Temat powitania musi być w języku odbiorcy.');
            smtpAssert(substr_count($welcome, $name) === 1, 'Nazwa odbiorcy pozostaje niezmieniona.');
            smtpAssert(preg_match('/(?<!\r)\n|\r(?!\n)/', $welcome) === 0, 'Wiadomość zachowuje CRLF.');

            $code = str_repeat('b', 32);
            $confirmation = withSmtp('success', static fn (MailService $mailer) =>
                $mailer->sendEmailChangeConfirmation('lukasz@example.test', $code, $locale))['message'];
            smtpAssert(str_contains($confirmation, $confirmationIntro), 'Instrukcja musi być w języku odbiorcy.');
            smtpAssert(substr_count($confirmation, $code) === 1, 'Kod pozostaje niezmieniony i występuje jeden raz.');
            smtpAssert(!str_contains($confirmation, '{code}') && str_contains($confirmation, '30'));
        } finally {
            Translator::setCurrent($previous);
        }
    });
}

$run('Domyślne API poczty pozostaje polskie niezależnie od języka interfejsu', static function (): void {
    $previous = Translator::current();
    try {
        Translator::setCurrent(new Translator('en'));
        $welcome = withSmtp('success', static fn (MailService $mailer) =>
            $mailer->sendWelcomeEmail('lukasz@example.test', 'Łucja'))['message'];
        smtpAssert(str_contains($welcome, 'Cześć Łucja!'));
        $confirmation = withSmtp('success', static fn (MailService $mailer) =>
            $mailer->sendEmailChangeConfirmation('lukasz@example.test', str_repeat('c', 32)))['message'];
        smtpAssert(str_contains($confirmation, 'Kod potwierdzenia: '));
    } finally {
        Translator::setCurrent($previous);
    }
});

$run('Nieprawidłowy kod potwierdzenia odrzucany jest przed połączeniem SMTP', static function (): void {
    $mailer = new MailService('127.0.0.1', 1, 'noreply@anivibe.local', 'AniVibe', 1);
    foreach (['', '12345', str_repeat('g', 32), str_repeat('a', 32) . "\r\nQUIT"] as $code) {
        smtpThrows(static fn () => $mailer->sendEmailChangeConfirmation('lukasz@example.test', $code), '/kod potwierdzenia/');
    }
});

$run('Znaki CRLF w imieniu nie dodają nagłówków ani poleceń', static function (): void {
    $result = withSmtp('success', static fn (MailService $mailer) =>
        $mailer->sendWelcomeEmail('lukasz@example.test', "Łukasz\r\nBcc: intruz@example.test\r\n.\r\nQUIT"));
    [$headers, $body] = explode("\r\n\r\n", $result['message'], 2);
    smtpAssert(!str_contains($headers, 'Bcc:'));
    smtpAssert(!str_contains($body, "\r\nBcc:"));
    smtpAssert(!str_contains($body, "\r\nQUIT"));
});

foreach (['quit-disconnect', 'quit-rejected'] as $scenario) {
    $run('Przyjęcie wiadomości pozostaje sukcesem mimo błędu QUIT: ' . $scenario, static function () use ($scenario): void {
        $result = withSmtp($scenario, static fn (MailService $mailer) =>
            $mailer->sendWelcomeEmail('lukasz@example.test', 'Łukasz'));
        smtpAssert(str_contains($result['message'], 'Łukasz'));
    });
}

foreach (['reject-recipient', 'reject-message', 'disconnect', 'timeout', 'inconsistent-reply', 'malformed-reply'] as $scenario) {
    $run('Kontrolowany błąd SMTP: ' . $scenario, static function () use ($scenario): void {
        withSmtp($scenario, static function (MailService $mailer): void {
            smtpThrows(static fn () => $mailer->sendWelcomeEmail('lukasz@example.test', 'Łukasz'));
        });
    });
}

$run('Brak serwera SMTP zwraca kontrolowany błąd połączenia', static function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    smtpAssert($server !== false);
    $address = stream_socket_get_name($server, false);
    fclose($server);
    $port = (int) substr($address, strrpos($address, ':') + 1);
    $mailer = new MailService('127.0.0.1', $port, 'noreply@anivibe.local', 'AniVibe', 1);
    smtpThrows(static fn () => $mailer->sendWelcomeEmail('lukasz@example.test', 'Łukasz'), '/połączyć z SMTP/');
});

$run('Nieprawidłowy adres odbiorcy jest odrzucany przed połączeniem', static function (): void {
    $mailer = new MailService('127.0.0.1', 1, 'noreply@anivibe.local', 'AniVibe', 1);

    foreach (['brak-adresu', "lukasz@example.test\r\nRCPT TO:<intruz@example.test>"] as $recipient) {
        smtpThrows(static fn () => $mailer->sendWelcomeEmail($recipient, 'Łukasz'), '/dane odbiorcy/');
    }
});

$run('Niebezpieczna konfiguracja SMTP jest odrzucana', static function (): void {
    foreach ([
        ["localhost\r\nEHLO intruz", 1025, 'noreply@anivibe.local', 'AniVibe', 1],
        ['localhost', 0, 'noreply@anivibe.local', 'AniVibe', 1],
        ['localhost', 65536, 'noreply@anivibe.local', 'AniVibe', 1],
        ['localhost', 1025, "noreply@anivibe.local\r\nBcc: intruz@example.test", 'AniVibe', 1],
        ['localhost', 1025, 'noreply@anivibe.local', 'AniVibe', 0],
    ] as $configuration) {
        smtpThrows(static fn () => (new MailService(...$configuration))
            ->sendWelcomeEmail('lukasz@example.test', 'Łukasz'), '/konfiguracja/');
    }
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.%s', $passed, $failed, PHP_EOL);
exit($failed === 0 ? 0 : 1);
