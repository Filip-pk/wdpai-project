<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;

final class DashboardController extends AppController
{
    public function index(): void
    {
        $animes = DemoData::animes();

        $this->render('dashboard', [
            'pageTitle' => 'Twój pulpit — AniVibe',
            'user' => DemoData::user(),
            'personas' => DemoData::personas(),
            'recommendations' => [
                3 => $animes[3],
                5 => $animes[5],
            ],
        ]);
    }
}