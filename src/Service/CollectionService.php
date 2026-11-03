<?php

declare(strict_types=1);

namespace App\Service;

final class CollectionService
{
    /** @return list<string> */
    public static function validate(string $name, string $description): array
    {
        $errors = [];
        $nameLength = preg_match_all('/./us', $name);
        $descriptionLength = preg_match_all('/./us', $description);

        if ($nameLength === false || $nameLength < 1 || $nameLength > 120
            || preg_match('/\S/u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            $errors[] = 'Podaj nazwę kolekcji o długości od 1 do 120 znaków, bez znaków sterujących.';
        }
        if ($descriptionLength === false || $descriptionLength > 500 || str_contains($description, "\0")) {
            $errors[] = 'Opis kolekcji może mieć maksymalnie 500 znaków i nie może zawierać znaku NUL.';
        }

        return $errors;
    }
}
