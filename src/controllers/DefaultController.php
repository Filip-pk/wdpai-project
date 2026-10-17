<?php

declare(strict_types=1);

namespace App\Controller;

final class DefaultController extends AppController
{
    public function index(): void
    {
        $this->render('home', [
            'pageTitle' => 'AniVibe — odkryj swoje następne anime',
            'appName' => 'AniVibe',
            'tagline' => 'Twój Inteligentny Przewodnik po Anime',
        ]);
    }

    public function notFound(): void
    {
        $this->render('not-found', [
            'pageTitle' => 'Nie znaleziono strony',
        ]);
    }
}