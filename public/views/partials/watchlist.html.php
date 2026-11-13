<section aria-labelledby="watchlist-title">
    <h2 id="watchlist-title"><?= $escape($t('view.catalog.your_watchlist')) ?></h2>

    <p><?= $escape($t('view.catalog.title_count')) ?> <?= $escape($watchlistTotal) ?><?php if ($statusFilter !== ''): ?> <?= $escape($t('view.catalog.selected_status_count')) ?> <?= $escape($watchlistPagination['total']) ?><?php endif; ?></p>

    <form action="<?= $watchlistOnly ? '/watchlist' : '/profile' ?>" method="get" class="watchlist-toolbar">
        <label for="watchlist-status"><?= $escape($t('view.catalog.watch_status')) ?></label>
        <select id="watchlist-status" name="status">
            <option value=""><?= $escape($t('view.catalog.all')) ?></option>
            <?php foreach ($watchlistStatuses as $value => $label): ?>
                <option value="<?= $escape($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= $escape($translateText($label)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= $escape($t('view.catalog.filter')) ?></button>
    </form>

    <?php if ($visibleWatchlist === []): ?>
        <div class="profile-empty">
            <p><?= $watchlistTotal === 0 ? $escape($t('view.catalog.watchlist_empty')) : $escape($t('view.catalog.watchlist_no_status')) ?></p>
            <a href="/anime"><?= $escape($t('view.catalog.discover_link')) ?></a>
        </div>
    <?php endif; ?>

    <ul class="anime-grid">
        <?php foreach ($visibleWatchlist as $entry): ?>
            <?php
            $animeId = $entry['animeId'];
            $anime = $animes[$animeId];
            ?>

            <li data-anime-id="<?= $escape($animeId) ?>" data-watchlist-status="<?= $escape($entry['status']) ?>" data-watched="<?= $escape($entry['watched']) ?>">
                <article class="watchlist-card">
                <?php require __DIR__ . '/anime-card.html.php'; ?>

                <p><?= $escape($t('view.catalog.status_prefix')) ?> <?= $escape($translateText($watchlistStatuses[$entry['status']])) ?></p>

                <p class="watchlist-rating" data-user-rating="<?= $escape($userRatings[$animeId] ?? '') ?>">
                    <?= $escape($t('view.catalog.your_score_prefix')) ?> <?= isset($userRatings[$animeId]) ? $escape($userRatings[$animeId]) . ' / 10' : $escape($t('view.catalog.no_score')) ?>
                    <a href="/anime/<?= $escape($animeId) ?>#anime-rating-title"><?= isset($userRatings[$animeId]) ? $escape($t('view.catalog.change_score')) : $escape($t('view.catalog.rate_anime')) ?></a>
                </p>

                <label for="progress-<?= $escape($animeId) ?>">
                    <?= $escape($t('view.catalog.watched_prefix')) ?>
                    <?= $escape($entry['watched']) ?>
                    /
                    <?= $anime['episodes'] !== null && $anime['episodes'] > 0 ? $escape($anime['episodes']) : '?' ?>
                    <?= $escape($t('view.catalog.episodes_suffix')) ?>
                </label>

                <?php if ($anime['episodes'] !== null && $anime['episodes'] > 0): ?>
                <progress
                    id="progress-<?= $escape($animeId) ?>"
                    value="<?= $escape($entry['watched']) ?>"
                    max="<?= $escape($anime['episodes']) ?>"
                >
                    <?= $escape($entry['watched']) ?> <?= $escape($t('view.catalog.episodes_suffix')) ?>
                </progress>
                <?php endif; ?>
                <form action="/watchlist" method="post" class="watchlist-edit">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="animeId" value="<?= $escape($animeId) ?>">
                    <input type="hidden" name="listPage" value="<?= $escape($watchlistPagination['page']) ?>">
                    <input type="hidden" name="listStatus" value="<?= $escape($statusFilter) ?>">
                    <input type="hidden" name="listView" value="<?= $watchlistOnly ? 'watchlist' : 'profile' ?>">
                    <label for="entry-status-<?= $escape($animeId) ?>"><?= $escape($t('view.catalog.status')) ?></label>
                    <select id="entry-status-<?= $escape($animeId) ?>" name="status">
                        <?php foreach ($watchlistStatuses as $value => $label): ?>
                            <option value="<?= $escape($value) ?>" <?= $entry['status'] === $value ? 'selected' : '' ?>><?= $escape($translateText($label)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="entry-watched-<?= $escape($animeId) ?>"><?= $escape($t('view.catalog.watched_episodes')) ?></label>
                    <input id="entry-watched-<?= $escape($animeId) ?>" name="watched" type="number" min="0" max="<?= $escape($anime['episodes'] !== null && $anime['episodes'] > 0 ? $anime['episodes'] : 2147483647) ?>" value="<?= $escape($entry['watched']) ?>" required>
                    <button type="submit"><?= $escape($t('view.catalog.save_progress')) ?></button>
                </form>
                <form action="/watchlist/remove" method="post" class="watchlist-remove">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="animeId" value="<?= $escape($animeId) ?>">
                    <input type="hidden" name="listPage" value="<?= $escape($watchlistPagination['page']) ?>">
                    <input type="hidden" name="listStatus" value="<?= $escape($statusFilter) ?>">
                    <input type="hidden" name="listView" value="<?= $watchlistOnly ? 'watchlist' : 'profile' ?>">
                    <button type="submit" aria-label="<?= $escape($t('view.catalog.remove_watchlist_label', ['title' => $anime['title']])) ?>"><?= $escape($t('view.catalog.remove_from_list')) ?></button>
                </form>
                </article>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($watchlistPagination['totalPages'] > 1): ?>
        <nav class="watchlist-pagination" aria-label="<?= $escape($t('view.catalog.watchlist_pages')) ?>">
            <?php if ($watchlistPagination['page'] > 1): ?>
                <a href="<?= $escape($watchlistPagination['previousUrl']) ?>" rel="prev"><?= $escape($t('view.catalog.previous')) ?></a>
            <?php endif; ?>
            <span><?= $escape($t('view.catalog.page_position', ['page' => $watchlistPagination['page'], 'pages' => $watchlistPagination['totalPages']])) ?></span>
            <?php if ($watchlistPagination['page'] < $watchlistPagination['totalPages']): ?>
                <a href="<?= $escape($watchlistPagination['nextUrl']) ?>" rel="next"><?= $escape($t('view.catalog.next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
