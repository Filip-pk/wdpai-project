<?php

declare(strict_types=1);

use App\Service\ExperienceService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message = 'Warunek testu nie został spełniony.'): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
    }
};

$run('Nowe konto zaczyna na poziomie 1 z pustym paskiem 0/50 EXP', static function () use ($assert): void {
    $assert(ExperienceService::progress(0) === [
        'level' => 1, 'totalExp' => 0, 'levelExp' => 0, 'nextLevelExp' => 50,
        'remainingExp' => 50, 'levelStartExp' => 0,
    ]);
});

$run('Progi 50, 150, 300 i 500 przyznają awans dokładnie na granicy', static function () use ($assert): void {
    foreach ([50 => 2, 150 => 3, 300 => 4, 500 => 5, 750 => 6] as $threshold => $level) {
        $before = ExperienceService::progress($threshold - 1);
        $at = ExperienceService::progress($threshold);
        $after = ExperienceService::progress($threshold + 1);
        $assert($before['level'] === $level - 1 && $before['remainingExp'] === 1);
        $assert($at['level'] === $level && $at['levelExp'] === 0 && $at['levelStartExp'] === $threshold);
        $assert($at['nextLevelExp'] === 50 * $level && $after['levelExp'] === 1);
    }
});

$run('Film i sezony dają uzgodniony poziom oraz postęp do następnego', static function () use ($assert): void {
    foreach ([[105, 2, 55, 100], [160, 3, 10, 150], [220, 3, 70, 150]] as [$exp, $level, $within, $next]) {
        $progress = ExperienceService::progress($exp);
        $assert($progress['totalExp'] === $exp && $progress['level'] === $level);
        $assert($progress['levelExp'] === $within && $progress['nextLevelExp'] === $next);
        $assert($progress['remainingExp'] === $next - $within);
    }
});

$run('Korekta EXP w dół od razu obniża poziom i aktualizuje pasek', static function () use ($assert): void {
    $completed = ExperienceService::progress(160);
    $corrected = ExperienceService::progress(15);
    $assert($completed['level'] === 3 && $corrected['level'] === 1);
    $assert($corrected['levelExp'] === 15 && $corrected['remainingExp'] === 35);
});

$run('Duże wyniki nie są obcinane do 32-bitowych liczb', static function () use ($assert): void {
    $progress = ExperienceService::progress(10_000_000_000);
    $assert($progress['level'] === 20_000 && $progress['levelStartExp'] === 9_999_500_000);
    $assert($progress['levelExp'] === 500_000 && $progress['nextLevelExp'] === 1_000_000);
});

$run('Zaokrąglenia nie przesuwają progów także przy wysokich poziomach', static function () use ($assert): void {
    foreach ([2, 3, 10, 101, 10_000, 1_000_000, 100_000_000] as $level) {
        $threshold = 25 * $level * ($level - 1);
        $assert(ExperienceService::progress($threshold - 1)['level'] === $level - 1);
        $assert(ExperienceService::progress($threshold)['level'] === $level);
        $assert(ExperienceService::progress($threshold + 1)['level'] === $level);
    }
    $maximum = ExperienceService::progress(PHP_INT_MAX);
    foreach ($maximum as $value) {
        $assert(is_int($value), 'Wyniki muszą pozostać całkowite także dla PHP_INT_MAX.');
    }
    $assert($maximum['levelExp'] >= 0 && $maximum['levelExp'] < $maximum['nextLevelExp']);
    $assert(PHP_INT_MAX - $maximum['levelStartExp'] === $maximum['levelExp']);
});

$run('Ujemna liczba EXP jest odrzucana', static function () use ($assert): void {
    $rejected = false;
    try {
        ExperienceService::progress(-1);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    $assert($rejected);
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
