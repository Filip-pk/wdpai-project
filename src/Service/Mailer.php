<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * The messages AniVibe sends. MailService delivers them over SMTP (Mailpit locally); another
 * transport only needs to implement this interface. Every method throws RuntimeException when
 * the message cannot be handed over, and callers decide whether that stops the operation.
 */
interface Mailer
{
    /** @throws RuntimeException */
    public function sendWelcomeEmail(string $to, string $fullName, string $locale = 'pl'): void;

    /** Sent instead of a welcome e-mail when someone registers an address that already has an account. */
    public function sendExistingAccountNotice(string $to, string $fullName, string $locale = 'pl'): void;

    /** @throws RuntimeException */
    public function sendEmailChangeConfirmation(string $to, string $code, string $locale = 'pl'): void;

    /** @throws RuntimeException */
    public function sendDiagnosticEmail(string $to, string $locale = 'pl'): void;
}
