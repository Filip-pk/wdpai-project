<?php

declare(strict_types=1);

use App\Database;
use App\Model\Project;
use App\Repository\ProjectRepository;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$connection = null;

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $repository = new ProjectRepository($connection);
    $existingTitles = [];

    foreach ($repository->findAll() as $project) {
        $existingTitles[$project->getTitle()] = true;
    }

    $projects = [
        new Project(
            null,
            'AniVibe — rekomendacje anime',
            'Projekt ćwiczeniowy: przygotowanie listy rekomendacji anime.',
            'planned',
        ),
        new Project(
            null,
            'AniVibe — rozmowy z postaciami',
            'Projekt ćwiczeniowy: opracowanie rozmów z wirtualnymi postaciami.',
            'active',
        ),
        new Project(
            null,
            'AniVibe — katalog anime',
            'Projekt ćwiczeniowy: uporządkowanie katalogu anime.',
            'completed',
        ),
    ];
    $added = 0;
    $skipped = 0;

    foreach ($projects as $project) {
        if (isset($existingTitles[$project->getTitle()])) {
            $skipped++;
            continue;
        }

        $repository->save($project);
        $existingTitles[$project->getTitle()] = true;
        $added++;
    }

    $connection->commit();

    echo sprintf(
        '[OK] Dodano projektów: %d. Pominięto istniejących: %d.',
        $added,
        $skipped,
    ) . PHP_EOL;
} catch (Throwable) {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        try {
            $connection->rollBack();
        } catch (Throwable) {
            // Zakończenie procesu zamknie również połączenie testowanej operacji.
        }
    }

    fwrite(
        STDERR,
        '[BŁĄD] Nie udało się dodać przykładowych projektów. Sprawdź dostępność bazy i konfigurację.' . PHP_EOL,
    );
    exit(1);
}
