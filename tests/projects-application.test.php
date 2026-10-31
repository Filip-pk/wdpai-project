<?php

declare(strict_types=1);

use App\Controller\AppController;
use App\Controller\DefaultController;
use App\Controller\ProjectController;
use App\Database;
use App\Model\Project;
use App\Repository\ProjectRepository;
use App\Routing;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Zachowaj możliwość ustawiania kodu HTTP pomiędzy renderowaniami w CLI.
ob_start();

$passed = 0;
$failed = 0;
$connection = null;

$assert = static function (bool $condition): void {
    if (!$condition) {
        throw new RuntimeException('Warunek testu nie został spełniony.');
    }
};

$escape = static fn (string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$render = static function (string $uri, callable $action): array {
    $previousUri = $_SERVER['REQUEST_URI'] ?? null;
    $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $previousStatus = http_response_code();
    $previousBufferLevel = ob_get_level();
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    http_response_code(200);
    ob_start();

    try {
        $result = $action();

        return [
            'html' => (string) ob_get_contents(),
            'status' => http_response_code(),
            'result' => $result,
        ];
    } finally {
        while (ob_get_level() > $previousBufferLevel) {
            ob_end_clean();
        }

        if ($previousUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $previousUri;
        }

        if ($previousMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $previousMethod;
        }

        http_response_code(is_int($previousStatus) ? $previousStatus : 200);
    }
};

$runEntryWithUnavailableDatabase = static function (string $uri) use ($assert): array {
    $source = <<<'PHP'
putenv('POSTGRES_HOST=127.0.0.1');
putenv('POSTGRES_PORT=1');
putenv('POSTGRES_DB=anivibe-test-db');
putenv('POSTGRES_USER=anivibe-test-user');
putenv('POSTGRES_PASSWORD=anivibe-test-secret');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $argv[1];
ob_start();
require $argv[2];
$html = ob_get_clean();
echo json_encode(
    ['status' => http_response_code() ?: 200, 'html' => $html],
    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
);
PHP;

    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'display_errors=1',
            '-r',
            $source,
            $uri,
            dirname(__DIR__) . '/public/index.php',
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );
    $assert(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $assert($exitCode === 0 && is_string($output) && is_string($errorOutput));
    $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    $assert(is_array($response) && is_string($response['html'] ?? null));
    $assert(
        preg_match(
            '/SQLSTATE|pgsql:|Stack trace|Fatal error|anivibe-test-secret|anivibe-test-user|anivibe-test-db|127\.0\.0\.1/i',
            $response['html'] . $errorOutput,
        ) === 0
    );

    return $response;
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
    $controller = new ProjectController($repository);

    $run = static function (string $name, callable $test) use (
        $connection,
        &$passed,
        &$failed,
    ): void {
        $connection->exec('SAVEPOINT projects_application_test');
        $success = false;

        try {
            $test();
            $success = true;
        } catch (Throwable) {
            $success = false;
        } finally {
            $connection->exec('ROLLBACK TO SAVEPOINT projects_application_test');
            $connection->exec('RELEASE SAVEPOINT projects_application_test');
        }

        if ($success) {
            $passed++;
            echo '[OK] ' . $name . PHP_EOL;
        } else {
            $failed++;
            fwrite(STDERR, '[BŁĄD] ' . $name . PHP_EOL);
        }
    };

    $run('Pusta lista projektów ma czytelny stan i odpowiedź 200', static function () use (
        $controller,
        $render,
        $assert,
    ): void {
        $response = $render('/projects', static fn () => $controller->index());

        $assert($response['status'] === 200);
        $assert(str_contains($response['html'], 'Brak projektów'));
        $assert(!str_contains($response['html'], 'data-project-id='));
    });

    $run('Lista wyświetla trzy statusy i zabezpiecza tytuły oraz opisy HTML', static function () use (
        $repository,
        $controller,
        $render,
        $escape,
        $assert,
    ): void {
        $title = 'Projekt <script>alert("test")</script> & "cytat"';
        $description = "Pierwsza linia <img src=x onerror=alert(1)>\nDruga linia & 'cytat'";
        $first = new Project(null, $title, $description, 'planned');
        $second = new Project(null, 'Projekt aktywny — polskie znaki: ąćęłńóśźż', null, 'active');
        $third = new Project(null, 'Projekt ukończony', '', 'completed');

        foreach ([$first, $second, $third] as $project) {
            $repository->save($project);
        }

        $response = $render('/projects', static fn () => $controller->index());
        $html = $response['html'];
        $assert($response['status'] === 200);
        $assert(str_contains($html, $escape($title)));
        $assert(str_contains($html, $escape($description)));
        $assert(!str_contains($html, '<script>alert("test")</script>'));
        $assert(!str_contains($html, '<img src=x onerror=alert(1)>'));
        $assert(str_contains($html, 'ąćęłńóśźż'));
        $assert(substr_count($html, 'Brak opisu.') === 2);

        foreach ([$first, $second, $third] as $project) {
            $assert(str_contains($html, 'data-project-id="' . $project->getId() . '"'));
            $assert(str_contains($html, 'data-project-status="' . $project->getStatus() . '"'));
            $assert(str_contains($html, 'href="/projects/' . $project->getId() . '"'));
        }

        foreach (['Planowany', 'W trakcie', 'Ukończony'] as $label) {
            $assert(str_contains($html, $label));
        }
    });

    $run('Szczegóły projektu pokazują ID i daty oraz zachowują bezpieczny opis wielowierszowy', static function () use (
        $repository,
        $controller,
        $render,
        $escape,
        $assert,
    ): void {
        $title = 'Projekt <b>tytuł</b> & "cytat"';
        $description = "Linia <svg onload=alert(1)>\nDruga linia 'opisu'";
        $project = new Project(null, $title, $description, 'active');
        $repository->save($project);
        $response = $render(
            '/projects/' . $project->getId(),
            static fn () => $controller->show($project->getId()),
        );
        $html = $response['html'];

        $assert($response['status'] === 200);
        $assert(str_contains($html, $escape($title)));
        $assert(str_contains($html, $escape($description)));
        $assert(!str_contains($html, '<b>tytuł</b>'));
        $assert(!str_contains($html, '<svg onload=alert(1)>'));
        $assert(str_contains($html, 'data-project-id="' . $project->getId() . '"'));
        $assert(str_contains($html, 'data-project-status="active"'));
        $assert(str_contains($html, 'href="/projects"'));
        $assert(substr_count($html, '<time ') === 2);
    });

    $run('Szczegóły projektu bez opisu pokazują tekst zastępczy', static function () use (
        $repository,
        $controller,
        $render,
        $assert,
    ): void {
        $project = new Project(null, 'Projekt bez opisu', null, 'planned');
        $repository->save($project);
        $response = $render(
            '/projects/' . $project->getId(),
            static fn () => $controller->show($project->getId()),
        );

        $assert($response['status'] === 200);
        $assert(str_contains($response['html'], 'Brak opisu.'));
    });

    $run('Nieistniejący projekt otrzymuje odpowiedź 404 i wspólny widok błędu', static function () use (
        $controller,
        $render,
        $assert,
    ): void {
        $response = $render(
            '/projects/' . PHP_INT_MAX,
            static fn () => $controller->show(PHP_INT_MAX),
        );

        $assert($response['status'] === 404);
        $assert(str_contains($response['html'], '404'));
        $assert(!str_contains($response['html'], 'data-project-id='));
        $assert(preg_match('/SQLSTATE|PDOException|Stack trace/i', $response['html']) === 0);
    });

    $run('Router korzysta z fabryki kontrolera z wstrzykniętym repozytorium', static function () use (
        $repository,
        $render,
        $assert,
    ): void {
        $classes = [];
        $project = new Project(null, 'Projekt przez router', null, 'completed');
        $repository->save($project);
        $routing = new Routing(static function (string $class) use ($repository, &$classes): AppController {
            $classes[] = $class;

            return $class === ProjectController::class
                ? new ProjectController($repository)
                : new $class();
        });
        $routing->get('/projects/{id}', ProjectController::class, 'show');
        $response = $render(
            '/projects/' . $project->getId(),
            static fn () => $routing->dispatch('GET', '/projects/' . $project->getId() . '?source=test'),
        );

        $assert($response['result'] === true && $response['status'] === 200);
        $assert($classes === [ProjectController::class]);
        $assert(str_contains($response['html'], 'Projekt przez router'));
        $assert($routing->dispatch('POST', '/projects/' . $project->getId()) === false);
        $assert($routing->dispatch('GET', '/projects/not-a-number') === false);
        $assert($classes === [ProjectController::class]);
    });

    $run('Router bez fabryki zachowuje dotychczasowe trasy demonstracyjne', static function () use (
        $render,
        $assert,
    ): void {
        $routing = new Routing();
        $routing->get('/', DefaultController::class, 'index');
        $response = $render('/', static fn () => $routing->dispatch('GET', '/?source=test'));

        $assert($response['result'] === true && $response['status'] === 200);
        $assert(str_contains($response['html'], 'AniVibe'));
        $assert($routing->dispatch('GET', '/nieznana-trasa') === false);
    });

    $run('Strona główna działa przy niedostępnej bazie dzięki leniwemu składaniu zależności', static function () use (
        $runEntryWithUnavailableDatabase,
        $assert,
    ): void {
        $response = $runEntryWithUnavailableDatabase('/');

        $assert($response['status'] === 200);
        $assert(str_contains($response['html'], 'AniVibe'));
    });

    $run('Niedostępna baza daje ogólną odpowiedź 500 na trasach projektów', static function () use (
        $runEntryWithUnavailableDatabase,
        $assert,
    ): void {
        foreach (['/projects', '/projects/1'] as $uri) {
            $response = $runEntryWithUnavailableDatabase($uri);

            $assert($response['status'] === 500);
            $assert(str_contains($response['html'], 'Błąd serwera'));
        }
    });
} catch (Throwable) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] Nie udało się przygotować lub zakończyć izolowanych testów aplikacji.' . PHP_EOL);
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
ob_end_flush();

exit($failed === 0 ? 0 : 1);
