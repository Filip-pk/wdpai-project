<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\DemoData;

final class AdminController extends AppController
{
    public function index(): void
    {
        // Wyłącznie podgląd szablonu.
        // Przed dodaniem operacji zapisu wdrożymy autoryzację ADMIN.
        $this->render('admin-persona', [
            'pageTitle' => 'Kreator postaci AI — AniVibe',
            'personas' => DemoData::personas(),
            'animes' => DemoData::animes(),
        ]);
    }
}