<?php

declare(strict_types=1);

use App\Database;
use App\Repository\LegacyAvatarRepository;
use App\Service\AvatarStorage;
use App\Service\LegacyAvatarMigration;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// A temporary users table and a temporary upload directory: real photos are never touched.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$passed = 0;
$failed = 0;
$connection = null;
$directory = sys_get_temp_dir() . '/anivibe-legacy-avatars-' . bin2hex(random_bytes(8));
$remove = static function (string $path) use (&$remove): void {
    foreach (is_dir($path) ? array_diff(scandir($path) ?: [], ['.', '..']) : [] as $name) {
        is_dir($path . '/' . $name) ? $remove($path . '/' . $name) : unlink($path . '/' . $name);
    }
    if (is_dir($path)) {
        rmdir($path);
    }
};

try {
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $connection->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING ALL) ON COMMIT DROP');
    $storage = new AvatarStorage($directory);
    $png = "\x89PNG\r\n\x1a\n" . 'legacy-photo';
    $otherFile = $storage->save('different-bytes');
    $insert = $connection->prepare('INSERT INTO users (id, name, email, password_hash, avatar_version, avatar_path, avatar_data)
        VALUES (:id, :name, :email, :hash, :version, :path, :data)');
    foreach ([
        [1, str_repeat('a', 32), null, $png],
        [2, str_repeat('b', 32), null, 'not-an-image'],
        [3, null, null, null],
        [4, str_repeat('d', 32), $otherFile, $png],
    ] as [$id, $version, $path, $data]) {
        $insert->bindValue('id', $id, PDO::PARAM_INT);
        $insert->bindValue('name', 'Konto ' . $id);
        $insert->bindValue('email', 'legacy-' . $id . '@example.test');
        $insert->bindValue('hash', 'hash');
        $insert->bindValue('version', $version);
        $insert->bindValue('path', $path);
        $insert->bindValue('data', $data, $data === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->execute();
    }
    $migration = new LegacyAvatarMigration(new LegacyAvatarRepository($connection), $storage);
    $row = static function (int $id) use ($connection): array {
        $statement = $connection->prepare('SELECT avatar_path, avatar_data IS NOT NULL AS has_copy FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    };

    $assert($migration->copyToFiles() === ['copied' => 1, 'skipped' => 1], 'Kopiowane jest tylko zdjęcie PNG bez pliku.');
    $first = $row(1);
    $assert(AvatarStorage::isValidPath((string) $first['avatar_path']) && $storage->read($first['avatar_path']) === $png,
        'Plik ma te same bajty co zdjęcie w bazie.');
    $assert($first['has_copy'] === true, 'Bez --clear kopia w bazie zostaje dla starszych wersji aplikacji.');
    $assert($row(2) === ['avatar_path' => null, 'has_copy' => true], 'Nieprawidłowe dane zostają nietknięte.');
    $assert($row(4)['avatar_path'] === $otherFile, 'Istniejąca ścieżka nie jest nadpisywana.');
    $assert($migration->copyToFiles() === ['copied' => 0, 'skipped' => 1], 'Ponowne kopiowanie nie tworzy nowych plików.');
    echo "[OK] Kopiowanie zapisuje pliki i ścieżki bez usuwania danych\n";
    $passed++;

    $assert($migration->clearDatabaseCopies() === 1, 'Usuwana jest tylko kopia zgodna z plikiem.');
    $assert($row(1) === ['avatar_path' => $first['avatar_path'], 'has_copy' => false], 'Zdjęcie zostaje tylko w pliku.');
    $assert($row(4)['has_copy'] === true, 'Kopia różna od pliku zostaje w bazie.');
    $assert($migration->clearDatabaseCopies() === 0, 'Ponowne czyszczenie niczego nie zmienia.');
    echo "[OK] Opcja --clear usuwa wyłącznie kopie potwierdzone plikiem\n";
    $passed++;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    $remove($directory);
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
