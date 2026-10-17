<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;

final class AnimeController extends AppController
{
    public function index(): void
    {
        $query = is_string($_GET['q'] ?? null)
            ? trim($_GET['q'])
            : '';

        $animes = DemoData::animes();

        if ($query !== '') {
            $animes = array_filter(
                $animes,
                static fn (array $anime): bool =>
                    stripos($anime['title'], $query) !== false,
            );
        }

        $this->render('anime-list', [
            'pageTitle' => 'Odkrywaj anime — AniVibe',
            'animes' => $animes,
            'query' => $query,
        ]);
    }

    public function show(int $id): void
    {
        $animes = DemoData::animes();

        if ($id < 1 || !isset($animes[$id])) {
            http_response_code(404);

            $this->render('not-found', [
                'pageTitle' => 'Nie znaleziono anime — AniVibe',
            ]);

            return;
        }

        $this->render('anime-details', [
            'pageTitle' => $animes[$id]['title'] . ' — AniVibe',
            'anime' => $animes[$id],
        ]);
    }
}