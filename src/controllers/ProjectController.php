<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

use App\Repository\ProjectRepository;
use Closure;

final class ProjectController extends AppController
{
    private const PROJECT_STATUSES = [
        'planned' => 'Planowany',
        'active' => 'W trakcie',
        'completed' => 'Ukończony',
    ];

    public function __construct(private ProjectRepository|Closure $projectRepository)
    {
    }

    public function index(): void
    {
        $this->requireLogin();
        $this->render('projects', [
            'pageTitle' => Translator::current()->translate('message.page.projects'),
            'projects' => $this->projects()->findAll(),
            'projectStatuses' => self::PROJECT_STATUSES,
        ]);
    }

    public function show(int $id): void
    {
        $this->requireLogin();
        $project = $id > 0 ? $this->projects()->find($id) : null;

        if ($project === null) {
            http_response_code(404);
            $this->render('not-found', [
                'pageTitle' => Translator::current()->translate('message.page.project_missing'),
            ]);

            return;
        }

        $this->render('project', [
            'pageTitle' => $project->getTitle() . ' — AniVibe',
            'project' => $project,
            'projectStatuses' => self::PROJECT_STATUSES,
        ]);
    }

    private function projects(): ProjectRepository
    {
        if ($this->projectRepository instanceof Closure) {
            $this->projectRepository = ($this->projectRepository)();
        }

        return $this->projectRepository;
    }
}
