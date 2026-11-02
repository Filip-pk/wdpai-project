<?php

$navigation = $isWelcome
    ? [
        '/anime' => 'Odkryj anime',
    ]
    : [
        '/dashboard' => 'Pulpit',
        '/anime' => 'Odkrywaj',
        '/chat' => 'Czat AI',
        '/watchlist' => 'Ulubione',
        '/profile' => 'Profil',
        '/projects' => 'Projekty',
    ];
?>

<nav class="site-navigation" aria-label="Nawigacja główna">
    <ul>
        <?php foreach ($navigation as $url => $label): ?>
            <li>
                <a
                    href="<?= $escape($url) ?>"
                    <?= $currentPath === $url
                        || ($url === '/projects' && str_starts_with((string) $currentPath, '/projects/'))
                        || ($url === '/profile' && $currentPath === '/profile/avatar')
                        ? 'aria-current="page"' : '' ?>
                >
                    <?= $escape($label) ?>
                </a>
            </li>
        <?php endforeach; ?>
        <?php if ($isLoggedIn ?? false): ?>
            <li class="account-menu">
                <a href="/profile" class="account-user" aria-label="<?= $escape('Profil: ' . $currentUserName) ?>">
                    <span class="account-avatar" aria-hidden="true">
                        <?php if (!empty($currentUserAvatarUrl)): ?>
                            <img src="<?= $escape($currentUserAvatarUrl) ?>" alt="" width="32" height="32" decoding="async">
                        <?php else: ?>
                            ✦
                        <?php endif; ?>
                    </span>
                    <span class="account-name"><?= $escape($currentUserName) ?></span>
                </a>
                <form method="post" action="/logout" class="account-logout">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <button type="submit">Wyloguj</button>
                </form>
            </li>
        <?php else: ?>
            <li>
                <a href="/login" <?= $currentPath === '/login' ? 'aria-current="page"' : '' ?>>
                    Zaloguj się
                </a>
            </li>
            <li>
                <a href="/register" <?= $currentPath === '/register' ? 'aria-current="page"' : '' ?>>
                    Zarejestruj się
                </a>
            </li>
        <?php endif; ?>
    </ul>
</nav>
