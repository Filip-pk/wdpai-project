<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page profile-overview user-profile-page" data-profile-user-id="<?= $escape($profileUser['id']) ?>">
    <div class="user-profile-toolbar">
        <a class="user-profile-back" href="/ranking"><?= $escape($t('view.catalog.back_user_ranking')) ?></a>
        <?php if ($isOwnProfile): ?>
            <a class="profile-photo-link" href="/profile"><?= $escape($t('view.catalog.back_account_settings')) ?></a>
        <?php endif; ?>
    </div>
    <?php if ($blockNotice !== null): ?>
        <p class="profile-notice" role="status"><?= $escape($translateText($blockNotice)) ?></p>
    <?php endif; ?>

    <div class="profile-grid">
        <div class="profile-sidebar">
            <header class="profile-summary user-profile-summary">
                <div class="profile-summary-top">
                    <div class="profile-avatar" aria-hidden="true">
                        <?php if ($profileUser['avatar_url'] !== null): ?>
                            <img src="<?= $escape($profileUser['avatar_url']) ?>" alt="" width="80" height="80" decoding="async">
                        <?php else: ?>
                            ✦
                        <?php endif; ?>
                    </div>
                    <span class="user-profile-badge"><?= $escape($t('view.catalog.public_profile')) ?></span>
                </div>
                <div class="profile-identity">
                    <p class="profile-card-label"><?= $isOwnProfile ? $escape($t('view.catalog.my_space')) : $escape($t('view.catalog.community')) ?></p>
                    <h1><?= $escape($profileUser['name']) ?></h1>
                    <p class="user-profile-joined"><?= $escape($t('view.catalog.joined')) ?> <time datetime="<?= $escape(substr($profileUser['created_at'], 0, 10)) ?>"><?= $escape(substr($profileUser['created_at'], 0, 10)) ?></time></p>
                </div>

                <p class="profile-interests"><?= $escape($profileUser['interests'] === '' ? $t('view.catalog.user_no_interests') : $profileUser['interests']) ?></p>

                <div class="user-profile-actions">
                    <a class="profile-public-link" href="#watchlist-title"><?= $escape($t('view.catalog.see_watchlist')) ?> <span aria-hidden="true">↓</span></a>
                    <?php if ($isOwnProfile): ?>
                        <a class="user-profile-action" href="/profile"><?= $escape($t('view.catalog.edit_profile')) ?></a>
                    <?php else: ?>
                        <?php if (!$blockState['blockedEitherWay'] && $canMessageProfile): ?>
                            <a class="user-profile-action" href="<?= $isLoggedIn ? '/messages/new/' . $escape($profileUser['id']) : '/login' ?>"><?= $escape($t('view.catalog.write_message')) ?></a>
                        <?php else: ?>
                            <p><?= $escape($t('view.catalog.messaging_unavailable')) ?></p>
                        <?php endif; ?>
                        <?php if ($isLoggedIn): ?>
                            <form class="community-inline-form" action="/users/<?= $escape($profileUser['id']) ?>/<?= $blockState['blockedByMe'] ? 'unblock' : 'block' ?>" method="post">
                                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                                <button type="submit" data-block-user-id="<?= $escape($profileUser['id']) ?>"><?= $blockState['blockedByMe'] ? $escape($t('view.catalog.unblock_user')) : $escape($t('view.catalog.block_user')) ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($showStatistics): ?>
            <?php
            $experienceTitle = $isOwnProfile ? $t('view.catalog.your_level') : $t('view.catalog.user_level');
            require __DIR__ . '/partials/experience.html.php';
            ?>
            <?php endif; ?>
        </div>

        <div class="user-profile-content">
            <?php if ($showStatistics): ?>
            <section class="profile-summary user-profile-statistics" aria-labelledby="user-statistics-title">
                <header class="profile-editor-heading">
                    <span class="profile-editor-symbol" aria-hidden="true">♡</span>
                    <div>
                        <h2 id="user-statistics-title"><?= $escape($t('view.catalog.watching_stats')) ?></h2>
                        <p><?= $escape($t('view.catalog.stats_intro')) ?></p>
                    </div>
                </header>
                <dl class="profile-stats">
                    <div><dt><?= $escape($t('view.catalog.titles_on_watchlist')) ?></dt><dd data-watchlist-count="<?= $escape($statistics['watchlistTotal']) ?>"><?= $escape($statistics['watchlistTotal']) ?></dd></div>
                    <div><dt><?= $escape($t('view.catalog.completed_titles')) ?></dt><dd data-completed-count="<?= $escape($statistics['completedCount']) ?>"><?= $escape($statistics['completedCount']) ?></dd></div>
                    <div><dt><?= $escape($t('view.catalog.currently_watching')) ?></dt><dd data-watching-count="<?= $escape($statistics['watchingCount']) ?>"><?= $escape($statistics['watchingCount']) ?></dd></div>
                    <div><dt><?= $escape($t('view.catalog.watched_episodes')) ?></dt><dd data-watched-episodes="<?= $escape($statistics['watchedEpisodes']) ?>"><?= $escape(number_format($statistics['watchedEpisodes'], 0, ',', ' ')) ?></dd></div>
                </dl>
                <p><?= $escape($t('view.catalog.stats_help')) ?></p>
            </section>
            <?php else: ?>
                <p class="profile-notice"><?= $escape($t('view.catalog.stats_hidden')) ?></p>
            <?php endif; ?>

            <div class="profile-watchlist">
                <section aria-labelledby="watchlist-title">
                    <h2 id="watchlist-title"><?= $escape($t('view.catalog.user_watchlist')) ?></h2>
                    <?php if ($showStatistics): ?>
                        <p><?= $escape($t('view.catalog.title_count')) ?> <?= $escape($statistics['watchlistTotal']) ?><?php if ($statusFilter !== ''): ?> <?= $escape($t('view.catalog.selected_status_count')) ?> <?= $escape($watchlistPagination['total']) ?><?php endif; ?></p>
                    <?php endif; ?>

                    <form action="<?= $escape($profilePath) ?>" method="get" class="watchlist-toolbar">
                        <label for="watchlist-status"><?= $escape($t('view.catalog.watch_status')) ?></label>
                        <select id="watchlist-status" name="status">
                            <option value=""><?= $escape($t('view.catalog.all')) ?></option>
                            <?php foreach ($watchlistStatuses as $value => $label): ?>
                                <option value="<?= $escape($value) ?>"<?= $statusFilter === $value ? ' selected' : '' ?>><?= $escape($translateText($label)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit"><?= $escape($t('view.catalog.filter')) ?></button>
                    </form>

                    <?php if ($visibleWatchlist === []): ?>
                        <p class="profile-empty"><?= $watchlistPagination['watchlistTotal'] === 0 ? $escape($t('view.catalog.user_watchlist_empty')) : $escape($t('view.catalog.watchlist_no_status')) ?></p>
                    <?php else: ?>
                        <ul class="anime-grid">
                            <?php foreach ($visibleWatchlist as $entry): ?>
                                <?php
                                $animeId = $entry['animeId'];
                                $anime = $animes[$animeId];
                                ?>
                                <li data-anime-id="<?= $escape($animeId) ?>" data-watchlist-status="<?= $escape($entry['status']) ?>" data-watched="<?= $escape($entry['watched']) ?>">
                                    <article class="watchlist-card">
                                        <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                                        <?php if ($showRatings && isset($profileRatings[$animeId])): ?>
                                            <p data-profile-rating="<?= $escape($profileRatings[$animeId]) ?>"><?= $escape($t('view.catalog.user_score')) ?> <?= $escape($profileRatings[$animeId]) ?> / 10</p>
                                        <?php endif; ?>
                                        <p><?= $escape($t('view.catalog.status_prefix')) ?> <?= $escape($translateText($watchlistStatuses[$entry['status']])) ?></p>
                                        <?php if ($anime['episodes'] !== null && $anime['episodes'] > 0): ?>
                                            <label for="user-progress-<?= $escape($animeId) ?>"><?= $escape($t('view.catalog.watched_prefix')) ?> <?= $escape($entry['watched']) ?> / <?= $escape($anime['episodes']) ?> <?= $escape($t('view.catalog.episodes_suffix')) ?></label>
                                            <progress id="user-progress-<?= $escape($animeId) ?>" value="<?= $escape($entry['watched']) ?>" max="<?= $escape($anime['episodes']) ?>"><?= $escape($entry['watched']) ?> <?= $escape($t('view.catalog.episodes_suffix')) ?></progress>
                                        <?php else: ?>
                                            <p><?= $escape($t('view.catalog.watched_prefix')) ?> <?= $escape($entry['watched']) ?> <?= $escape($t('view.catalog.episodes_unknown_total')) ?></p>
                                        <?php endif; ?>
                                    </article>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if ($watchlistPagination['totalPages'] > 1): ?>
                        <nav class="watchlist-pagination" aria-label="<?= $escape($t('view.catalog.user_watchlist_pages')) ?>">
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
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
