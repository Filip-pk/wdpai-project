<?php

declare(strict_types=1);

// Moves profile photos saved in users.avatar_data by older versions to avatar files.
// Without options it only copies them, so older application versions keep working.
// With --clear it also removes the database copy of every photo whose file matches.
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

$options = array_slice($argv, 1);
if (array_diff($options, ['--clear']) !== []) {
    fwrite(STDERR, 'Usage: php scripts/migrate-avatars.php [--clear]' . PHP_EOL);
    exit(2);
}

try {
    $migration = new LegacyAvatarMigration(
        new LegacyAvatarRepository((new Database())->connect()),
        AvatarStorage::forApplication(),
    );
    $result = $migration->copyToFiles();
    echo sprintf('Skopiowane zdjęcia: %d, pominięte: %d.', $result['copied'], $result['skipped']) . PHP_EOL;
    if (in_array('--clear', $options, true)) {
        echo sprintf('Usunięte kopie w bazie: %d.', $migration->clearDatabaseCopies()) . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Przeniesienie zdjęć nie powiodło się: ' . $exception::class . PHP_EOL);
    exit(1);
}
