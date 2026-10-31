<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProjectRepository;

final class ProjectController extends AppController
{
    private const PROJECT_STATUSES = [
        'planned' => 'Planowany',
        'active' => 'W trakcie',
        'completed' => 'Ukończony',
    ];

    public function __construct(private readonly ProjectRepository $projectRepository)
    {
    }

    public function index(): void
    {
        $this->render('projects', [
            'pageTitle' => 'Projekty — AniVibe',
            'projects' => $this->projectRepository->findAll(),
            'projectStatuses' => self::PROJECT_STATUSES,
        ]);
    }

    public function show(int $id): void
    {
        $project = $id > 0 ? $this->projectRepository->find($id) : null;

        if ($project === null) {
            http_response_code(404);
            $this->render('not-found', [
                'pageTitle' => 'Nie znaleziono projektu — AniVibe',
            ]);

            return;
        }

        $this->render('project', [
            'pageTitle' => $project->getTitle() . ' — AniVibe',
            'project' => $project,
            'projectStatuses' => self::PROJECT_STATUSES,
        ]);
    }
}
