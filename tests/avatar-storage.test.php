<?php

declare(strict_types=1);

use App\Service\AvatarStorage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Uses its own temporary directory, so it never touches storage/uploads.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$directory = sys_get_temp_dir() . '/anivibe-avatars-' . bin2hex(random_bytes(8));
$passed = 0;
$failed = 0;
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

try {
    $storage = new AvatarStorage($directory);

    $run('Plik dostaje losową nazwę, a baza tylko względną ścieżkę', static function () use ($storage, $directory, $assert): void {
        $first = $storage->save('first-image');
        $second = $storage->save('second-image');
        $assert(AvatarStorage::isValidPath($first) && AvatarStorage::isValidPath($second) && $first !== $second,
            'Każde zdjęcie ma unikalną nazwę avatars/<32 znaki hex>.png.');
        $assert(is_file($directory . '/' . $first) && $storage->read($first) === 'first-image', 'Plik zawiera zapisane zdjęcie.');
        $assert(glob($directory . '/avatars/*.tmp') === [], 'Po zapisie nie zostaje plik tymczasowy.');
    });

    $run('Odczyt przyjmuje wyłącznie ścieżki zdjęć', static function () use ($storage, $directory, $assert): void {
        file_put_contents($directory . '/secret.txt', 'secret');
        foreach (['../secret.txt', 'avatars/../secret.txt', '/etc/passwd', 'avatars/' . str_repeat('a', 32) . '.php',
            'avatars/' . str_repeat('A', 32) . '.png', '', 'avatars/' . str_repeat('a', 32) . '.png'] as $path) {
            $assert($storage->read($path) === null, 'Odrzucona ścieżka: ' . $path);
        }
    });

    $run('Sprzątanie usuwa tylko stare pliki, do których nie odwołuje się żadne konto', static function () use ($directory, $assert): void {
        $storage = new AvatarStorage($directory . '/cleanup');
        $referenced = $storage->save('referenced');
        $orphan = $storage->save('orphan');
        $recent = $storage->save('recent-orphan');
        $staleTemporary = 'avatars/' . str_repeat('d', 32) . '.png';
        file_put_contents($directory . '/cleanup/' . $staleTemporary . '.tmp', 'partial');
        file_put_contents($directory . '/cleanup/avatars/notes.txt', 'not an avatar');
        foreach ([$referenced, $orphan, $staleTemporary . '.tmp', 'avatars/notes.txt'] as $path) {
            touch($directory . '/cleanup/' . $path, time() - 7200);
        }
        $assert($storage->removeUnreferenced([$referenced], 3600) === 2, 'Usunięty osierocony plik i stary plik tymczasowy.');
        $assert($storage->read($referenced) === 'referenced', 'Używane zdjęcie zostaje.');
        $assert($storage->read($orphan) === null, 'Nieużywane stare zdjęcie znika.');
        $assert($storage->read($recent) === 'recent-orphan', 'Świeży plik czeka na zatwierdzenie zmiany w bazie.');
        $assert(is_file($directory . '/cleanup/avatars/notes.txt'), 'Inne pliki nie są usuwane.');
        $assert($storage->removeUnreferenced([$referenced], 3600) === 0, 'Ponowne uruchomienie niczego nie usuwa.');
    });
} finally {
    $remove = static function (string $path) use (&$remove): void {
        foreach (is_dir($path) ? array_diff(scandir($path) ?: [], ['.', '..']) : [] as $name) {
            is_dir($path . '/' . $name) ? $remove($path . '/' . $name) : unlink($path . '/' . $name);
        }
        if (is_dir($path)) {
            rmdir($path);
        }
    };
    $remove($directory);
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
