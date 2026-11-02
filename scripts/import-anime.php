<?php

declare(strict_types=1);

use App\Database;
use App\Repository\AnimeRepository;
use App\Service\AnimeImportService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $options = ['limit' => '200'];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help') {
            echo 'Import próbki publicznego zbioru anime z Hugging Face.' . PHP_EOL;
            echo 'php scripts/import-anime.php [--limit=200] [--dry-run] [--file=katalog.json] [--save=katalog.json]' . PHP_EOL;
            echo 'Tryb --dry-run nie łączy się z bazą i nie zmienia jej danych.' . PHP_EOL;
            exit(0);
        }
        if ($argument === '--dry-run') {
            $options['dry-run'] = true;
        } elseif (preg_match('/\A--(limit|file|save)=(.+)\z/', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        } else {
            throw new RuntimeException('Nieznany argument. Użyj --help, aby zobaczyć składnię.');
        }
    }

    $limit = filter_var($options['limit'], FILTER_VALIDATE_INT);
    if ($limit === false) {
        throw new RuntimeException('Limit musi być liczbą całkowitą.');
    }
    $service = new AnimeImportService();
    $animes = isset($options['file'])
        ? $service->fromFile($options['file'], $limit)
        : $service->collect($limit);

    if (isset($options['save'])) {
        $service->saveFile($options['save'], $animes);
        echo '[OK] Zapisano plik katalogu.' . PHP_EOL;
    }
    if (isset($options['dry-run'])) {
        echo '[OK] Zweryfikowano anime: ' . count($animes) . '. Baza pozostała bez zmian.' . PHP_EOL;
        exit(0);
    }

    $repository = new AnimeRepository((new Database())->connect());
    $result = $repository->importBatch($animes);
    echo sprintf(
        '[OK] Dodano: %d. Zaktualizowano: %d. Łącznie w katalogu: %d.',
        $result['inserted'],
        $result['updated'],
        $result['total'],
    ) . PHP_EOL;
} catch (Throwable $exception) {
    $message = $exception instanceof RuntimeException && !$exception instanceof PDOException
        ? $exception->getMessage()
        : 'Nie udało się zaimportować katalogu. Sprawdź schemat i dostępność bazy.';
    fwrite(STDERR, '[BŁĄD] ' . $message . PHP_EOL);
    exit(1);
}
