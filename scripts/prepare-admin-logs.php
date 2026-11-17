<?php

declare(strict_types=1);

use App\Service\ApplicationLogger;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$storagePath = dirname(__DIR__) . '/storage';
$logPath = $storagePath . '/logs';

try {
    if (is_link($storagePath) || is_link($logPath)) {
        throw new RuntimeException('Nieprawidłowy katalog logów.');
    }
    if (!is_dir($logPath) && !mkdir($logPath, 0770, true) && !is_dir($logPath)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu logów.');
    }
    // The Docker PHP workers use www-data. CLI commands commonly run as root.
    // Only the fixed log directory and its recognized files receive ownership changes.
    $paths = [$logPath];
    foreach (glob($logPath . '/*') ?: [] as $file) {
        if (preg_match('/\Aapplication(?:-[0-9]{8}-[0-9]{6}(?:-[0-9]{6})?-[a-f0-9]{24})?\.jsonl\z/', basename($file)) === 1) {
            $paths[] = $file;
        }
    }
    if (is_file($logPath . '/.application.lock')) {
        $paths[] = $logPath . '/.application.lock';
    }
    foreach ($paths as $path) {
        if (is_link($path)) {
            throw new RuntimeException('Nieprawidłowy plik logów.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            if (!chown($path, 'www-data') || !chgrp($path, 'www-data')) {
                throw new RuntimeException('Nie udało się ustawić właściciela logów.');
            }
        }
        if (!chmod($path, $path === $logPath ? 0770 : 0660)) {
            throw new RuntimeException('Nie udało się ustawić praw logów.');
        }
    }
    if (!(new ApplicationLogger($logPath))->isWritable()) {
        throw new RuntimeException('Katalog logów jest niedostępny do zapisu.');
    }
    echo 'Katalog logów administratora: OK.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Nie udało się przygotować logów. Sprawdź prawa katalogu storage/logs dla procesu PHP.' . PHP_EOL);
    exit(1);
}
