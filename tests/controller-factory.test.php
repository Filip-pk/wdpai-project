<?php

declare(strict_types=1);

use App\Controller\AppController;
use App\ControllerFactory;
use App\Service\ApplicationLogger;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Every controller is built for a guest while the database address points nowhere: loading each
// class catches signature conflicts with AppController, and the unreachable database proves that
// building a controller never opens a connection before an action needs one.
putenv('POSTGRES_HOST=127.0.0.1');
putenv('POSTGRES_PORT=1');
$_SESSION = [];
$_COOKIE = [];
$passed = 0;
$failed = 0;
$logs = sys_get_temp_dir() . '/anivibe-factory-' . bin2hex(random_bytes(8));
$factory = new ControllerFactory(new ApplicationLogger($logs), dirname(__DIR__));

foreach (glob(dirname(__DIR__) . '/src/controllers/*Controller.php') ?: [] as $file) {
    $class = 'App\\Controller\\' . basename($file, '.php');
    if ($class === AppController::class) {
        continue;
    }
    try {
        $controller = $factory($class);
        if (!$controller instanceof $class) {
            throw new RuntimeException('Fabryka zwróciła ' . $controller::class);
        }
        $passed++;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $class . ': ' . $exception::class . ' ' . $exception->getMessage() . PHP_EOL);
    }
}
echo '[OK] Fabryka buduje ' . $passed . " kontrolerów bez łączenia z bazą\n";

foreach (is_dir($logs) ? array_diff(scandir($logs) ?: [], ['.', '..']) : [] as $name) {
    unlink($logs . '/' . $name);
}
if (is_dir($logs)) {
    rmdir($logs);
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 && $passed > 20 ? 0 : 1);
