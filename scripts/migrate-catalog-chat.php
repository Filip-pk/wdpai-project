<?php

declare(strict_types=1);

use App\Database;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $migration = file_get_contents(dirname(__DIR__) . '/database/30-catalog-chat.sql');
    if ($migration === false) {
        throw new RuntimeException('Brak pliku migracji.');
    }
    (new Database())->connect()->exec($migration);
    echo 'Migracja czatu katalogowego: OK.' . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Nie udało się zastosować migracji czatu katalogowego. Sprawdź dostęp do bazy.' . PHP_EOL);
    exit(1);
}
