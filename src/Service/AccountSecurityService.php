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

    public static function verifyPassword(string $password, ?string $hash): bool
    {
        return $hash !== null && self::isPasswordValid($password) && password_verify($password, $hash);
    }

    public static function isEmailValid(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/[\x00-\x20\x7F<>]/', $email) !== 1;
    }
}
