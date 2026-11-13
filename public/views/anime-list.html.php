<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="catalog-page">
    <header class="catalog-heading">
        <div class="catalog-heading-copy">
            <p class="eyebrow"><?= $escape($t('view.catalog.find_next')) ?></p>
            <h1><?= $escape($t('view.catalog.discover')) ?></h1>
            <p><?= $escape($t('view.catalog.catalog_intro')) ?></p>
        </div>
        <div class="catalog-heading-meta">
            <p class="catalog-size"><strong data-catalog-count="<?= $escape($catalogTotal) ?>"><?= $escape($catalogTotal) ?></strong> <?= $escape($t('view.catalog.catalog_size')) ?></p>
            <a class="catalog-ranking-link" href="/anime/ranking"><?= $escape($t('view.catalog.community_rankings')) ?> <span aria-hidden="true">→</span></a>
        </div>
    </header>

    <?php if (!empty($searchError)): ?>
        <p class="catalog-search-error" id="catalog-search-error" role="alert"><?= $escape($translateText($searchError)) ?></p>
    <?php endif; ?>

    <form action="/anime" method="get" role="search" class="catalog-search" aria-label="<?= $escape($t('view.catalog.search_accessible')) ?>"<?= !empty($searchError) ? ' aria-describedby="catalog-search-error"' : '' ?>>
        <div class="catalog-search-primary">
            <div class="catalog-field catalog-query">
                <label for="anime-search"><?= $escape($t('view.catalog.search_title')) ?></label>
                <input id="anime-search" name="q" type="search" maxlength="100" value="<?= $escape($query) ?>" placeholder="<?= $escape($t('view.catalog.search_placeholder')) ?>" aria-describedby="anime-search-help">
                <p id="anime-search-help"><?= $escape($t('view.catalog.search_help')) ?></p>
            </div>
            <div class="catalog-field">
                <label for="anime-genre"><?= $escape($t('view.catalog.genre')) ?></label>
                <select id="anime-genre" name="genre">
                    <option value=""><?= $escape($t('view.catalog.all_genres')) ?></option>
                    <?php foreach ($genres as $genre): ?>
                        <option value="<?= $escape($genre['id']) ?>" <?= $genreId === $genre['id'] ? 'selected' : '' ?>><?= $escape($translateText($genre['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="catalog-field">
                <label for="anime-sort"><?= $escape($t('view.catalog.sort')) ?></label>
                <select id="anime-sort" name="sort">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= $escape($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= $escape($translateText($label)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <details class="catalog-advanced-filters"<?= implode('', $filters) !== '' || !empty($searchError) ? ' open' : '' ?>>
            <summary>
                <span><?= $escape($t('view.catalog.advanced')) ?></span>
                <span class="catalog-filters-summary"><?= $escape($t('view.catalog.advanced_hint')) ?></span>
            </summary>
            <div class="catalog-search-filters">
                <fieldset class="catalog-filter-group">
                    <legend><?= $escape($t('view.catalog.format')) ?></legend>
                    <div class="catalog-field">
                        <label for="anime-type"><?= $escape($t('view.catalog.anime_type')) ?></label>
                        <select id="anime-type" name="type">
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?= $escape($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= $escape($translateText($label)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </fieldset>

                <fieldset class="catalog-filter-group">
                    <legend><?= $escape($t('view.catalog.episode_count')) ?></legend>
                    <div class="catalog-range">
                        <div class="catalog-field">
                            <label for="anime-episodes-min"><?= $escape($t('view.catalog.from')) ?></label>
                            <input id="anime-episodes-min" name="episodes_min" type="number" min="1" max="2147483647" step="1" value="<?= $escape($filters['episodes_min']) ?>" placeholder="<?= $escape($t('view.catalog.example1')) ?>">
                        </div>
                        <div class="catalog-field">
                            <label for="anime-episodes-max"><?= $escape($t('view.catalog.to')) ?></label>
                            <input id="anime-episodes-max" name="episodes_max" type="number" min="1" max="2147483647" step="1" value="<?= $escape($filters['episodes_max']) ?>" placeholder="<?= $escape($t('view.catalog.example26')) ?>">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="catalog-filter-group">
                    <legend><?= $escape($t('view.catalog.source_score')) ?></legend>
                    <div class="catalog-range">
                        <div class="catalog-field">
                            <label for="anime-score-min"><?= $escape($t('view.catalog.from')) ?></label>
                            <input id="anime-score-min" name="score_min" type="number" min="0" max="10" step="0.01" value="<?= $escape($filters['score_min']) ?>" placeholder="<?= $escape($t('view.catalog.example7')) ?>">
                        </div>
                        <div class="catalog-field">
                            <label for="anime-score-max"><?= $escape($t('view.catalog.to')) ?></label>
                            <input id="anime-score-max" name="score_max" type="number" min="0" max="10" step="0.01" value="<?= $escape($filters['score_max']) ?>" placeholder="<?= $escape($t('view.catalog.example10')) ?>">
                        </div>
                    </div>
                    <p class="catalog-filter-hint"><?= $escape($t('view.catalog.score_scale')) ?></p>
                </fieldset>

                <fieldset class="catalog-filter-group">
                    <legend><?= $escape($t('view.catalog.release_year')) ?></legend>
                    <div class="catalog-range">
                        <div class="catalog-field">
                            <label for="anime-year-min"><?= $escape($t('view.catalog.from')) ?></label>
                            <input id="anime-year-min" name="year_min" type="number" min="1900" max="2200" step="1" value="<?= $escape($filters['year_min']) ?>" placeholder="<?= $escape($t('view.catalog.example2000')) ?>">
                        </div>
                        <div class="catalog-field">
                            <label for="anime-year-max"><?= $escape($t('view.catalog.to')) ?></label>
                            <input id="anime-year-max" name="year_max" type="number" min="1900" max="2200" step="1" value="<?= $escape($filters['year_max']) ?>" placeholder="<?= $escape($t('view.catalog.any')) ?>">
                        </div>
                    </div>
                </fieldset>
            </div>
            <p class="catalog-filter-help"><?= $escape($t('view.catalog.filter_help')) ?></p>
        </details>
        <div class="catalog-search-actions">
            <button type="submit"><?= $escape($t('view.catalog.search')) ?></button>
            <a href="/anime"><?= $escape($t('view.catalog.clear_filters')) ?></a>
        </div>
    </form>

    <section class="catalog-results" aria-labelledby="catalog-title" data-page="<?= $escape($page) ?>">
        <header class="catalog-results-heading">
            <div>
                <h2 id="catalog-title"><?= $escape($t('view.catalog.catalog')) ?></h2>
                <p><?= $escape($t('view.catalog.results_count')) ?> <strong data-result-count="<?= $escape($total) ?>"><?= $escape($total) ?></strong></p>
                <p class="catalog-results-order"><?= $escape($t('view.catalog.order')) ?> <?= $escape($translateText($sortOptions[$sort])) ?></p>
            </div>
            <?php if ($total > 0): ?>
                <p><?= $escape($t('view.catalog.page_position', ['page' => $page, 'pages' => $totalPages])) ?></p>
            <?php endif; ?>
        </header>

        <?php if ($animes === []): ?>
            <div class="catalog-empty">
                <span aria-hidden="true">✦</span>
                <h3><?= $escape($t('view.catalog.no_matches')) ?></h3>
                <p><?= $escape($t('view.catalog.no_matches_help')) ?></p>
                <a href="/anime"><?= $escape($t('view.catalog.show_catalog')) ?></a>
            </div>
        <?php else: ?>
            <ul class="anime-grid">
                <?php foreach ($animes as $animeId => $anime): ?>
                    <li><?php require __DIR__ . '/partials/anime-card.html.php'; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="catalog-pagination" aria-label="<?= $escape($t('view.catalog.catalog_pages')) ?>">
                <?php if ($previousUrl !== null): ?>
                    <a href="<?= $escape($previousUrl) ?>" rel="prev" class="catalog-page-direction"><?= $escape($t('view.catalog.previous')) ?></a>
                <?php endif; ?>
                <ol>
                    <?php foreach ($pagination as $item): ?>
                        <li>
                            <a href="<?= $escape($item['url']) ?>" aria-label="<?= $escape($t('view.catalog.page_label', ['page' => $item['page']])) ?>" <?= $item['current'] ? 'aria-current="page"' : '' ?>><?= $escape($item['page']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($nextUrl !== null): ?>
                    <a href="<?= $escape($nextUrl) ?>" rel="next" class="catalog-page-direction"><?= $escape($t('view.catalog.next')) ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <aside class="catalog-help" aria-labelledby="catalog-help-title">
        <h2 id="catalog-help-title"><?= $escape($t('view.catalog.help_choose')) ?></h2>
        <p><?= $escape($t('view.catalog.assistant_demo_help')) ?></p>
        <a href="/chat"><?= $escape($t('view.catalog.see_assistant')) ?></a>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
