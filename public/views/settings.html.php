<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="settings-page">
    <header class="settings-heading">
        <p class="eyebrow"><?= $escape($t('settings.space')) ?></p>
        <h1><?= $escape($t('navigation.settings')) ?></h1>
        <p><?= $escape($t('settings.description')) ?></p>
    </header>
    <nav class="settings-section-links" aria-label="<?= $escape($t('settings.sections')) ?>">
        <a href="#language"><?= $escape($t('language.label')) ?></a>
        <a href="#appearance"><?= $escape($t('settings.appearance')) ?></a>
        <a href="#accessibility"><?= $escape($t('settings.accessibility')) ?></a>
        <a href="#security"><?= $escape($t('settings.security')) ?></a>
        <a href="#privacy"><?= $escape($t('settings.privacy')) ?></a>
    </nav>
    <?php if (isset($_SESSION['settings_notice'])): ?>
        <p class="settings-notice" role="status"><?= $escape($translateText($_SESSION['settings_notice'])) ?></p>
        <?php unset($_SESSION['settings_notice']); ?>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="settings-notice settings-error" role="alert"><ul>
            <?php foreach ($errors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <div class="settings-layout">
        <div class="settings-sections">
            <section class="settings-card" id="language" aria-labelledby="language-title">
                <h2 id="language-title"><?= $escape($t('language.label')) ?></h2>
                <p class="settings-help"><?= $escape($t('language.help')) ?></p>
                <form method="post" action="/language">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="returnTo" value="/settings#language">
                    <div class="settings-field">
                        <label for="settings-language"><?= $escape($t('language.label')) ?></label>
                        <select id="settings-language" name="language">
                            <?php foreach ($languages as $code => $name): ?>
                                <option value="<?= $escape($code) ?>" lang="<?= $escape($code) ?>"<?= $locale === $code ? ' selected' : '' ?>><?= $escape($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="settings-actions"><button type="submit"><?= $escape($t('language.save')) ?></button></div>
                </form>
            </section>
            <section class="settings-card" id="appearance" aria-labelledby="appearance-title">
                <header><p class="eyebrow"><?= $escape($t('settings.your_way')) ?></p><h2 id="appearance-title"><?= $escape($t('settings.appearance')) ?></h2></header>
                <form method="post" action="/settings" data-appearance-form>
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="settings-fields">
                        <?php foreach (['theme' => $t('settings.theme'), 'font_size' => $t('settings.font'), 'line_height' => $t('settings.line_height'), 'interface_density' => $t('settings.density')] as $key => $label): ?>
                            <div class="settings-field">
                                <label for="settings-<?= $escape($key) ?>"><?= $escape($label) ?></label>
                                <select id="settings-<?= $escape($key) ?>" name="<?= $escape($key) ?>" data-preference="<?= $escape($key) ?>">
                                    <?php foreach ($appearanceChoices[$key] as $value => $choice): ?>
                                        <option value="<?= $escape($value) ?>"<?= ($form[$key] ?? '') === $value ? ' selected' : '' ?>><?= $escape($translateText($choice)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings-help"><?= $escape($t('settings.preview_help')) ?></p>
                    <div class="settings-actions"><button type="submit"><?= $escape($t('settings.save_appearance')) ?></button></div>
                </form>
                <form method="post" action="/settings/appearance/reset" class="settings-reset">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <button type="submit" class="settings-secondary"><?= $escape($t('settings.reset')) ?></button>
                </form>
            </section>
            <section class="settings-card" id="accessibility" aria-labelledby="accessibility-title">
                <header><p class="eyebrow"><?= $escape($t('settings.readable')) ?></p><h2 id="accessibility-title"><?= $escape($t('settings.accessibility')) ?></h2></header>
                <form method="post" action="/settings/accessibility" data-accessibility-form>
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="settings-fields">
                        <?php foreach (['high_contrast' => $t('settings.contrast'), 'reduced_motion' => $t('settings.animation'), 'strong_focus' => $t('settings.focus'), 'large_controls' => $t('settings.controls'), 'message_text_size' => $t('settings.messages'), 'comment_text_size' => $t('settings.comments')] as $key => $label): ?>
                            <div class="settings-field">
                                <label for="settings-<?= $escape($key) ?>"><?= $escape($label) ?></label>
                                <select id="settings-<?= $escape($key) ?>" name="<?= $escape($key) ?>" data-preference="<?= $escape($key) ?>">
                                    <?php foreach ($accessibilityChoices[$key] as $value => $choice): ?>
                                        <option value="<?= $escape($value) ?>"<?= ($form[$key] ?? '') === (string) $value ? ' selected' : '' ?>><?= $escape($translateText($choice)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings-help"><?= $escape($t('settings.accessibility_help')) ?></p>
                    <div class="settings-actions"><button type="submit"><?= $escape($t('settings.save_accessibility')) ?></button></div>
                </form>
            </section>
            <?php require __DIR__ . '/partials/settings-security.html.php'; ?>
            <?php require __DIR__ . '/partials/settings-privacy.html.php'; ?>
        </div>

        <aside class="settings-preview settings-card" aria-labelledby="preview-title">
            <p class="eyebrow"><?= $escape($t('settings.live')) ?></p><h2 id="preview-title"><?= $escape($t('settings.preview')) ?></h2>
            <p><?= $escape($t('settings.preview_text')) ?></p>
            <article class="settings-example-card">
                <span class="settings-example-art" aria-hidden="true">✦</span>
                <div><p class="eyebrow"><?= $escape($t('settings.example_card')) ?></p><h3><?= $escape($t('settings.example_title')) ?></h3><p><?= $escape($t('settings.example_meta')) ?></p></div>
                <button type="button" data-preview-button><?= $escape($t('settings.preview_button')) ?></button>
            </article>
            <p class="settings-help"><?= $escape($t('settings.example_help')) ?></p>
            <div class="settings-example-card"><p class="message-body"><?= $escape($t('settings.example_message')) ?></p><p class="comment-body"><?= $escape($t('settings.example_comment')) ?></p></div>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
