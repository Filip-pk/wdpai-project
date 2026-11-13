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
            return [Translator::current()->translate('message.comment.utf8')];
        }

        $errors = [];
        if ($length < 1 || $length > self::MAX_LENGTH || preg_match('/[^\s\p{Z}]/u', $body) !== 1) {
            $errors[] = Translator::current()->translate('message.comment.length');
        }
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $body) === 1) {
            $errors[] = Translator::current()->translate('message.comment.controls');
        }

        return $errors;
    }
}
