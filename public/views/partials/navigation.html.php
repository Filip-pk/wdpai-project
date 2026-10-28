<?php

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
