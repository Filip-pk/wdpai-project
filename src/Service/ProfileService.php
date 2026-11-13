<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserRepository;

final class ProfileService
{
    public const THEMES = ['system', 'light', 'dark'];

    public function __construct(private readonly UserRepository $users)
    {
    }

    /** @return list<string> */
    public function update(array $user, array $form): array
    {
        $errors = [];
        $nameLength = preg_match_all('/./us', $form['name']);
        $interestsLength = preg_match_all('/./us', $form['interests']);

        if ($nameLength === false || $nameLength < 1 || $nameLength > 120
            || str_contains($form['name'], "\0")) {
            $errors[] = 'Nazwa użytkownika musi mieć od 1 do 120 znaków.';
        }
        if (strlen($form['email']) > 255 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Podaj poprawny adres e-mail (maksymalnie 255 znaków).';
        }
        if ($interestsLength === false || $interestsLength > 1000
            || str_contains($form['interests'], "\0")) {
            $errors[] = 'Zainteresowania mogą mieć maksymalnie 1000 znaków.';
        }
        if (!in_array($form['theme'], self::THEMES, true)) {
            $errors[] = 'Wybierz motyw systemowy, jasny lub ciemny.';
        }

        if ($errors !== []) {
            return $errors;
        }

        if ($form['email'] !== strtolower($user['email'])) {
            $errors[] = 'Adres e-mail zmienisz w ustawieniach konta po potwierdzeniu nowego adresu kodem.';
        }

        if ($errors === []) {
            $this->users->updateProfile(
                $user['id'], $form['name'], $form['interests'], $form['theme'],
            );
        }

        return $errors;
    }
}
