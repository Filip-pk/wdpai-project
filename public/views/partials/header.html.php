<?php

$escape = static fn (string|int|float $value): string =>
    htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    );

$currentPath = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH,
);

$isWelcome = $currentPath === '/';

$navigation = $isWelcome
    ? [
        '/anime' => 'Odkryj anime',
        '/login' => 'Zaloguj się',
    ]
    : [
        '/dashboard' => 'Pulpit',
        '/anime' => 'Odkrywaj',
        '/chat' => 'Czat AI',
        '/watchlist' => 'Ulubione',
        '/profile' => 'Profil',
    ];
?>

<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $escape($pageTitle) ?></title>

    <link rel="stylesheet" href="/css/style.css">

    <?php if ($currentPath === '/login'): ?>
        <link rel="stylesheet" href="/css/login.css">
    <?php endif; ?>
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

        <nav class="site-navigation" aria-label="Nawigacja główna">
            <ul>
                <?php foreach ($navigation as $url => $label): ?>
                    <li>
                        <a
                            href="<?= $escape($url) ?>"
                            <?= $currentPath === $url ? 'aria-current="page"' : '' ?>
                        >
                            <?= $escape($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </header>

    <aside class="prototype-notice" aria-label="Informacja o prototypie">
        <p>
            <strong>Wersja demonstracyjna.</strong>
            Logowanie, zapis zmian i AI nie są jeszcze podłączone.
        </p>
    </aside>

    <main id="main-content" class="page-content" tabindex="-1">