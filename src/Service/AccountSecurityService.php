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
