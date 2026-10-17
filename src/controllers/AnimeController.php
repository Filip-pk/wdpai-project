<?php

declare(strict_types=1);

namespace App\Controller;

final class AnimeController extends AppController
{
    private const DEMO_ANIME = [
        1 => [
            'title' => 'Naruto',
            'description' => 'Historia młodego ninja, który marzy o zostaniu Hokage.',
        ],
        2 => [
            'title' => 'Death Note',
            'description' => 'Uczeń znajduje tajemniczy notes o nadnaturalnej mocy.',
        ],
    ];

    public function index(): void
    {
        $this->render('anime-list', [
            'pageTitle' => 'Katalog anime — AniVibe',
            'animes' => self::DEMO_ANIME,
        ]);
    }

    public function show(int $id): void
    {
        if ($id < 1 || !isset(self::DEMO_ANIME[$id])) {
            http_response_code(404);
            $this->render('not-found', [
                'pageTitle' => 'Nie znaleziono anime',
            ]);
            return;
        }

        $this->render('anime-details', [
            'pageTitle' => self::DEMO_ANIME[$id]['title'] . ' — AniVibe',
            'anime' => self::DEMO_ANIME[$id],
        ]);
    }
}