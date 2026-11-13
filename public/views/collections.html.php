<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page collections-page<?= $selectedCollection === null ? ' collections-library-page' : ' collection-details-page' ?>">
    <header class="profile-heading collections-heading">
        <div class="collections-heading-copy">
            <p class="eyebrow"><?= $escape($t('view.catalog.just_you')) ?></p>
            <h1><?= $selectedCollection === null ? $escape($t('view.catalog.my_collections')) : $escape($selectedCollection['name']) ?></h1>
            <p><?= $selectedCollection === null ? $escape($t('view.catalog.collections_intro')) : $escape($selectedCollection['description'] === '' ? $t('view.catalog.private_list_default') : $selectedCollection['description']) ?></p>
        </div>
        <?php if ($selectedCollection === null): ?>
            <a class="collection-create-link" href="#collection-create-title"><span aria-hidden="true">＋</span> <?= $escape($t('view.catalog.new_collection')) ?></a>
        <?php else: ?>
            <span class="collection-privacy-badge"><span aria-hidden="true">◇</span> <?= $escape($t('view.catalog.private_collection')) ?></span>
        <?php endif; ?>
    </header>

    <?php require __DIR__ . '/partials/profile-tabs.html.php'; ?>

    <?php if ($notice !== null): ?>
        <p class="profile-notice" data-kind="success" role="status"><?= $escape($translateText($notice)) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="profile-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($selectedCollection === null): ?>
        <div class="collections-layout">
            <section class="collections-library" aria-labelledby="collections-title">
                <div class="collections-section-heading">
                    <h2 id="collections-title"><?= $escape($t('view.catalog.your_lists')) ?></h2>
                    <span class="collection-private" data-collections-total="<?= $escape($collectionsPagination['total']) ?>"><?= $escape($t('view.catalog.private_plural')) ?> <?= $escape($collectionsPagination['total']) ?></span>
                </div>
                <ul class="collection-grid">
                    <?php foreach ($collections as $collection): ?>
                        <li>
                            <article class="collection-card" data-collection-id="<?= $escape($collection['id']) ?>">
                                <a class="collection-card-link" href="/profile/collections/<?= $escape($collection['id']) ?>">
                                    <div class="collection-previews" aria-hidden="true">
                                        <?php for ($previewIndex = 0; $previewIndex < 4; $previewIndex++): ?>
                                            <span class="collection-preview">
                                                <span>✦</span>
                                                <?php if (!empty($collection['previews'][$previewIndex]['coverUrl'])): ?>
                                                    <img data-anime-cover src="<?= $escape($collection['previews'][$previewIndex]['coverUrl']) ?>" alt="" width="120" height="180" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                                <?php endif; ?>
                                            </span>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="collection-card-copy">
                                        <span class="collection-private"><?= $collection['isDefault'] ? $escape($t('view.catalog.starter_private')) : $escape($t('view.catalog.private_collection')) ?></span>
                                        <h3><?= $escape($collection['name']) ?></h3>
                                        <p class="collection-description"><?= $escape($collection['description'] === '' ? $t('view.catalog.collection_default_help') : $collection['description']) ?></p>
                                        <span class="collection-count"><?= $escape($t('view.catalog.anime_count')) ?> <?= $escape($collection['animeCount']) ?> <span aria-hidden="true">→</span></span>
                                    </div>
                                </a>
                            </article>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($collectionsPagination['totalPages'] > 1): ?>
                    <nav class="collection-pagination" aria-label="<?= $escape($t('view.catalog.collections_pages')) ?>">
                        <?php if ($collectionsPagination['page'] > 1): ?>
                            <a href="/profile/collections?page=<?= $escape($collectionsPagination['page'] - 1) ?>" rel="prev"><?= $escape($t('view.catalog.previous')) ?></a>
                        <?php endif; ?>
                        <span><?= $escape($t('view.catalog.page_position', ['page' => $collectionsPagination['page'], 'pages' => $collectionsPagination['totalPages']])) ?></span>
                        <?php if ($collectionsPagination['page'] < $collectionsPagination['totalPages']): ?>
                            <a href="/profile/collections?page=<?= $escape($collectionsPagination['page'] + 1) ?>" rel="next"><?= $escape($t('view.catalog.next')) ?></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>

            <section class="collection-panel collection-create-panel" aria-labelledby="collection-create-title">
                <div class="collection-panel-heading">
                    <span class="collection-panel-symbol" aria-hidden="true">＋</span>
                    <h2 id="collection-create-title"><?= $escape($t('view.catalog.new_collection')) ?></h2>
                </div>
                <p><?= $escape($t('view.catalog.collection_name_help')) ?></p>
                <form action="/profile/collections" method="post" class="collection-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="profile-field">
                        <label for="collection-name"><?= $escape($t('view.catalog.collection_name')) ?></label>
                        <input id="collection-name" name="name" type="text" maxlength="120" value="<?= $escape($collectionForm['name']) ?>" placeholder="<?= $escape($t('view.catalog.collection_weekend')) ?>" required>
                    </div>
                    <div class="profile-field">
                        <label for="collection-description"><?= $escape($t('view.catalog.short_description')) ?> <span><?= $escape($t('view.catalog.optional')) ?></span></label>
                        <textarea id="collection-description" name="description" rows="3" maxlength="500"><?= $escape($collectionForm['description']) ?></textarea>
                    </div>
                    <button type="submit"><?= $escape($t('view.catalog.create_collection')) ?></button>
                </form>
                <p class="collection-help"><?= $escape($t('view.catalog.private_collections_help')) ?></p>
            </section>
        </div>
    <?php else: ?>
        <div class="collection-toolbar">
            <a href="/profile/collections"><?= $escape($t('view.catalog.all_collections')) ?></a>
            <span class="collection-private"><?= $escape($t('view.catalog.private_count')) ?> <?= $escape($pagination['total']) ?></span>
        </div>

        <div class="collection-detail-layout">
            <details class="collection-panel collection-editor"<?= $editForm['name'] !== $selectedCollection['name'] || $editForm['description'] !== $selectedCollection['description'] ? ' open' : '' ?>>
                <summary><?= $escape($t('view.catalog.edit_collection')) ?></summary>
                <form action="/profile/collections/<?= $escape($selectedCollection['id']) ?><?= $pagination['page'] > 1 ? '?page=' . $escape($pagination['page']) : '' ?>" method="post" class="collection-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="profile-field">
                        <label for="collection-edit-name"><?= $escape($t('view.catalog.collection_name')) ?></label>
                        <input id="collection-edit-name" name="name" type="text" maxlength="120" value="<?= $escape($editForm['name']) ?>" required>
                    </div>
                    <div class="profile-field">
                        <label for="collection-edit-description"><?= $escape($t('view.catalog.short_description')) ?> <span><?= $escape($t('view.catalog.optional')) ?></span></label>
                        <textarea id="collection-edit-description" name="description" rows="3" maxlength="500"><?= $escape($editForm['description']) ?></textarea>
                    </div>
                    <button type="submit"><?= $escape($t('view.catalog.save_collection')) ?></button>
                </form>
            </details>

            <section class="collection-content" aria-labelledby="collection-anime-title">
                <div class="collections-section-heading">
                    <h2 id="collection-anime-title"><?= $escape($t('view.catalog.collection_anime')) ?></h2>
                    <a href="/anime"><?= $escape($t('view.catalog.find_collection_anime')) ?></a>
                </div>
                <p class="collection-help"><?= $escape($t('view.catalog.collection_watchlist_help')) ?></p>

                <?php if ($collectionAnimes === []): ?>
                    <div class="collection-empty">
                        <span aria-hidden="true">✦</span>
                        <h3><?= $escape($t('view.catalog.collection_start')) ?></h3>
                        <p><?= $escape($t('view.catalog.collection_start_help')) ?></p>
                        <a href="/anime"><?= $escape($t('view.catalog.browse_catalog_arrow')) ?></a>
                    </div>
                <?php else: ?>
                    <ul class="collection-anime-grid">
                        <?php foreach ($collectionAnimes as $collectionAnimeId => $collectionAnime): ?>
                            <li>
                                <article class="collection-anime-card" data-collection-anime-id="<?= $escape($collectionAnimeId) ?>">
                                    <a class="anime-cover collection-anime-cover" href="/anime/<?= $escape($collectionAnimeId) ?>" aria-label="<?= $escape($t('view.catalog.details_label', ['title' => $collectionAnime['title']])) ?>">
                                        <span class="anime-cover-placeholder" aria-hidden="true"><span>✦</span><span><?= $escape($t('view.catalog.no_cover')) ?></span></span>
                                        <?php if (!empty($collectionAnime['coverUrl'])): ?>
                                            <img data-anime-cover src="<?= $escape($collectionAnime['coverUrl']) ?>" alt="" width="200" height="300" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                        <?php endif; ?>
                                    </a>
                                    <div class="collection-anime-copy">
                                        <h3><a href="/anime/<?= $escape($collectionAnimeId) ?>"><?= $escape($collectionAnime['title']) ?></a></h3>
                                        <dl class="collection-community-rating">
                                            <?php $animeId = $collectionAnimeId; require __DIR__ . '/partials/community-anime-rating.html.php'; ?>
                                        </dl>
                                        <?php $entry = $collectionWatchlist[$collectionAnimeId] ?? null; ?>
                                        <?php if ($entry !== null): ?>
                                            <p class="collection-watchlist-status"><?= $escape(['planned' => $t('view.catalog.status_planned'), 'watching' => $t('view.catalog.status_watching'), 'completed' => $t('view.catalog.status_completed'), 'on_hold' => $t('view.catalog.status_on_hold'), 'dropped' => $t('view.catalog.status_dropped')][$entry['status']] ?? $entry['status']) ?></p>
                                            <p class="collection-help"><?= $escape($t('view.catalog.watched_episodes_prefix')) ?> <?= $escape($entry['watched']) ?><?= ($collectionAnime['episodes'] ?? 0) > 0 ? ' / ' . $escape($collectionAnime['episodes']) : '' ?></p>
                                        <?php else: ?>
                                            <p class="collection-help"><?= $escape($t('view.catalog.outside_watchlist')) ?></p>
                                        <?php endif; ?>
                                        <form action="/profile/collections/<?= $escape($selectedCollection['id']) ?>/remove<?= $pagination['page'] > 1 ? '?page=' . $escape($pagination['page']) : '' ?>" method="post" class="collection-remove-form">
                                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="animeId" value="<?= $escape($collectionAnimeId) ?>">
                                            <button type="submit" aria-label="<?= $escape($t('view.catalog.remove_collection_label', ['title' => $collectionAnime['title']])) ?>"><?= $escape($t('view.catalog.remove_this_collection')) ?></button>
                                        </form>
                                    </div>
                                </article>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($pagination['totalPages'] > 1): ?>
                    <nav class="collection-pagination" aria-label="<?= $escape($t('view.catalog.collection_pages')) ?>">
                        <?php if ($pagination['page'] > 1): ?>
                            <a href="/profile/collections/<?= $escape($selectedCollection['id']) ?>?page=<?= $escape($pagination['page'] - 1) ?>" rel="prev"><?= $escape($t('view.catalog.previous')) ?></a>
                        <?php endif; ?>
                        <span><?= $escape($t('view.catalog.page_position', ['page' => $pagination['page'], 'pages' => $pagination['totalPages']])) ?></span>
                        <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                            <a href="/profile/collections/<?= $escape($selectedCollection['id']) ?>?page=<?= $escape($pagination['page'] + 1) ?>" rel="next"><?= $escape($t('view.catalog.next')) ?></a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
