<?php

$navigation = $isWelcome
    ? [
        '/anime' => 'Odkryj anime',
    ]
    : [
        '/dashboard' => 'Pulpit',
        '/anime' => 'Odkrywaj',
        '/chat' => 'Czat AI',
        '/messages' => 'Wiadomości',
        '/watchlist' => 'Ulubione',
        '/profile' => 'Profil',
        '/ranking' => 'Ranking',
        '/projects' => 'Projekty',
    ];
if ($isWelcome && $isLoggedIn) {
    $navigation['/messages'] = 'Wiadomości';
}
if ($isLoggedIn) {
    $navigation['/notifications/comments'] = 'Powiadomienia';
}
if ($isModerator) {
    $navigation['/moderation'] = 'Moderacja';
}
?>

<nav class="site-navigation" aria-label="Nawigacja główna">
    <ul>
        <?php foreach ($navigation as $url => $label): ?>
            <li>
                <a
                    href="<?= $escape($url) ?>"
                    <?php if ($url === '/messages' && $isLoggedIn): ?> data-notifications-link aria-label="<?= $escape('Wiadomości. Nieprzeczytane: ' . $unreadMessageCount) ?>"<?php endif; ?>
                    <?php if ($url === '/notifications/comments' && $isLoggedIn): ?> data-notifications-comment-link aria-label="<?= $escape('Powiadomienia. Nieprzeczytane: ' . ($unreadReplyCount ?? 0)) ?>"<?php endif; ?>
                    <?= $currentPath === $url
                        || ($url === '/projects' && str_starts_with((string) $currentPath, '/projects/'))
                        || ($url === '/profile' && str_starts_with((string) $currentPath, '/profile/'))
                        || ($url === '/messages' && str_starts_with((string) $currentPath, '/messages/'))
                        || ($url === '/moderation' && str_starts_with((string) $currentPath, '/moderation/'))
                        ? 'aria-current="page"' : '' ?>
                >
                    <?= $escape($label) ?>
                    <?php if ($url === '/messages' && $isLoggedIn): ?>
                        <span class="community-unread" data-unread-message-count aria-live="polite"<?= $unreadMessageCount === 0 ? ' hidden' : '' ?>><?= $escape($unreadMessageCount) ?></span>
                    <?php endif; ?>
                    <?php if ($url === '/notifications/comments' && $isLoggedIn): ?>
                        <span class="community-unread" data-unread-reply-count aria-live="polite"<?= ($unreadReplyCount ?? 0) === 0 ? ' hidden' : '' ?>><?= $escape($unreadReplyCount ?? 0) ?></span>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
        <?php if ($isLoggedIn ?? false): ?>
            <li class="account-menu">
                <a href="/users/<?= $escape($accountId) ?>" class="account-user" aria-label="<?= $escape('Profil: ' . $currentUserName) ?>">
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
