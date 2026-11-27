<?php

declare(strict_types=1);

// Scheduled cleanup task. The cron container runs it every hour (docker/cron/crontab);
// docs/maintenance.md describes what it deletes and how to run it by hand.

use App\Database;
use App\Repository\MaintenanceRepository;
use App\Service\ApplicationLogger;
use App\Service\AvatarStorage;
use App\Service\CleanupService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$started = gmdate('Y-m-d\TH:i:s\Z');
try {
    $result = (new CleanupService(
        new MaintenanceRepository((new Database())->connect()),
        AvatarStorage::forApplication(),
        new ApplicationLogger(dirname(__DIR__) . '/storage/logs'),
    ))->run();
} catch (Throwable $exception) {
    fwrite(STDERR, $started . ' cleanup failed: ' . $exception::class . PHP_EOL);
    exit(1);
}

if ($result['status'] === 'skipped') {
    echo $started . ' cleanup skipped: another run is still in progress' . PHP_EOL;
    exit(0);
}
echo sprintf(
    '%s cleanup finished: login_attempts=%d email_changes=%d community_activity=%d avatar_files=%d',
    $started, $result['loginAttempts'], $result['emailChanges'], $result['activity'], $result['avatarFiles'],
) . PHP_EOL;
