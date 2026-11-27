<?php

declare(strict_types=1);

namespace App\Service;

final class AccountSecurityService
{
    public static function isPasswordValid(string $password): bool
    {
        $length = preg_match_all('/./us', $password);

        return $length !== false && $length >= 8 && strlen($password) <= 72 && !str_contains($password, "\0");
    }

    /**
     * Rules for a new password: the limits above, at least one letter and one digit,
     * and not the account's e-mail address or its local part. Existing passwords are
     * still verified with isPasswordValid(), so older accounts can sign in.
     */
    public static function isPasswordStrong(string $password, string $email = ''): bool
    {
        if (!self::isPasswordValid($password)
            || preg_match('/\p{L}/u', $password) !== 1
            || preg_match('/\p{N}/u', $password) !== 1) {
            return false;
        }

        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return true;
        }
        $password = mb_strtolower($password);
        $localPart = strstr($email, '@', true);

        return $password !== $email && ($localPart === false || $password !== $localPart);
    }

    /**
     * bcrypt hash (PASSWORD_DEFAULT cost) of a random password nobody knows. Verifying against it
     * makes a missing account take as long as a wrong password, so timing does not reveal accounts.
     */
    public const UNKNOWN_ACCOUNT_HASH = '$2y$10$WU2y/WDY5h.3L.SFp2DPK.AW8aVybw3A5GmWh2b48PQJ2TaDhJKJG';

    public static function verifyPassword(string $password, ?string $hash): bool
    {
        if (!self::isPasswordValid($password)) {
            return false;
        }
        $matches = password_verify($password, $hash ?? self::UNKNOWN_ACCOUNT_HASH);

        return $hash !== null && $matches;
    }

    /**
     * The one e-mail rule for sign-in, registration, profile, settings and outgoing mail. Besides the
     * format it refuses spaces, control characters and angle brackets, which PHP accepts in quoted
     * local parts but which must never reach an SMTP command or a mail header.
     */
    public static function isEmailValid(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/[\x00-\x20\x7F<>]/', $email) !== 1;
    }
}
