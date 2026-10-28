<?php

$escape = static fn (string|int|float $value): string =>
    htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    );

$assetUrl = static function (string $path) use ($escape): string {
    $version = substr(
        hash_file('sha256', dirname(__DIR__, 2) . $path),
        0,
        12,
    );

    return $escape($path . '?v=' . $version);
};

$currentPath = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH,
);

$isWelcome = $currentPath === '/';
?>

<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $escape($pageTitle) ?></title>

    <script>
        (function () {
            const storageKey = 'theme';
            let savedTheme = null;
            let systemPrefersDark = false;

            try {
                savedTheme = localStorage.getItem(storageKey);
            } catch (error) {
                // Brak dostępu do pamięci nie blokuje wyświetlania strony.
            }

            try {
                systemPrefersDark = window.matchMedia(
                    '(prefers-color-scheme: dark)'
                ).matches;
            } catch (error) {
                // Bez preferencji przeglądarki używamy jasnego motywu.
            }

            const hasSavedTheme = savedTheme === 'light' || savedTheme === 'dark';

            document.documentElement.dataset.theme = hasSavedTheme
                ? savedTheme
                : (systemPrefersDark ? 'dark' : 'light');
        })();
    </script>

    <link rel="stylesheet" href="<?= $assetUrl('/css/style.css') ?>">

    <?php if ($currentPath === '/login'): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/login.css') ?>">
    <?php endif; ?>

    <script src="<?= $assetUrl('/js/theme.js') ?>" defer></script>
</head>
<body class="<?= $isWelcome ? 'page-welcome' : 'page-standard' ?>">
    <a href="#main-content" class="skip-link">
        Przejdź do treści
    </a>

    <header class="site-header">
        <a href="/" class="site-brand" aria-label="AniVibe — strona główna">
            <span class="site-brand-icon" aria-hidden="true">🌸</span>
            <span>AniVibe</span>
        </a>

        <?php require __DIR__ . '/navigation.html.php'; ?>

        <div class="theme-controls" role="group" aria-label="Ustawienia motywu">
            <button
                class="theme-toggle"
                type="button"
                aria-label="Włącz ciemny motyw"
                aria-pressed="false"
                title="Włącz ciemny motyw"
                disabled
            >
                <svg
                    class="theme-icon-moon"
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path d="M21 12.8A9 9 0 0 1 11.2 3 9 9 0 1 0 21 12.8Z" />
                </svg>
                <svg
                    class="theme-icon-sun"
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    aria-hidden="true"
                    focusable="false"
                >
                    <circle cx="12" cy="12" r="4" />
                    <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" />
                </svg>
            </button>
            <button class="theme-system" type="button" disabled>
                Motyw systemowy
            </button>
        </div>
    </header>

    <aside class="prototype-notice" aria-label="Informacja o prototypie">
        <p>
            <strong>Wersja demonstracyjna.</strong>
            Logowanie, zapis zmian i AI nie są jeszcze podłączone.
        </p>
    </aside>

    <main id="main-content" class="page-content" tabindex="-1">
