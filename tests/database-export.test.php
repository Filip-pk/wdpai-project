<?php

declare(strict_types=1);

use App\Service\DiagnosticService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Checks the committed database/export/anivibe.sql without a database connection: it must
// match the migrations and hold only the demo accounts. After changing database/*.sql,
// regenerate it with the export command from README.md.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

try {
    $export = str_replace("\r\n", "\n", (string) file_get_contents($root . '/database/export/anivibe.sql'));
    $seed = (string) file_get_contents($root . '/database/33-demo-data.sql');
    $assert(str_contains($export, 'PostgreSQL database dump'), 'Plik eksportu jest zrzutem pg_dump.');

    $tables = [];
    foreach (DiagnosticService::REQUIRED_TABLES as $table) {
        $found = preg_match('/^CREATE TABLE public\.' . preg_quote($table, '/') . ' \(\n(.*?)^\);/ms', $export, $match);
        $assert($found === 1, 'Eksport nie zawiera tabeli ' . $table . '. Wygeneruj go ponownie.');
        $tables[$table] = $match[1];
    }
    $assert(preg_match_all('/^CREATE TABLE /m', $export) === count(DiagnosticService::REQUIRED_TABLES),
        'Eksport zawiera tylko tabele z migracji.');
    foreach (DiagnosticService::REQUIRED_COLUMNS as $qualifiedName) {
        [$table, $column] = explode('.', $qualifiedName, 2);
        $assert(str_contains($tables[$table], "\n    " . $column . ' ') || str_starts_with($tables[$table], '    ' . $column . ' '),
            'Eksport nie zawiera kolumny ' . $qualifiedName . '. Wygeneruj go ponownie.');
    }
    echo "[OK] Eksport zawiera wszystkie tabele i kolumny z migracji\n";
    $passed++;

    preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $export, $emails);
    $emails = array_values(array_unique($emails[0]));
    sort($emails);
    $assert($emails === ['admin@demo.anivibe.test', 'moderator@demo.anivibe.test', 'spammer@demo.anivibe.test', 'user@demo.anivibe.test'],
        'Eksport zawiera wyłącznie konta demonstracyjne: ' . implode(', ', $emails));
    preg_match_all('/\$2y\$10\$[A-Za-z0-9.\/]{53}/', $seed, $hashes);
    $assert(count($hashes[0]) === 4, 'Seed zawiera cztery hashe haseł.');
    foreach ($hashes[0] as $hash) {
        $assert(str_contains($export, $hash), 'Eksport zawiera konta z aktualnego seeda.');
    }
    echo "[OK] Eksport zawiera wyłącznie aktualne konta demonstracyjne\n";
    $passed++;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] ' . $exception->getMessage() . PHP_EOL);
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
