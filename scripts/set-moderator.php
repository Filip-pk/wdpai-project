<?php

declare(strict_types=1);

use App\Database;
use App\Repository\CommunityRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$userId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$revoke = ($argv[2] ?? '') === '--revoke';
if ($userId === false || count($argv) > 3 || (isset($argv[2]) && !$revoke)) {
    fwrite(STDERR, 'Użycie: php scripts/set-moderator.php ID_UŻYTKOWNIKA [--revoke]' . PHP_EOL);
    exit(1);
}

try {
    $repository = new CommunityRepository((new Database())->connect());
    if (!$repository->setModerator($userId, !$revoke)) {
        fwrite(STDERR, 'Nie znaleziono konta.' . PHP_EOL);
        exit(1);
    }
    echo ($revoke ? 'Odebrano' : 'Nadano') . ' rolę moderatora kontu ID ' . $userId . '.' . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'Nie udało się zmienić roli moderatora. Sprawdź połączenie z bazą i migrację 15.' . PHP_EOL);
    exit(1);
}
