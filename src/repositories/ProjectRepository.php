<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Project;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

final class ProjectRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /** @return list<Project> */
    public function findAll(): array
    {
        $statement = $this->database->query(
            'SELECT id, title, description, status, created_at, updated_at
             FROM projects
             ORDER BY created_at DESC, id DESC'
        );

        $projects = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $projects[] = $this->mapRowToProject($row);
        }

        return $projects;
    }

    public function find(int $id): ?Project
    {
        $statement = $this->database->prepare(
            'SELECT id, title, description, status, created_at, updated_at
             FROM projects
             WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRowToProject($row);
    }

    public function save(Project $project): void
    {
        if ($project->getId() !== null) {
            throw new InvalidArgumentException('Nie można dodać projektu z nadanym id.');
        }

        $statement = $this->database->prepare(
            'INSERT INTO projects (title, description, status)
             VALUES (:title, :description, :status)
             RETURNING id'
        );
        $statement->execute([
            'title' => $project->getTitle(),
            'description' => $project->getDescription(),
            'status' => $project->getStatus(),
        ]);

        $project->setId((int) $statement->fetchColumn());
    }

    public function update(Project $project): void
    {
        if ($project->getId() === null) {
            throw new InvalidArgumentException('Nie można zaktualizować projektu bez id.');
        }

        $statement = $this->database->prepare(
            'UPDATE projects
             SET title = :title,
                 description = :description,
                 status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $project->getId(),
            'title' => $project->getTitle(),
            'description' => $project->getDescription(),
            'status' => $project->getStatus(),
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->database->prepare('DELETE FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function mapRowToProject(array $row): Project
    {
        return new Project(
            (int) $row['id'],
            (string) $row['title'],
            $row['description'] === null ? null : (string) $row['description'],
            (string) $row['status'],
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
