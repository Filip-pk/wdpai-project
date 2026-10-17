<?php

declare(strict_types=1);

namespace App\Data;

final class DemoData
{
    public static function animes(): array
    {
        return [
            1 => [
                'title' => 'Naruto',
                'genres' => ['Akcja', 'Przygoda'],
                'description' => 'Młody ninja marzy o zostaniu Hokage i zdobyciu uznania swojej wioski.',
                'episodes' => 220,
                'score' => 8.3,
                'studio' => 'Pierrot',
                'year' => 2002,
            ],
            2 => [
                'title' => 'Death Note',
                'genres' => ['Thriller', 'Psychologiczne'],
                'description' => 'Odkrycie tajemniczego notesu rozpoczyna pojedynek intelektów między jego właścicielem a detektywem.',
                'episodes' => 37,
                'score' => 8.6,
                'studio' => 'Madhouse',
                'year' => 2006,
            ],
            3 => [
                'title' => 'Cyberpunk: Edgerunners',
                'genres' => ['Sci-Fi', 'Akcja', 'Cyberpunk'],
                'description' => 'W Night City David Martinez dołącza do grupy najemników i próbuje przetrwać w świecie cybernetycznych modyfikacji oraz potężnych korporacji.',
                'episodes' => 10,
                'score' => 8.6,
                'studio' => 'Trigger',
                'year' => 2022,
            ],
            4 => [
                'title' => 'Steins;Gate',
                'genres' => ['Sci-Fi', 'Thriller'],
                'description' => 'Eksperymenty grupy przyjaciół prowadzą do odkrycia możliwości zmieniania przeszłości.',
                'episodes' => 24,
                'score' => 9.1,
                'studio' => 'White Fox',
                'year' => 2011,
            ],
            5 => [
                'title' => 'Attack on Titan',
                'genres' => ['Akcja', 'Mroczne fantasy'],
                'description' => 'Ludzkość ukryta za gigantycznymi murami staje w obliczu ataku tytanów. Dane dotyczą pierwszego sezonu.',
                'episodes' => 25,
                'score' => 8.7,
                'studio' => 'Wit Studio',
                'year' => 2013,
            ],
            6 => [
                'title' => 'Chainsaw Man',
                'genres' => ['Akcja', 'Horror'],
                'description' => 'Denji zostaje wciągnięty w niebezpieczny świat łowców demonów.',
                'episodes' => 12,
                'score' => 8.6,
                'studio' => 'MAPPA',
                'year' => 2022,
            ],
        ];
    }

    public static function user(): array
    {
        return [
            'name' => 'Kacper',
            'email' => 'kacper@example.com',
            'role' => 'USER',
            'aiUsed' => 6,
            'aiLimit' => 10,
        ];
    }

    public static function personas(): array
    {
        return [
            1 => [
                'name' => 'Naruto Uzumaki',
                'anime' => 'Naruto',
            ],
            2 => [
                'name' => 'L Lawliet',
                'anime' => 'Death Note',
            ],
            3 => [
                'name' => 'Mikasa Ackerman',
                'anime' => 'Attack on Titan',
            ],
            4 => [
                'name' => 'Goku',
                'anime' => 'Dragon Ball',
            ],
        ];
    }

    public static function watchlist(): array
    {
        return [
            [
                'animeId' => 3,
                'status' => 'Oglądam',
                'watched' => 8,
            ],
            [
                'animeId' => 4,
                'status' => 'Ukończone',
                'watched' => 24,
            ],
            [
                'animeId' => 5,
                'status' => 'Planowane',
                'watched' => 0,
            ],
            [
                'animeId' => 6,
                'status' => 'Oglądam',
                'watched' => 9,
            ],
        ];
    }
}