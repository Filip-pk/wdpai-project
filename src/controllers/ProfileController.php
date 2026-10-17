<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;

final class ProfileController extends AppController
{
    public function index(): void
    {
        $this->renderProfile(false);
    }

    public function watchlist(): void
    {
        $this->renderProfile(true);
    }

    private function renderProfile(bool $watchlistOnly): void
    {
        $watchlist = DemoData::watchlist();

        $completedCount = count(array_filter(
                $watchlist,
                static fn (array $entry): bool =>
                    $entry['status'] === 'Ukończone',
        ));

        $this->render('profile', [
            'pageTitle' => ($watchlistOnly ? 'Watchlista' : 'Twój profil')
                . ' — AniVibe',
            'watchlistOnly' => $watchlistOnly,
            'user' => DemoData::user(),
            'animes' => DemoData::animes(),
            'watchlist' => $watchlist,
            'completedCount' => $completedCount,
        ]);
    }
}