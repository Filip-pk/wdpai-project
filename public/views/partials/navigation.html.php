<?php

$primaryNavigation = [
    '/dashboard' => $t('navigation.discover'),
    '/chat' => $t('navigation.chat'),
    '/watchlist' => $t('navigation.favorites'),
    '/profile' => $t('navigation.profile'),
];
$secondaryNavigation = [
    '/anime' => $t('navigation.catalog'),
    '/anime/ranking' => $t('navigation.anime_ranking'),
    '/messages' => $t('navigation.messages'),
    '/ranking' => $t('navigation.user_ranking'),
    '/projects' => $t('navigation.projects'),
];
if ($isLoggedIn) {
    $secondaryNavigation['/notifications/comments'] = $t('navigation.notifications');
    $secondaryNavigation['/settings'] = $t('navigation.settings');
}
if ($isModerator) {
    $secondaryNavigation['/moderation'] = $t('navigation.moderation');
}
$isNavigationCurrent = static fn (string $url): bool => $currentPath === $url
    || ($url === '/chat' && str_starts_with((string) $currentPath, '/chat/'))
    || ($url === '/projects' && str_starts_with((string) $currentPath, '/projects/'))
    || ($url === '/profile' && (str_starts_with((string) $currentPath, '/profile/') || $isUserProfilePage))
    || ($url === '/messages' && str_starts_with((string) $currentPath, '/messages/'))
    || ($url === '/settings' && str_starts_with((string) $currentPath, '/settings/'))
    || ($url === '/moderation' && str_starts_with((string) $currentPath, '/moderation/'));
$navigationIcon = static function (string $url): string {
    $paths = [
        '/dashboard' => '<circle cx="12" cy="12" r="9"/><path d="m16 8-2.5 5.5L8 16l2.5-5.5Z"/>',
        '/chat' => '<rect x="4" y="6" width="16" height="14" rx="4"/><path d="M12 3v3M8 12h.01M16 12h.01M9 16h6M1 11v4M23 11v4"/>',
        '/watchlist' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        '/profile' => '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2Z"/>',
        '/anime' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="m10 8 6 4-6 4Z"/>',
        '/messages' => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-3 3V11.5A8.5 8.5 0 0 1 9.5 3h3a8.5 8.5 0 0 1 8.5 8.5Z"/><path d="M7 9h8M7 13h5"/>',
        '/notifications/comments' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        '/projects' => '<rect x="3" y="6" width="18" height="15" rx="2"/><path d="M8 6V3h8v3M3 12h18M10 12v3h4v-3"/>',
        '/settings' => '<path d="m10 2-1 3-3 1-3 3 2 3-1 4 3 2 3-1 3 2 3-2 1-4 3-2-1-4-3-1-1-3Z"/><circle cx="12" cy="12" r="3"/>',
        '/moderation' => '<path d="m12 2 9 4v6c0 5-9 10-9 10S3 17 3 12V6Z"/><path d="m8 12 3 3 5-6"/>',
    ];
    $path = $paths[$url] ?? '<path d="M8 3h8v4a4 4 0 0 1-8 0ZM8 5H4v2a4 4 0 0 0 5 4M16 5h4v2a4 4 0 0 1-5 4M12 11v7M8 21h8M9 18h6"/>';

    return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
};
?>

<nav class="site-navigation" aria-label="<?= $escape($t('navigation.main')) ?>">
    <?php if ($isWorkspace): ?>
        <a href="/" class="navigation-brand"><span aria-hidden="true">🌸</span> AniVibe</a>
        <p class="navigation-caption"><?= $escape($t('navigation.tagline')) ?></p>
        <ul class="navigation-primary">
            <?php foreach ($primaryNavigation as $url => $label): ?>
                <li>
                    <a href="<?= $escape($url) ?>"<?= $isNavigationCurrent($url) ? ' aria-current="page"' : '' ?>>
                        <?= $navigationIcon($url) ?>
                        <span><?= $escape($label) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
            <li class="navigation-more-item" hidden>
                <button type="button" data-navigation-more aria-expanded="false" aria-controls="navigation-panel">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg>
                    <span><?= $escape($t('navigation.more')) ?></span>
                    <?php if ($isLoggedIn): ?>
                        <span class="navigation-unread-indicators">
                            <span class="community-unread" data-unread-message-count aria-live="polite" title="<?= $escape($t('navigation.unread_messages')) ?>"<?= $unreadMessageCount === 0 ? ' hidden' : '' ?>><?= $escape($unreadMessageCount) ?></span>
                            <span class="community-unread" data-unread-reply-count aria-live="polite" title="<?= $escape($t('navigation.unread_replies')) ?>"<?= ($unreadReplyCount ?? 0) === 0 ? ' hidden' : '' ?>><?= $escape($unreadReplyCount ?? 0) ?></span>
                        </span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>
    <?php else: ?>
        <ul class="navigation-public-links">
            <li><a href="/anime"><?= $escape($t('navigation.explore')) ?></a></li>
            <?php if (!$isLoggedIn): ?>
                <li><a href="/login"<?= $currentPath === '/login' ? ' aria-current="page"' : '' ?>><?= $escape($t('navigation.login')) ?></a></li>
                <li><a href="/register" class="navigation-register"<?= $currentPath === '/register' ? ' aria-current="page"' : '' ?>><?= $escape($t('navigation.join')) ?> <span aria-hidden="true">↗</span></a></li>
            <?php else: ?>
                <li><a href="/dashboard"><?= $escape($t('navigation.your_dashboard')) ?> <span aria-hidden="true">→</span></a></li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>

    <details class="navigation-menu">
        <summary class="navigation-toggle" aria-controls="navigation-panel">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span class="navigation-toggle-label"><?= $escape($t('navigation.menu')) ?></span>
            <?php if ($isLoggedIn): ?>
                <span class="navigation-unread-indicators">
                    <span class="community-unread" data-unread-message-count aria-live="polite" title="<?= $escape($t('navigation.unread_messages')) ?>"<?= $unreadMessageCount === 0 ? ' hidden' : '' ?>><?= $escape($unreadMessageCount) ?></span>
                    <span class="community-unread" data-unread-reply-count aria-live="polite" title="<?= $escape($t('navigation.unread_replies')) ?>"<?= ($unreadReplyCount ?? 0) === 0 ? ' hidden' : '' ?>><?= $escape($unreadReplyCount ?? 0) ?></span>
                </span>
            <?php endif; ?>
        </summary>
        <div class="navigation-panel" id="navigation-panel">
            <p class="navigation-panel-title"><?= $isWorkspace ? $t('navigation.discover_more') : $t('navigation.welcome') ?></p>
            <ul class="navigation-secondary">
                <?php foreach (($isWorkspace ? [] : $primaryNavigation) + $secondaryNavigation as $url => $label): ?>
                    <li>
                        <a href="<?= $escape($url) ?>"<?= $isNavigationCurrent($url) ? ' aria-current="page"' : '' ?>
                            <?php if ($url === '/messages' && $isLoggedIn): ?> data-notifications-link aria-label="<?= $escape($t('navigation.messages_count', ['count' => $unreadMessageCount])) ?>"<?php endif; ?>
                            <?php if ($url === '/notifications/comments' && $isLoggedIn): ?> data-notifications-comment-link aria-label="<?= $escape($t('navigation.notifications_count', ['count' => $unreadReplyCount ?? 0])) ?>"<?php endif; ?>
                        >
                            <?= $navigationIcon($url) ?>
                            <span><?= $escape($label) ?></span>
                            <?php if ($url === '/messages' && $isLoggedIn): ?>
                                <span class="community-unread" data-unread-message-count aria-live="polite"<?= $unreadMessageCount === 0 ? ' hidden' : '' ?>><?= $escape($unreadMessageCount) ?></span>
                            <?php endif; ?>
                            <?php if ($url === '/notifications/comments' && $isLoggedIn): ?>
                                <span class="community-unread" data-unread-reply-count aria-live="polite"<?= ($unreadReplyCount ?? 0) === 0 ? ' hidden' : '' ?>><?= $escape($unreadReplyCount ?? 0) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="/language" class="language-switcher">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="returnTo" value="<?= $escape($_SERVER['REQUEST_URI'] ?? '/') ?>">
                <label for="navigation-language"><?= $escape($t('language.label')) ?></label>
                <div class="language-switcher-controls">
                    <select id="navigation-language" name="language">
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?= $escape($code) ?>" lang="<?= $escape($code) ?>"<?= $locale === $code ? ' selected' : '' ?>><?= $escape($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"><?= $escape($t('language.save')) ?></button>
                </div>
            </form>
            <?php if ($isLoggedIn): ?>
                <div class="account-menu">
                    <a href="/users/<?= $escape($accountId) ?>" class="account-user" aria-label="<?= $escape($t('navigation.profile_name', ['user' => $currentUserName])) ?>">
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
                        <button type="submit"><?= $escape($t('navigation.logout')) ?></button>
                    </form>
                </div>
            <?php else: ?>
                <div class="navigation-account-links">
                    <a href="/login"<?= $currentPath === '/login' ? ' aria-current="page"' : '' ?>><?= $escape($t('navigation.login')) ?></a>
                    <a href="/register"<?= $currentPath === '/register' ? ' aria-current="page"' : '' ?>><?= $escape($t('navigation.register')) ?></a>
                </div>
            <?php endif; ?>
        </div>
    </details>
</nav>
