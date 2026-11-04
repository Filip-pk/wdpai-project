<?php

declare(strict_types=1);

namespace App\Service;

final class MessageService
{
    public const MAX_LENGTH = 4000;

    public static function normalize(string $body): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $body), " \t\r\n");
    }

    /** @return list<string> */
    public static function validate(string $body): array
    {
        $length = preg_match_all('/./us', $body);
        if ($length === false) {
            return ['Podaj wiadomość jako poprawny tekst UTF-8.'];
        }

        $errors = [];
        if ($length < 1 || $length > self::MAX_LENGTH || preg_match('/[^\s\p{Z}]/u', $body) !== 1) {
            $errors[] = 'Wiadomość musi mieć od 1 do 4000 znaków i nie może składać się wyłącznie z białych znaków.';
        }
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $body) === 1) {
            $errors[] = 'Wiadomość nie może zawierać znaków sterujących innych niż tabulator i nowa linia.';
        }

        return $errors;
    }
}
