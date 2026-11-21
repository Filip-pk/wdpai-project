<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AdminRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

$userId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$revoke = ($argv[2] ?? '') === '--revoke';
if ($userId === false || count($argv) > 3 || (isset($argv[2]) && !$revoke)) {
    fwrite(STDERR, 'Użycie: php scripts/set-admin.php ID_UŻYTKOWNIKA [--revoke]' . PHP_EOL);
    exit(1);
}

try {
    $repository = new AdminRepository((new Database())->connect());
    if (!$repository->setAdmin($userId, !$revoke)) {
        fwrite(STDERR, 'Nie znaleziono konta lub konto jest aktywnie zablokowane.' . PHP_EOL);
        exit(1);
    }
    echo ($revoke ? 'Odebrano' : 'Nadano') . ' rolę administratora kontu ID ' . $userId . '.' . PHP_EOL;
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Nie można odebrać roli ostatniemu aktywnemu administratorowi.') {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    } else {
        fwrite(STDERR, 'Nie udało się zmienić roli administratora. Sprawdź połączenie z bazą i migrację 29.' . PHP_EOL);
    }
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, 'Nie udało się zmienić roli administratora. Sprawdź połączenie z bazą i migrację 29.' . PHP_EOL);
    exit(1);
}
