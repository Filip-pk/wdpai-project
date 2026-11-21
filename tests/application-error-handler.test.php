<?php

declare(strict_types=1);

use App\Service\ApplicationErrorHandler;
use App\Service\ApplicationLogger;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? '') === '--scenario') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    error_reporting(E_ALL);
    $_SERVER['REQUEST_URI'] = '/anime/123?token=PRIVATE_TOKEN&email=private@example.test';
    $logger = new ApplicationLogger($argv[3]);
    $previousCalls = 0;
    if (in_array($argv[2], ['previous', 'previous-throws'], true)) {
        set_error_handler(static function () use (&$previousCalls, $argv): bool {
            $previousCalls++;
            if ($argv[2] === 'previous-throws') {
                throw new RuntimeException('Previous handler marker');
            }
            return true;
        });
    }
    ApplicationErrorHandler::register($logger);
    ApplicationErrorHandler::register($logger);
    switch ($argv[2]) {
        case 'warning':
            trigger_error('PRIVATE_MESSAGE password=PRIVATE_PASSWORD', E_USER_WARNING);
            echo 'continued';
            break;
        case 'notice':
            trigger_error('PRIVATE_MESSAGE', E_USER_NOTICE);
            break;
        case 'silenced':
            @trigger_error('PRIVATE_MESSAGE', E_USER_WARNING);
            break;
        case 'reporting-disabled':
            error_reporting(0);
            trigger_error('PRIVATE_MESSAGE', E_USER_WARNING);
            break;
        case 'previous':
            trigger_error('PRIVATE_MESSAGE', E_USER_WARNING);
            echo $previousCalls;
            break;
        case 'previous-throws':
            try {
                trigger_error('PRIVATE_MESSAGE', E_USER_WARNING);
            } catch (RuntimeException $exception) {
                echo $exception->getMessage() === 'Previous handler marker' ? 'caught' : 'wrong';
            }
            break;
        case 'caught':
            try {
                throw new RuntimeException('PRIVATE_MESSAGE');
            } catch (RuntimeException) {
                echo 'caught';
            }
            break;
        case 'fatal':
            trigger_error('PRIVATE_MESSAGE password=PRIVATE_PASSWORD', E_USER_ERROR);
            break;
        case 'parse':
            require $argv[4];
            break;
        case 'fatal-reporting-disabled':
            error_reporting(0);
            trigger_error('PRIVATE_MESSAGE', E_USER_ERROR);
            break;
        default:
            throw new RuntimeException('Unknown isolated test scenario.');
    }
    exit;
}

function errorHandlerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/anivibe-error-handler-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$brokenFile = $root . '/parse-fixture.php';
file_put_contents($brokenFile, '<?php function PRIVATE_FILE(');

$run = static function (string $scenario) use ($root, $brokenFile): array {
    $directory = $root . '/' . $scenario;
    mkdir($directory, 0700);
    $process = proc_open([PHP_BINARY, __FILE__, '--scenario', $scenario, $directory, $brokenFile],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    errorHandlerAssert(is_resource($process), 'Could not launch the isolated PHP process.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $logger = new ApplicationLogger($directory);
    return ['status' => $status, 'output' => $output, 'errors' => $errors, 'items' => $logger->recent()['items']];
};

try {
    foreach (['warning' => E_USER_WARNING, 'notice' => E_USER_NOTICE] as $scenario => $code) {
        $result = $run($scenario);
        errorHandlerAssert($result['status'] === 0 && count($result['items']) === 1, 'A warning/notice must be recorded once without changing successful execution.');
        $entry = $result['items'][0];
        errorHandlerAssert($entry['level'] === 'warning' && $entry['messageKey'] === 'admin.log.runtime_error', 'Unexpected warning metadata.');
        errorHandlerAssert($entry['context'] === ['path' => '/anime/[id]', 'code' => $code], 'Only safe path and severity may be logged.');
        errorHandlerAssert(!str_contains(json_encode($entry), 'PRIVATE'), 'Runtime logs must not expose messages, query tokens or filenames.');
        if ($scenario === 'warning') {
            errorHandlerAssert($result['output'] === 'continued', 'The runtime warning must not replace the application response.');
        }
    }
    echo "[OK] Warnings and notices preserve execution and contain only safe metadata\n";

    foreach (['silenced', 'reporting-disabled', 'fatal-reporting-disabled', 'caught'] as $scenario) {
        $result = $run($scenario);
        errorHandlerAssert($result['items'] === [], 'Suppressed or caught errors must not produce an unrelated runtime/fatal event.');
    }
    echo "[OK] @, error_reporting and caught exceptions keep their existing behavior\n";

    $previous = $run('previous');
    errorHandlerAssert($previous['status'] === 0 && $previous['output'] === '1' && count($previous['items']) === 1,
        'The previous handler must execute once, including repeated registration.');
    $throwing = $run('previous-throws');
    errorHandlerAssert($throwing['status'] === 0 && $throwing['output'] === 'caught' && count($throwing['items']) === 1,
        'Exceptions from an existing handler must remain catchable by the application.');
    echo "[OK] Existing handlers and catches are preserved, registration is idempotent\n";

    foreach (['fatal', 'parse'] as $scenario) {
        $result = $run($scenario);
        errorHandlerAssert($result['status'] !== 0 && count($result['items']) === 1, 'A fatal error must be recorded during shutdown.');
        $entry = $result['items'][0];
        errorHandlerAssert($entry['level'] === 'error' && $entry['messageKey'] === 'admin.log.fatal_error', 'Unexpected fatal error metadata.');
        errorHandlerAssert(!str_contains(json_encode($entry), 'PRIVATE'), 'Fatal logs must not expose error messages or filenames.');
    }
    echo "[OK] Fatal and included-file parse errors are recorded during shutdown\n";
} finally {
    foreach (glob($root . '/*') ?: [] as $path) {
        if (is_dir($path)) {
            foreach (array_merge(glob($path . '/*') ?: [], glob($path . '/.*') ?: []) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($root);
}
