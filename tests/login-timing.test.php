<?php

declare(strict_types=1);

use App\Service\AccountSecurityService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixed = password_get_info(AccountSecurityService::UNKNOWN_ACCOUNT_HASH);
$current = password_get_info(password_hash('Porownanie-2026', PASSWORD_DEFAULT));
$assert($fixed['algoName'] === $current['algoName'], 'The fixed hash must use the default algorithm.');
$assert(($fixed['options']['cost'] ?? null) === ($current['options']['cost'] ?? null),
    'The fixed hash must use the default cost; regenerate it after a PHP upgrade changes the cost.');
echo "[OK] The unknown-account hash matches the current password_hash() algorithm and cost\n";

$hash = password_hash('Poprawne-Haslo-2026', PASSWORD_DEFAULT);
$assert(AccountSecurityService::verifyPassword('Poprawne-Haslo-2026', $hash), 'A correct password must pass.');
$assert(!AccountSecurityService::verifyPassword('Bledne-Haslo-2026', $hash), 'A wrong password must fail.');
$assert(!AccountSecurityService::verifyPassword('Poprawne-Haslo-2026', null), 'A missing account must fail.');
$assert(!AccountSecurityService::verifyPassword('krotkie', $hash), 'A password outside the limits must fail.');
echo "[OK] Verification results are unchanged\n";

$measure = static function (?string $storedHash): float {
    $started = hrtime(true);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        AccountSecurityService::verifyPassword('Bledne-Haslo-2026', $storedHash);
    }
    return (hrtime(true) - $started) / 5;
};
$measure($hash);
$wrongPassword = $measure($hash);
$missingAccount = $measure(null);
$ratio = $missingAccount / max(1.0, $wrongPassword);
$assert($ratio > 0.5 && $ratio < 2.0,
    sprintf('A missing account must take as long as a wrong password (ratio %.2f).', $ratio));
echo sprintf("[OK] A missing account takes as long as a wrong password (ratio %.2f)\n", $ratio);
