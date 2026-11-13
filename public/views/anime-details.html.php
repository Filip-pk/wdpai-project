<?php require __DIR__ . '/partials/header.html.php'; ?>

<nav class="catalog-back" aria-label="<?= $escape($t('view.catalog.return_catalog')) ?>">
    <a href="/anime"><?= $escape($t('view.catalog.back_catalog')) ?></a>
</nav>

<article class="anime-details">
    <header class="anime-details-heading">
        <div class="anime-cover anime-detail-cover" aria-hidden="true">
            <span class="anime-cover-placeholder"><span>✦</span><span><?= $escape($t('view.catalog.no_cover')) ?></span></span>
            <?php if (!empty($anime['coverUrl'])): ?>
                <img data-anime-cover src="<?= $escape($anime['coverUrl']) ?>" alt="" width="300" height="450" decoding="async" referrerpolicy="no-referrer">
            <?php endif; ?>
        </div>
        <div class="anime-details-identity">
            <p class="eyebrow"><?= $escape(empty($anime['type']) || $anime['type'] === 'UNKNOWN' ? $t('view.catalog.discover_series') : $anime['type']) ?></p>
            <h1><?= $escape($anime['title']) ?></h1>
            <ul class="anime-genres" aria-label="<?= $escape($t('view.catalog.genres')) ?>">
                <?php foreach ($anime['genres'] as $genreName): ?>
                    <li><?= $escape($translateText($genreName)) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="anime-detail-intro"><?= $escape($t('view.catalog.details_intro')) ?></p>
            <a class="anime-detail-primary-link" href="#anime-actions-title"><?= $escape($t('view.catalog.watchlist_your_score')) ?> <span aria-hidden="true">↗</span></a>
        </div>
    </header>

    <nav class="anime-detail-shortcuts" aria-label="<?= $escape($t('view.catalog.anime_sections')) ?>">
        <a href="#synopsis-title"><?= $escape($t('view.catalog.synopsis')) ?></a>
        <a href="#anime-facts-title"><?= $escape($t('view.catalog.information')) ?></a>
        <a href="#anime-actions-title"><?= $escape($t('view.catalog.watchlist_score')) ?></a>
        <a href="#comments"><?= $escape($t('view.catalog.comments')) ?></a>
    </nav>

    <div class="anime-detail-layout">
        <div class="anime-detail-primary">
            <section class="anime-details-section" aria-labelledby="synopsis-title">
                <div class="anime-section-heading">
                    <h2 id="synopsis-title"><?= $escape($t('view.catalog.synopsis')) ?></h2>
                    <?php if (($anime['descriptionLanguage'] ?? '') === 'en' && $anime['description'] !== ''): ?>
                        <span class="anime-language"><?= $escape($t('view.catalog.description_english')) ?></span>
                    <?php endif; ?>
                </div>
                <p class="anime-synopsis"<?= ($anime['descriptionLanguage'] ?? '') === 'en' && $anime['description'] !== '' ? ' lang="en"' : '' ?>><?= $escape($anime['description'] === '' ? $t('view.catalog.no_description') : $anime['description']) ?></p>
            </section>

            <section class="anime-details-section" aria-labelledby="anime-facts-title">
                <h2 id="anime-facts-title"><?= $escape($t('view.catalog.series_information')) ?></h2>

                <dl class="anime-detail-facts">
                    <div><dt><?= $escape($t('view.catalog.studio')) ?></dt><dd><?= $escape($anime['studio'] === '' ? $t('view.catalog.unknown_plural') : $anime['studio']) ?></dd></div>
                    <div><dt><?= $escape($t('view.catalog.release_year')) ?></dt><dd><?= isset($anime['year']) ? $escape($anime['year']) : $escape($t('view.catalog.unknown_male')) ?></dd></div>
                    <div><dt><?= $escape($t('view.catalog.episode_count')) ?></dt><dd><?= isset($anime['episodes']) ? $escape($anime['episodes']) : $escape($t('view.catalog.unknown_female')) ?></dd></div>
                    <div><dt><?= $escape($t('view.catalog.source_score')) ?></dt><dd><?= isset($anime['score']) ? $escape(number_format($anime['score'], 2, ',', '')) . ' / 10' : $escape($t('view.catalog.no_score')) ?></dd></div>
                    <?php require __DIR__ . '/partials/community-anime-rating.html.php'; ?>
                </dl>
                <?php if (!empty($anime['sourceUrl']) && str_starts_with($anime['sourceUrl'], 'https://')): ?>
                    <p class="anime-source"><?= $escape($t('view.catalog.anime_data')) ?> <a href="<?= $escape($anime['sourceUrl']) ?>" rel="external noreferrer"><?= $escape($anime['sourceLabel']) ?></a>.</p>
                <?php endif; ?>
                <details class="anime-source">
                    <summary><?= $escape($t('view.catalog.catalog_sources')) ?></summary>
                    <p><a href="https://github.com/cedya77/anime-offline-database" rel="external noreferrer">Anime Offline Database — cedya77</a>,
                        <?= $escape($t('view.catalog.continuation')) ?> <a href="https://github.com/manami-project/anime-offline-database" rel="external noreferrer">manami-project</a>;
                        <?= $escape($t('view.catalog.descriptions_scores')) ?> <a href="https://huggingface.co/datasets/moviebrain01/anime-dataset-2025" rel="external noreferrer"><?= $escape($t('view.catalog.snapshot')) ?></a>
                        <?= $escape($t('view.catalog.and')) ?> <a href="https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime" rel="external noreferrer">TheRealVigilante / Quan Than</a>.</p>
                </details>
            </section>
        </div>

        <div class="anime-detail-secondary">
            <section class="anime-details-section anime-personal-panel" aria-labelledby="anime-actions-title">
                <h2 id="anime-actions-title"><?= $escape($t('view.catalog.your_watchlist_score')) ?></h2>

                <?php if ($isLoggedIn): ?>
                    <form action="/watchlist" method="post" class="anime-watchlist-add">
                        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="animeId" value="<?= $escape($animeId) ?>">
                        <input type="hidden" name="mode" value="add">
                        <button type="submit"><?= $escape($t('view.catalog.add_watchlist')) ?></button>
                    </form>
                <?php else: ?>
                    <p><a href="/login"><?= $escape($t('view.catalog.login')) ?></a><?= $escape($t('view.catalog.login_add_watchlist')) ?></p>
                <?php endif; ?>

                <section class="anime-rating-panel" aria-labelledby="anime-rating-title"<?php if ($isLoggedIn): ?> data-user-rating="<?= $escape($userRating ?? '') ?>"<?php endif; ?>>
                    <h3 id="anime-rating-title"><?= $escape($t('view.catalog.your_score')) ?></h3>
                    <?php if ($isLoggedIn): ?>
                        <p class="anime-rating-current"><?= $userRating !== null ? $escape($t('view.catalog.your_score_prefix')) . ' ' . $escape($userRating) . ' / 10' : $escape($t('view.catalog.awaiting_score')) ?></p>
                        <p class="anime-rating-help" id="anime-rating-help"><?= $escape($t('view.catalog.score_help')) ?></p>
                        <?php if ($ratingNotice !== null): ?>
                            <p class="anime-rating-notice" data-kind="success" role="status"><?= $escape($translateText($ratingNotice)) ?></p>
                        <?php endif; ?>
                        <?php if ($ratingErrors !== []): ?>
                            <div class="anime-rating-notice" data-kind="error" role="alert">
                                <ul><?php foreach ($ratingErrors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
                            </div>
                        <?php endif; ?>
                        <form action="/anime/<?= $escape($animeId) ?>/rating" method="post" class="anime-rating-form">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <div class="anime-rating-field">
                                <label for="anime-rating"><?= $escape($t('view.catalog.score_label')) ?></label>
                                <select id="anime-rating" name="rating" aria-describedby="anime-rating-help"<?= $ratingErrors !== [] ? ' aria-invalid="true"' : '' ?> required>
                                    <option value=""<?= $ratingValue === '' ? ' selected' : '' ?>><?= $escape($t('view.catalog.choose_score')) ?></option>
                                    <?php for ($value = 1; $value <= 10; $value++): ?>
                                        <option value="<?= $escape($value) ?>"<?= $ratingValue === (string) $value ? ' selected' : '' ?>><?= $escape($value) ?> / 10</option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <button type="submit"><?= $escape($t('view.catalog.save_score')) ?></button>
                        </form>
                        <?php if ($userRating !== null): ?>
                            <form action="/anime/<?= $escape($animeId) ?>/rating/remove" method="post" class="anime-rating-remove">
                                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                                <button type="submit"><?= $escape($t('view.catalog.remove_score')) ?></button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p><a href="/login"><?= $escape($t('view.catalog.login')) ?></a><?= $escape($t('view.catalog.login_score')) ?></p>
                    <?php endif; ?>
                </section>

                <a class="anime-detail-assistant-link" href="/chat"><?= $escape($t('view.catalog.go_assistant')) ?> <span aria-hidden="true">→</span></a>
            </section>

            <section class="anime-details-section anime-collection-actions" aria-labelledby="anime-collections-title">
                <h2 id="anime-collections-title"><?= $escape($t('view.catalog.private_collections')) ?></h2>
                <?php if ($isLoggedIn): ?>
                    <?php if ($collectionNotice !== null): ?>
                        <p class="anime-collection-notice" data-kind="success" role="status"><?= $escape($translateText($collectionNotice)) ?></p>
                    <?php endif; ?>
                    <?php if ($collectionErrors !== []): ?>
                        <div class="anime-collection-notice" data-kind="error" role="alert">
                            <ul><?php foreach ($collectionErrors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
                        </div>
                    <?php endif; ?>

                    <details class="anime-collection-picker"<?= $collectionErrors !== [] ? ' open' : '' ?>>
                        <summary><?= $escape($t('view.catalog.add_collection')) ?></summary>
                        <form action="/anime/<?= $escape($animeId) ?>/collections" method="post" class="collection-form">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <fieldset class="collection-choices">
                                <legend><?= $escape($t('view.catalog.choose_collections')) ?></legend>
                                <?php foreach ($collections as $collection): ?>
                                    <?php $alreadyAdded = in_array($collection['id'], $animeCollectionIds, true); ?>
                                    <label class="collection-choice" for="anime-collection-<?= $escape($collection['id']) ?>">
                                        <input id="anime-collection-<?= $escape($collection['id']) ?>" name="collectionIds[]" type="checkbox" value="<?= $escape($collection['id']) ?>"<?= $alreadyAdded || in_array($collection['id'], $selectedCollectionIds, true) ? ' checked' : '' ?><?= $alreadyAdded ? ' disabled' : '' ?>>
                                        <span class="collection-choice-copy">
                                            <span class="collection-choice-name"><?= $escape($collection['name']) ?></span>
                                            <?php if ($alreadyAdded): ?><span class="collection-choice-added"><?= $escape($t('view.catalog.added')) ?></span><?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <button type="submit"><?= $escape($t('view.catalog.add_selected_collections')) ?></button>
                        </form>
                        <p class="collection-help"><?= $escape($t('view.catalog.multiple_collections_help')) ?></p>
                    </details>

                    <details class="anime-collection-picker"<?= $collectionForm['name'] !== '' || $collectionForm['description'] !== '' ? ' open' : '' ?>>
                        <summary><?= $escape($t('view.catalog.create_new_collection')) ?></summary>
                        <form action="/anime/<?= $escape($animeId) ?>/collections/create" method="post" class="collection-form">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <div class="profile-field">
                                <label for="anime-collection-name"><?= $escape($t('view.catalog.collection_name')) ?></label>
                                <input id="anime-collection-name" name="name" type="text" maxlength="120" value="<?= $escape($collectionForm['name']) ?>" placeholder="<?= $escape($t('view.catalog.collection_example_friends')) ?>" required>
                            </div>
                            <div class="profile-field">
                                <label for="anime-collection-description"><?= $escape($t('view.catalog.short_description')) ?> <span><?= $escape($t('view.catalog.optional')) ?></span></label>
                                <textarea id="anime-collection-description" name="description" rows="3" maxlength="500"><?= $escape($collectionForm['description']) ?></textarea>
                            </div>
                            <button type="submit"><?= $escape($t('view.catalog.create_collection_add')) ?></button>
                        </form>
                    </details>
                    <p><a href="/profile/collections"><?= $escape($t('view.catalog.go_my_collections')) ?></a></p>
                <?php else: ?>
                    <p><a href="/login"><?= $escape($t('view.catalog.login')) ?></a><?= $escape($t('view.catalog.login_collections')) ?></p>
                <?php endif; ?>
            </section>


        </div>
    </div>

    <section class="anime-details-section anime-trailer-panel" aria-labelledby="trailer-title">
        <h2 id="trailer-title"><?= $escape($t('view.catalog.series_preview')) ?></h2>

        <p><?= $escape($t('view.catalog.trailer_soon')) ?></p>

        <button type="button" disabled>
            <?= $escape($t('view.catalog.watch_trailer')) ?>
        </button>
    </section>
</article>

<?php require __DIR__ . '/partials/comments.html.php'; ?>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
