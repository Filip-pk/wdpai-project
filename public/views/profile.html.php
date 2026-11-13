<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page<?= $watchlistOnly ? ' watchlist-page' : ' profile-account profile-overview' ?>">
    <header class="profile-heading<?= $watchlistOnly ? ' watchlist-heading' : '' ?>">
        <p class="eyebrow"><?= $escape($t('view.catalog.your_account')) ?></p>
        <h1><?= $watchlistOnly ? $escape($t('view.catalog.favorite_anime')) : $escape($t('view.catalog.your_profile')) ?></h1>
        <p><?= $watchlistOnly ? $escape($t('view.catalog.watchlist_intro')) : $escape($t('view.catalog.profile_intro')) ?></p>
        <?php if ($watchlistOnly): ?>
            <dl class="watchlist-overview-stats">
                <div><dt><?= $escape($t('view.catalog.on_your_list')) ?></dt><dd data-watchlist-count="<?= $escape($watchlistTotal) ?>"><?= $escape($watchlistTotal) ?></dd></div>
                <div><dt><?= $escape($t('view.catalog.completed_series')) ?></dt><dd data-completed-count="<?= $escape($completedCount) ?>"><?= $escape($completedCount) ?></dd></div>
            </dl>
        <?php endif; ?>
    </header>

    <?php require __DIR__ . '/partials/profile-tabs.html.php'; ?>

    <?php if ($saved): ?>
        <p class="profile-notice" data-kind="success" role="status"><?= $escape($t('view.catalog.profile_saved')) ?></p>
    <?php endif; ?>
    <?php if ($avatarSaved ?? false): ?>
        <p class="profile-notice" data-kind="success" role="status"><?= $escape($t('view.catalog.avatar_saved')) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="profile-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($watchlistOnly): ?>
        <?php require __DIR__ . '/partials/experience.html.php'; ?>
    <?php endif; ?>

    <?php if (!$watchlistOnly): ?>
        <div class="profile-grid">
            <div class="profile-sidebar">
                <section class="profile-summary" aria-labelledby="profile-title">
                    <div class="profile-summary-top">
                        <a class="profile-avatar" href="/users/<?= $escape($user['id']) ?>" aria-label="<?= $escape($t('view.catalog.view_profile_label', ['user' => $user['name']])) ?>">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?= $escape($user['avatar_url']) ?>" alt="" width="80" height="80" decoding="async">
                            <?php else: ?>
                                ✦
                            <?php endif; ?>
                        </a>
                        <a class="profile-photo-link" href="#profile-avatar-title"><?= $escape($t('view.catalog.change_photo')) ?></a>
                    </div>
                    <div class="profile-identity">
                        <p class="profile-card-label"><?= $escape($t('view.catalog.my_space')) ?></p>
                        <h2 id="profile-title"><a href="/users/<?= $escape($user['id']) ?>"><?= $escape($user['name']) ?></a></h2>
                        <p class="profile-email"><?= $escape($user['email']) ?></p>
                    </div>
                    <p class="profile-interests"><?= $escape($user['interests'] === '' ? $t('view.catalog.interests_help') : $user['interests']) ?></p>
                    <dl class="profile-stats">
                        <div><dt><?= $escape($t('view.catalog.titles_your_list')) ?></dt><dd data-watchlist-count="<?= $escape($watchlistTotal) ?>"><?= $escape($watchlistTotal) ?></dd></div>
                        <div><dt><?= $escape($t('view.catalog.completed_series')) ?></dt><dd data-completed-count="<?= $escape($completedCount) ?>"><?= $escape($completedCount) ?></dd></div>
                    </dl>
                    <a class="profile-public-link" href="/users/<?= $escape($user['id']) ?>"><?= $escape($t('view.catalog.public_profile_link')) ?> <span aria-hidden="true">↗</span></a>
                </section>

                <?php require __DIR__ . '/partials/experience.html.php'; ?>
            </div>

            <section class="profile-editor" aria-labelledby="profile-edit-title">
                <header class="profile-editor-heading">
                    <span class="profile-editor-symbol" aria-hidden="true">⚙</span>
                    <div>
                        <h2 id="profile-edit-title"><?= $escape($t('view.catalog.data_settings')) ?></h2>
                        <p><?= $escape($t('view.catalog.profile_your_terms')) ?></p>
                    </div>
                </header>
                <form action="/profile" method="post" class="profile-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="profile-field">
                        <label for="profile-name"><?= $escape($t('view.catalog.username')) ?></label>
                        <input id="profile-name" name="name" type="text" value="<?= $escape($form['name']) ?>" maxlength="120" autocomplete="nickname" required>
                    </div>
                    <div class="profile-field">
                        <label for="profile-email"><?= $escape($t('view.catalog.email')) ?></label>
                        <input id="profile-email" name="email" type="email" value="<?= $escape($user['email']) ?>" maxlength="255" autocomplete="email" readonly>
                        <p><a href="/settings#security"><?= $escape($t('view.catalog.change_email_settings')) ?></a> <?= $escape($t('view.catalog.email_confirmation_help')) ?></p>
                    </div>
                    <div class="profile-field profile-field-wide">
                        <label for="profile-interests"><?= $escape($t('view.catalog.interests')) ?></label>
                        <textarea id="profile-interests" name="interests" rows="4" maxlength="1000" placeholder="<?= $escape($t('view.catalog.interests_placeholder')) ?>"><?= $escape($form['interests']) ?></textarea>
                    </div>
                    <div class="profile-field profile-field-wide">
                        <label for="profile-theme"><?= $escape($t('view.catalog.app_theme')) ?></label>
                        <select id="profile-theme" name="theme">
                            <?php foreach (['system' => $t('view.catalog.theme_system'), 'light' => $t('view.catalog.theme_light'), 'dark' => $t('view.catalog.theme_dark')] as $value => $label): ?>
                                <option value="<?= $escape($value) ?>" <?= $form['theme'] === $value ? 'selected' : '' ?>><?= $escape($translateText($label)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p><?= $escape($t('view.catalog.choice_saved_account')) ?></p>
                    </div>
                    <div class="profile-actions"><button type="submit"><?= $escape($t('view.catalog.save_profile')) ?></button></div>
                </form>
                <form action="/profile/avatar" method="post" enctype="multipart/form-data" class="profile-avatar-upload" aria-labelledby="profile-avatar-title">
                    <h3 id="profile-avatar-title"><?= $escape($t('view.catalog.profile_photo')) ?></h3>
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <label for="profile-avatar"><?= $escape($t('view.catalog.choose_photo')) ?></label>
                    <input id="profile-avatar" name="avatar" type="file" accept="image/png,image/jpeg" aria-describedby="avatar-info" required>
                    <p id="avatar-info"><?= $escape($t('view.catalog.photo_help')) ?></p>
                    <div class="profile-actions"><button type="submit"><?= $escape($t('view.catalog.save_photo')) ?></button></div>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <div class="profile-watchlist"><?php require __DIR__ . '/partials/watchlist.html.php'; ?></div>

    <aside class="profile-empty" aria-labelledby="import-title">
        <h2 id="import-title"><?= $escape($t('view.catalog.import_mal_title')) ?></h2>
        <p><?= $escape($t('view.catalog.import_profile_help')) ?></p>
        <a href="/profile/import"><?= $escape($t('view.catalog.import_mal_link')) ?> <span aria-hidden="true">→</span></a>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
