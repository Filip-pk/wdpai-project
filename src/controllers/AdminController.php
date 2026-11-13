<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;
use App\Data\DemoData;

final class AdminController extends AppController
{
    public function index(): void
    {
        // Wyłącznie podgląd szablonu.
        // Przed dodaniem operacji zapisu wdrożymy autoryzację ADMIN.
        $this->render('admin-persona', [
            'pageTitle' => Translator::current()->translate('message.page.admin'),
            'personas' => DemoData::personas(),
            'animes' => DemoData::animes(),
        ]);
    }
}