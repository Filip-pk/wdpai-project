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
            $errors[] = Translator::current()->translate('message.collection.name_length');
        }
        if ($descriptionLength === false || $descriptionLength > 500 || str_contains($description, "\0")) {
            $errors[] = Translator::current()->translate('message.collection.description_length');
        }

        return $errors;
    }
}
