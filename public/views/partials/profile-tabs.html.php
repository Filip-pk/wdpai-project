<nav class="profile-tabs" aria-label="Sekcje profilu">
    <a href="/profile"<?= in_array($currentPath, ['/profile', '/profile/avatar'], true) ? ' aria-current="page"' : '' ?>>Profil</a>
    <a href="/watchlist"<?= $currentPath === '/watchlist' ? ' aria-current="page"' : '' ?>>Watchlista</a>
    <a href="/profile/collections"<?= str_starts_with((string) $currentPath, '/profile/collections') ? ' aria-current="page"' : '' ?>>Moje kolekcje</a>
    <a href="/profile/import"<?= str_starts_with((string) $currentPath, '/profile/import') ? ' aria-current="page"' : '' ?>>Import MAL</a>
</nav>
