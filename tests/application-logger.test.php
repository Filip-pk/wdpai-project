<?php

declare(strict_types=1);

use App\Service\ApplicationLogger;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$directory = sys_get_temp_dir() . '/anivibe-logger-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$cleanup = static function (string $path) use ($directory): void {
    $root = realpath($directory);
    $resolved = realpath($path);
    if ($root === false || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Unsafe test cleanup path.');
    }
    unlink($path);
};

try {
    $logger = new ApplicationLogger($directory);
    $assert($logger->isWritable(), 'Fresh log directory must be writable.');
    $id = $logger->recordException(new RuntimeException('postgres://admin:TOP_SECRET@db/private?token=HIDDEN'),
        'application', '/anime/123?email=private@example.test&token=HIDDEN');
    $logger->record('warning', 'mail', 'admin.log.mail_failed', [
        'password' => 'TOP_SECRET', 'email' => 'private@example.test', 'token' => 'HIDDEN',
        'message' => 'PRIVATE_BODY', 'trace' => 'PRIVATE_TRACE', 'service' => 'mail',
        'path' => '/settings/security/TOP_SECRET?token=HIDDEN', 'code' => 503,
    ]);
    $rows = $logger->recent();
    $assert($rows['pagination']['total'] === 2, 'Two events must be retained.');
    $assert($rows['items'][1]['id'] === $id, 'The newest event must be first.');
    $assert($rows['items'][1]['context']['path'] === '/anime/[id]', 'Paths must remove identifiers and query parameters.');
    $assert($rows['items'][1]['context']['class'] === RuntimeException::class, 'Exception class must be retained.');
    $assert($rows['items'][0]['context']['path'] === '/settings/security/[other]', 'Unknown path segments must be hidden.');
    $assert($rows['items'][0]['context']['code'] === 503, 'A bounded numeric code may be retained.');
    $data = file_get_contents($directory . '/application.jsonl');
    foreach (['TOP_SECRET', 'HIDDEN', 'private@example.test', 'PRIVATE_BODY', 'PRIVATE_TRACE', 'postgres://'] as $secret) {
        $assert(!str_contains($data, $secret), 'Sensitive information must never enter a log record.');
    }
    $assert($logger->recent('warning', 'mail')['pagination']['total'] === 1, 'Level and module filters must compose.');
    $assert($logger->recent('error', 'mail')['pagination']['total'] === 0, 'Filters must not return unrelated records.');
    echo "[OK] Exception privacy, safe context, newest-first ordering and filters\n";

    for ($i = 0; $i < 30; $i++) {
        $logger->record('info', 'admin', 'admin.log.event');
    }
    $assert(count($logger->recent()['items']) === 20, 'The first page must be bounded to twenty records.');
    $assert(count($logger->recent('', '', 2)['items']) === 12, 'The second page must contain the remaining records.');
    $assert($logger->recent('', '', 999)['pagination']['page'] === 2, 'Page numbers must be clamped.');
    file_put_contents($directory . '/application.jsonl', "INVALID_JSON\n" . str_repeat('x', 3000) . "\n", FILE_APPEND);
    $assert($logger->recent()['pagination']['total'] === 32, 'Malformed records must be skipped.');
    echo "[OK] Pagination and malformed input are bounded\n";

    $locked = fopen($directory . '/.application.lock', 'c');
    flock($locked, LOCK_EX);
    $started = microtime(true);
    $logger->record('error', 'application', 'admin.log.exception');
    $assert(microtime(true) - $started < 0.5, 'A competing lock must not block the application.');
    $assert($logger->recent()['items'] === [], 'A competing writer lock must fail safely for reads.');
    flock($locked, LOCK_UN);
    fclose($locked);
    echo "[OK] Log lock contention cannot stall application responses\n";

    foreach (glob($directory . '/*') ?: [] as $path) {
        $cleanup($path);
    }
    $rotating = new ApplicationLogger($directory, 4096, 1, 3);
    $lastId = '';
    for ($i = 0; $i < 150; $i++) {
        $lastId = $rotating->recordException(new RuntimeException('SECRET'), 'application', '/anime/123');
    }
    $files = glob($directory . '/application*.jsonl') ?: [];
    $assert(count($files) <= 3 && count($files) > 1, 'Rotation must retain at most three bounded files.');
    foreach ($files as $file) {
        clearstatcache(true, $file);
        $assert(filesize($file) <= 4096, 'Every rotated file must respect the size limit.');
    }
    $assert($rotating->recent()['items'][0]['id'] === $lastId, 'The latest event must survive repeated rotation.');
    $oldFile = $directory . '/application-20000101-000000-' . str_repeat('a', 24) . '.jsonl';
    file_put_contents($oldFile, '{}');
    touch($oldFile, time() - 172800);
    $rotating->record('info', 'admin', 'admin.log.event');
    $assert(!file_exists($oldFile), 'Expired log files must be pruned.');
    echo "[OK] File rotation, retention and last-event preservation\n";

    $invalid = new ApplicationLogger($directory . '/application.jsonl/subdirectory');
    $assert(!$invalid->isWritable(), 'A non-directory storage path must be reported as unavailable.');
    $assert(preg_match('/\A[a-f0-9]{24}\z/', $invalid->recordException(new RuntimeException('SECRET'), 'application')) === 1,
        'Logging failure must still return a harmless event identifier.');
    $assert($invalid->recent()['items'] === [], 'Unavailable log storage must not throw.');
    echo "[OK] Unavailable storage fails safely\n";
} finally {
    foreach (array_merge(glob($directory . '/*') ?: [], glob($directory . '/.*') ?: []) as $path) {
        if (is_file($path)) {
            $cleanup($path);
        }
    }
    rmdir($directory);
}
