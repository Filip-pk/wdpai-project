<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page showcase-editor-page" data-showcase-editor>
    <header class="profile-heading">
        <a class="user-profile-back" href="/profile"><span aria-hidden="true">←</span> <?= $escape($t('showcase.back')) ?></a>
        <p class="eyebrow"><?= $escape($t('showcase.eyebrow')) ?></p>
        <h1><?= $escape($t('showcase.editor_title')) ?></h1>
        <p><?= $escape($t('showcase.editor_intro')) ?></p>
    </header>

    <?php require __DIR__ . '/partials/profile-tabs.html.php'; ?>

    <?php if ($errors !== []): ?>
        <div class="profile-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($t($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($showcaseAnimes !== [] || $showcaseBadges !== []): ?>
        <div class="showcase-saved-preview">
            <p class="showcase-editor-help"><?= $escape($t('showcase.saved_preview')) ?></p>
            <?php $canEditShowcase = false; ?>
            <?php require __DIR__ . '/partials/profile-showcase.html.php'; ?>
        </div>
    <?php endif; ?>

    <section class="showcase-editor-panel" aria-labelledby="showcase-anime-title">
        <header class="showcase-step-heading">
            <span aria-hidden="true">01</span>
            <div>
                <h2 id="showcase-anime-title"><?= $escape($t('showcase.favorites')) ?></h2>
                <p id="showcase-slots-help"><?= $escape($t('showcase.slots_help')) ?></p>
            </div>
        </header>

        <form action="/profile/showcase" method="get" class="showcase-search" data-showcase-search
            data-loading="<?= $escape($t('showcase.search_loading')) ?>"
            data-results="<?= $escape($t('showcase.search_results')) ?>"
            data-empty="<?= $escape($t('showcase.search_empty')) ?>"
            data-error="<?= $escape($t('showcase.search_error')) ?>">
            <label for="showcase-search"><?= $escape($t('showcase.search_label')) ?></label>
            <div class="showcase-search-controls">
                <input id="showcase-search" name="q" type="search" value="<?= $escape($showcaseQuery) ?>" maxlength="160" placeholder="<?= $escape($t('showcase.search_placeholder')) ?>" aria-describedby="showcase-search-help">
                <button type="submit"><?= $escape($t('showcase.search')) ?></button>
            </div>
            <p id="showcase-search-help"><?= $escape($t('showcase.search_help')) ?></p>
            <noscript><p><?= $escape($t('showcase.search_fallback')) ?></p></noscript>
            <noscript><p><?= $escape($t('showcase.search_fallback')) ?></p></noscript>
            <p class="showcase-search-status" role="status" data-showcase-search-status></p>
        </form>

        <form id="showcase-form" action="/profile/showcase" method="post" class="showcase-form" data-showcase-form>
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="q" value="<?= $escape($showcaseQuery) ?>" data-showcase-query>
            <div class="showcase-slot-grid">
                <?php for ($showcaseSlot = 0; $showcaseSlot < 3; $showcaseSlot++): ?>
                    <?php $selectedAnimeId = $selection['animeIds'][$showcaseSlot] ?? null; ?>
                    <div class="showcase-slot">
                        <span class="showcase-slot-number" aria-hidden="true">0<?= $escape($showcaseSlot + 1) ?></span>
                        <label for="showcase-slot-<?= $escape($showcaseSlot + 1) ?>"><?= $escape($t('showcase.slot', ['number' => $showcaseSlot + 1])) ?></label>
                        <select id="showcase-slot-<?= $escape($showcaseSlot + 1) ?>" name="animeIds[]" aria-describedby="showcase-slots-help">
                            <option value=""><?= $escape($t('showcase.no_anime')) ?></option>
                            <?php foreach ($animeChoices as $choiceId => $choice): ?>
                                <option value="<?= $escape($choiceId) ?>"<?= (string) $selectedAnimeId === (string) $choiceId ? ' selected' : '' ?>><?= $escape($choice['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endfor; ?>
            </div>
        </form>
    </section>

    <section class="showcase-editor-panel" aria-labelledby="showcase-badges-title">
        <header class="showcase-step-heading">
            <span aria-hidden="true">02</span>
            <div>
                <h2 id="showcase-badges-title"><?= $escape($t('showcase.badges')) ?></h2>
                <p id="showcase-badges-help"><?= $escape($t('showcase.badges_help')) ?></p>
            </div>
            <span class="showcase-selection-count" data-showcase-badge-count aria-live="polite"><?= $escape(count($selection['badgeCodes'])) ?> / 3</span>
        </header>
        <?php if ($earnedBadges === []): ?>
            <p class="showcase-editor-help"><?= $escape($t('showcase.no_badges')) ?> <a href="/achievements"><?= $escape($t('showcase.earn_badges')) ?> <span aria-hidden="true">→</span></a></p>
        <?php else: ?>
            <div class="showcase-badge-options">
                <?php foreach ($earnedBadges as $achievement): ?>
                    <label class="showcase-badge-option">
                        <input type="checkbox" form="showcase-form" name="badgeCodes[]" value="<?= $escape($achievement['code']) ?>"<?= in_array($achievement['code'], $selection['badgeCodes'], true) ? ' checked' : '' ?> aria-describedby="showcase-badges-help">
                        <span class="showcase-badge-symbol" aria-hidden="true"><?php require __DIR__ . '/partials/achievement-icon.html.php'; ?></span>
                        <span class="showcase-badge-option-text">
                            <strong><?= $escape($t($achievement['titleKey'])) ?></strong>
                            <span><?= $escape($t($achievement['descriptionKey'])) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="showcase-editor-help"><?= $escape($t('showcase.privacy_help')) ?></p>
    </section>

    <div class="showcase-editor-actions">
        <p><?= $escape($t('showcase.save_help')) ?></p>
        <button type="submit" form="showcase-form"><?= $escape($t('showcase.save')) ?></button>
        <a href="/profile"><?= $escape($t('showcase.cancel')) ?></a>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
