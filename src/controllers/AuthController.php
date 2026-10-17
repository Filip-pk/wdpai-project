<?php

declare(strict_types=1);

namespace App\Controller;

final class AuthController extends AppController
{
    public function login(): void
    {
        $this->render('login', [
            'pageTitle' => 'Logowanie — AniVibe',
        ]);
    }
}