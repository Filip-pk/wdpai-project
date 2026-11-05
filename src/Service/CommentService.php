<?php

declare(strict_types=1);

namespace App\Service;

final class CommentService
{
    public const MAX_LENGTH = 2000;

    /** @return list<string> */
    public static function validate(string $body): array
    {
        $length = preg_match_all('/./us', $body);
        if ($length === false) {
            return ['Podaj komentarz jako poprawny tekst UTF-8.'];
        }

        $errors = [];
        if ($length < 1 || $length > self::MAX_LENGTH || preg_match('/[^\s\p{Z}]/u', $body) !== 1) {
            $errors[] = 'Komentarz musi mieć od 1 do 2000 znaków i nie może składać się wyłącznie z białych znaków.';
        }
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $body) === 1) {
            $errors[] = 'Komentarz nie może zawierać znaków sterujących innych niż tabulator i nowa linia.';
        }

        return $errors;
    }
}
