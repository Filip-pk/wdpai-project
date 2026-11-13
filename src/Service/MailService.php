<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class MailService
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $from,
        private readonly string $fromName,
        private readonly int $timeout = 5,
    ) {
    }

    public function sendWelcomeEmail(string $to, string $fullName): void
    {
        $this->validateConfiguration();

        if (!$this->isEmailValid($to) || preg_match('//u', $fullName) !== 1) {
            throw new RuntimeException('Nieprawidłowe dane odbiorcy wiadomości.');
        }

        $safeName = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $fullName) ?? '');
        $body = "Cześć {$safeName}!\r\n\r\n"
            . "Twoje konto w AniVibe zostało utworzone. Możesz się już zalogować.\r\n\r\n"
            . "Odkrywaj anime i twórz własną watchlistę.\r\n\r\n"
            . "Pozdrawiamy,\r\nZespół AniVibe";
        $this->sendMessage($to, 'Witaj w AniVibe — cześć!', $body);
    }

    public function sendEmailChangeConfirmation(string $to, string $code): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $code) !== 1) {
            throw new RuntimeException('Nieprawidłowy kod potwierdzenia.');
        }

        $this->sendMessage($to, 'AniVibe — potwierdź nowy adres e-mail',
            "Potwierdź zmianę adresu e-mail w AniVibe.\r\n\r\n"
            . "Kod potwierdzenia: {$code}\r\n\r\n"
            . "Zaloguj się na dotychczasowe konto i otwórz Ustawienia → Konto i bezpieczeństwo → Potwierdź e-mail.\r\n"
            . "Wklej kod do formularza. Kod jest ważny przez 30 minut i działa tylko raz.\r\n\r\n"
            . "Jeśli nie prosisz o tę zmianę, zignoruj wiadomość. Dotychczasowy adres pozostanie aktywny.\r\n"
        );
    }

    private function sendMessage(string $to, string $subject, string $body): void
    {
        $this->validateConfiguration();
        if (!$this->isEmailValid($to)) {
            throw new RuntimeException('Nieprawidłowe dane odbiorcy wiadomości.');
        }

        $socket = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, $this->timeout);

        if ($socket === false) {
            throw new RuntimeException("Nie udało się połączyć z SMTP (kod {$errorCode}): {$errorMessage}");
        }

        try {
            if (!stream_set_timeout($socket, $this->timeout)) {
                throw new RuntimeException('Nie udało się ustawić limitu czasu SMTP.');
            }

            $this->expect($socket, [220]);
            $this->command($socket, "EHLO anivibe.local\r\n", [250]);
            $this->command($socket, "MAIL FROM:<{$this->from}>\r\n", [250]);
            $this->command($socket, "RCPT TO:<{$to}>\r\n", [250, 251]);
            $this->command($socket, "DATA\r\n", [354]);

            $message = 'Date: ' . date(DATE_RFC2822) . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(16)) . "@anivibe.local>\r\n"
                . 'From: ' . $this->encodeHeader($this->fromName) . " <{$this->from}>\r\n"
                . "To: <{$to}>\r\n"
                . 'Subject: ' . $this->encodeHeader($subject) . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . preg_replace('/^\./m', '..', $body)
                . "\r\n.\r\n";

            $this->command($socket, $message, [250]);

            // Kod 250 po DATA potwierdza przyjęcie wiadomości niezależnie od QUIT.
            try {
                $this->command($socket, "QUIT\r\n", [221]);
            } catch (RuntimeException $exception) {
                error_log('SMTP przyjął wiadomość; błąd zakończenia połączenia: ' . $exception->getMessage());
            }
        } finally {
            fclose($socket);
        }
    }

    private function validateConfiguration(): void
    {
        if ($this->host === '' || strlen($this->host) > 253
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]*\z/', $this->host) !== 1
            || $this->port < 1 || $this->port > 65535 || $this->timeout < 1
            || !$this->isEmailValid($this->from)
            || trim($this->fromName) === '' || preg_match('//u', $this->fromName) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $this->fromName) === 1) {
            throw new RuntimeException('Nieprawidłowa konfiguracja poczty SMTP.');
        }
    }

    private function isEmailValid(string $email): bool
    {
        return strlen($email) <= 255
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/[\x00-\x20\x7F<>]/', $email) !== 1;
    }

    /** @param resource $socket
     *  @param list<int> $expectedCodes
     */
    private function command($socket, string $command, array $expectedCodes): void
    {
        $length = strlen($command);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($socket, substr($command, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Nie udało się wysłać danych do SMTP.');
            }

            $offset += $written;
        }

        $this->expect($socket, $expectedCodes);
    }

    /** @param resource $socket
     *  @param list<int> $expectedCodes
     */
    private function expect($socket, array $expectedCodes): void
    {
        $responseCode = null;

        for ($lineCount = 0; $lineCount < 100; $lineCount++) {
            $line = @fgets($socket, 513);

            if ($line === false) {
                $timedOut = stream_get_meta_data($socket)['timed_out'];
                throw new RuntimeException($timedOut
                    ? 'Przekroczono czas oczekiwania na odpowiedź SMTP.'
                    : 'Serwer SMTP przerwał połączenie.');
            }

            if (preg_match('/\A([2-5][0-9]{2})([ -])[^\r\n]*\r\n\z/', $line, $matches) !== 1) {
                throw new RuntimeException('Nieprawidłowy format odpowiedzi SMTP.');
            }

            $code = (int) $matches[1];

            if ($responseCode !== null && $responseCode !== $code) {
                throw new RuntimeException('Niespójna wieloliniowa odpowiedź SMTP.');
            }

            $responseCode = $code;

            if ($matches[2] === ' ') {
                if (!in_array($code, $expectedCodes, true)) {
                    throw new RuntimeException("Serwer SMTP odrzucił polecenie (kod {$code}).");
                }

                return;
            }
        }

        throw new RuntimeException('Zbyt długa wieloliniowa odpowiedź SMTP.');
    }

    private function encodeHeader(string $value): string
    {
        preg_match_all('/.{1,10}/us', $value, $chunks);

        return implode("\r\n ", array_map(
            static fn (string $chunk): string => '=?UTF-8?B?' . base64_encode($chunk) . '?=',
            $chunks[0],
        ));
    }
}
