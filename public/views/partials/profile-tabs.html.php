<nav class="profile-tabs" aria-label="<?= $escape($t('view.catalog.profile_sections')) ?>">
    <a href="/profile"<?= in_array($currentPath, ['/profile', '/profile/avatar'], true) ? ' aria-current="page"' : '' ?>><?= $escape($t('view.catalog.profile')) ?></a>
    <a href="/watchlist"<?= $currentPath === '/watchlist' ? ' aria-current="page"' : '' ?>><?= $escape($t('view.catalog.watchlist')) ?></a>
    <a href="/profile/collections"<?= str_starts_with((string) $currentPath, '/profile/collections') ? ' aria-current="page"' : '' ?>><?= $escape($t('view.catalog.my_collections')) ?></a>
    <a href="/profile/import"<?= str_starts_with((string) $currentPath, '/profile/import') ? ' aria-current="page"' : '' ?>><?= $escape($t('view.catalog.mal_import')) ?></a>
</nav>
