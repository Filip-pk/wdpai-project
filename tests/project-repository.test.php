<?php

declare(strict_types=1);

use App\Database;
use App\Model\Project;
use App\Repository\ProjectRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$connection = null;

$assert = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Warunek testu nie został spełniony.');
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $connection->exec(
        'CREATE TEMP TABLE projects (LIKE public.projects INCLUDING ALL) ON COMMIT DROP'
    );

    $temporaryTableCount = $connection->query(
        "SELECT count(*)
         FROM pg_class
         WHERE oid = to_regclass('projects')
           AND oid <> to_regclass('public.projects')
           AND relnamespace = pg_my_temp_schema()
           AND relpersistence = 't'"
    )->fetchColumn();
    $temporarySequenceCount = $connection->query(
        "SELECT count(*)
         FROM pg_class
         WHERE oid = pg_get_serial_sequence('projects', 'id')::regclass
           AND oid <> pg_get_serial_sequence('public.projects', 'id')::regclass
           AND relnamespace = pg_my_temp_schema()
           AND relpersistence = 't'"
    )->fetchColumn();

    $assert((int) $temporaryTableCount === 1 && (int) $temporarySequenceCount === 1);

    $repository = new ProjectRepository($connection);

    $run = static function (string $name, callable $test) use (
        $connection,
        &$passed,
        &$failed,
    ): void {
        $connection->exec('SAVEPOINT repository_test');
        $success = false;

        try {
            $test();
            $success = true;
        } catch (Throwable) {
            $success = false;
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT repository_test');
            $connection->exec('RELEASE SAVEPOINT repository_test');
        }

        if ($success) {
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } else {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . PHP_EOL);
        }
    };

    $find = static function (int $id) use ($repository, $assert): Project {
        $project = $repository->find($id);
        $assert($project instanceof Project);

        return $project;
    };

    $run('Pusta lista i brak projektu zwracają odpowiednio pustą tablicę i null', static function () use (
        $repository,
        $assert,
    ): void {
        $assert($repository->findAll() === []);
        $assert($repository->find(PHP_INT_MAX) === null);
    });

    $run('Zapis nadaje ID, a odczyt mapuje rekord i daty na model Project', static function () use (
        $repository,
        $assert,
        $find,
    ): void {
        $project = new Project(null, 'Projekt do zapisania', null, 'planned');
        $repository->save($project);

        $assert($project->getId() !== null && $project->getId() > 0);
        $storedProject = $find($project->getId());

        $assert($storedProject->getId() === $project->getId());
        $assert($storedProject->getTitle() === 'Projekt do zapisania');
        $assert($storedProject->getDescription() === null);
        $assert($storedProject->getStatus() === 'planned');
        $assert($storedProject->getCreatedAt() instanceof DateTimeImmutable);
        $assert($storedProject->getUpdatedAt() instanceof DateTimeImmutable);
    });

    $run('Opis null i pusty opis zachowują różne wartości', static function () use (
        $repository,
        $assert,
        $find,
    ): void {
        $withoutDescription = new Project(null, 'Bez opisu', null, 'active');
        $withEmptyDescription = new Project(null, 'Pusty opis', '', 'completed');
        $repository->save($withoutDescription);
        $repository->save($withEmptyDescription);

        $assert($find($withoutDescription->getId())->getDescription() === null);
        $assert($find($withEmptyDescription->getId())->getDescription() === '');
    });

    $run('Apostrofy i fragmenty SQL w tytule oraz opisie pozostają danymi', static function () use (
        $repository,
        $assert,
        $find,
    ): void {
        $title = "O'Reilly; SELECT 999; --";
        $description = "Opis 'cytat'; SELECT 'dane'; --";
        $project = new Project(null, $title, $description, 'active');
        $repository->save($project);
        $storedProject = $find($project->getId());

        $assert($storedProject->getTitle() === $title);
        $assert($storedProject->getDescription() === $description);
        $assert(count($repository->findAll()) === 1);
    });

    $run('Lista zawiera modele posortowane malejąco według daty i ID przy remisie', static function () use (
        $connection,
        $repository,
        $assert,
    ): void {
        $older = new Project(null, 'Starszy projekt', null, 'planned');
        $firstAtSameTime = new Project(null, 'Pierwszy projekt z remisu', null, 'active');
        $secondAtSameTime = new Project(null, 'Drugi projekt z remisu', null, 'completed');

        foreach ([$older, $firstAtSameTime, $secondAtSameTime] as $project) {
            $repository->save($project);
        }

        $fixture = $connection->prepare('UPDATE projects SET created_at = :createdAt WHERE id = :id');
        foreach ([
            [$older, '2025-01-01T12:00:00+01:00'],
            [$firstAtSameTime, '2025-02-01T12:00:00+01:00'],
            [$secondAtSameTime, '2025-02-01T13:00:00+02:00'],
        ] as [$project, $createdAt]) {
            $fixture->execute(['id' => $project->getId(), 'createdAt' => $createdAt]);
        }

        $projects = $repository->findAll();
        $assert(array_is_list($projects) && count($projects) === 3);
        $ids = array_map(static fn (Project $project): ?int => $project->getId(), $projects);

        $assert($ids === [
            $secondAtSameTime->getId(),
            $firstAtSameTime->getId(),
            $older->getId(),
        ]);
        $assert($projects[0]->getCreatedAt() instanceof DateTimeImmutable);
        $assert(
            $projects[0]->getCreatedAt()->getTimestamp()
            === (new DateTimeImmutable('2025-02-01T11:00:00+00:00'))->getTimestamp()
        );
    });

    $run('Aktualizacja zmienia wskazany projekt i datę zmiany, zachowując datę utworzenia', static function () use (
        $connection,
        $repository,
        $assert,
        $find,
    ): void {
        $project = new Project(null, 'Pierwotny tytuł', 'Pierwotny opis', 'planned');
        $otherProject = new Project(null, 'Drugi projekt', 'Osobny opis', 'active');
        $repository->save($project);
        $repository->save($otherProject);

        $fixture = $connection->prepare(
            'UPDATE projects
             SET created_at = :createdAt, updated_at = :updatedAt
             WHERE id = :id'
        );
        $fixture->execute([
            'id' => $project->getId(),
            'createdAt' => '2000-01-01T12:00:00+00:00',
            'updatedAt' => '2001-01-01T12:00:00+00:00',
        ]);
        $beforeUpdate = $find($project->getId());

        $repository->update(new Project($project->getId(), 'Nowy tytuł', null, 'completed'));
        $afterUpdate = $find($project->getId());
        $unchangedProject = $find($otherProject->getId());

        $assert($afterUpdate->getTitle() === 'Nowy tytuł');
        $assert($afterUpdate->getDescription() === null);
        $assert($afterUpdate->getStatus() === 'completed');
        $assert($afterUpdate->getCreatedAt() instanceof DateTimeImmutable);
        $assert($beforeUpdate->getCreatedAt() instanceof DateTimeImmutable);
        $assert(
            $afterUpdate->getCreatedAt()->getTimestamp()
            === $beforeUpdate->getCreatedAt()->getTimestamp()
        );
        $assert($afterUpdate->getUpdatedAt() instanceof DateTimeImmutable);
        $assert($beforeUpdate->getUpdatedAt() instanceof DateTimeImmutable);
        $assert($afterUpdate->getUpdatedAt() > $beforeUpdate->getUpdatedAt());
        $assert($unchangedProject->getTitle() === 'Drugi projekt');
        $assert($unchangedProject->getDescription() === 'Osobny opis');
        $assert($unchangedProject->getStatus() === 'active');
    });

    $run('Usunięcie dotyczy wskazanego projektu, a brak ID jest bezpiecznym brakiem zmiany', static function () use (
        $repository,
        $assert,
        $find,
    ): void {
        $toDelete = new Project(null, 'Do usunięcia', null, 'planned');
        $toKeep = new Project(null, 'Do zachowania', null, 'active');
        $repository->save($toDelete);
        $repository->save($toKeep);

        $repository->delete($toDelete->getId());
        $assert($repository->find($toDelete->getId()) === null);
        $assert($find($toKeep->getId())->getTitle() === 'Do zachowania');

        $repository->delete($toDelete->getId());
        $repository->delete(PHP_INT_MAX);
        $assert(count($repository->findAll()) === 1);
    });

    $run('Aktualizacja projektu bez ID jest odrzucana', static function () use (
        $repository,
        $assert,
    ): void {
        $exception = null;

        try {
            $repository->update(new Project(null, 'Projekt bez ID', null, 'planned'));
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $assert($exception instanceof InvalidArgumentException);
        $assert($repository->findAll() === []);
    });

    $run('Ponowne dodanie projektu z ID jest odrzucane', static function () use (
        $repository,
        $assert,
    ): void {
        $exception = null;
        $project = new Project(789, 'Projekt z nadanym ID', null, 'planned');

        try {
            $repository->save($project);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $assert($exception instanceof InvalidArgumentException);
        $assert($project->getId() === 789);
        $assert($repository->findAll() === []);
    });

    $run('Baza odrzuca niedozwolony status przez ograniczenie CHECK', static function () use (
        $repository,
        $assert,
    ): void {
        $exception = null;
        $project = new Project(null, 'Niepoprawny status', null, 'unknown');

        try {
            $repository->save($project);
        } catch (PDOException $caught) {
            $exception = $caught;
        }

        $assert($exception instanceof PDOException && $exception->getCode() === '23514');
        $assert($project->getId() === null);
    });
} catch (Throwable) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować lub zakończyć izolowanych testów repozytorium.' . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        try {
            $connection->rollBack();
        } catch (Throwable) {
            $failed++;
            fwrite(STDERR, '[BŁĄD] Nie udało się wycofać transakcji testowej.' . PHP_EOL);
        }
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;

exit($failed === 0 ? 0 : 1);
