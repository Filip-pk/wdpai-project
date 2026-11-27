<?php

declare(strict_types=1);

// Copies profile photos saved in users.avatar_data by older versions to avatar files and records
// their paths. Run it after migration 34 and before migration 35, which drops the column.
// See docs/user-profiles.md.

use App\Database;
use App\Repository\LegacyAvatarRepository;
use App\Service\AvatarStorage;
use App\Service\LegacyAvatarMigration;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $avatars = new LegacyAvatarRepository((new Database())->connect());
    if (!$avatars->hasLegacyColumn()) {
        echo 'Kolumna avatar_data została już usunięta: zdjęcia są w plikach.' . PHP_EOL;
        exit(0);
    }
    $result = (new LegacyAvatarMigration($avatars, AvatarStorage::forApplication()))->copyToFiles();
    echo sprintf('Skopiowane zdjęcia: %d, pominięte: %d.', $result['copied'], $result['skipped']) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Przeniesienie zdjęć nie powiodło się: ' . $exception::class . PHP_EOL);
    exit(1);
}
