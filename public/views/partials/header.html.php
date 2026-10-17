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

$navigation = [
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
</head>
<body>
    <a href="#main-content">Przejdź do treści</a>

    <header class="site-header">
        <a href="/" aria-label="AniVibe — strona główna">
            <span aria-hidden="true">🌸</span>
            AniVibe
        </a>

        <nav aria-label="Nawigacja główna">
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

    <aside aria-label="Informacja o wersji demonstracyjnej">
        <p>
            Prototyp HTML — dane przykładowe.
            Logowanie, zapis zmian i AI nie są jeszcze podłączone.
        </p>
    </aside>

    <main id="main-content" tabindex="-1">