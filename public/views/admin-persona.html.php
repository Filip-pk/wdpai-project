<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-creator">
    <header class="page-heading social-hero">
        <div>
            <p class="social-kicker"><?= $escape($t('view.interface.your_character_your_story')) ?></p>
            <h1 class="social-title"><?= $escape($t('view.interface.ai_character_creator')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.define_a_character_and_instructions_for_the_ollama_model')) ?></p>
        </div>
        <span class="social-pill"><?= $escape($t('view.interface.creator_preview')) ?></span>
    </header>

    <p id="creator-demo-notice" class="ai-demo-notice"><span aria-hidden="true">◇</span> <?= $escape($t('view.interface.admin_form_preview_it_does_not_grant_admin_permissions_or_save_data')) ?></p>

    <div class="ai-layout">
        <form action="/admin/personas" method="post" enctype="multipart/form-data" class="ai-creator-form">
            <fieldset disabled class="ai-creator-fields" aria-describedby="creator-demo-notice">
                <legend><?= $escape($t('view.interface.character_identity')) ?></legend>
                <div class="ai-creator-field">
                    <label for="persona-avatar"><?= $escape($t('view.interface.character_avatar')) ?></label>
                    <input id="persona-avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="persona-avatar-info">
                    <p id="persona-avatar-info"><?= $escape($t('view.interface.planned_png_jpeg_or_webp_up_to_4_mb')) ?></p>
                </div>
                <div class="ai-creator-field">
                    <label for="persona-name"><?= $escape($t('view.interface.character_name')) ?></label>
                    <input id="persona-name" name="name" type="text" maxlength="100" placeholder="Naruto Uzumaki" required>
                </div>
                <div class="ai-creator-field">
                    <label for="persona-anime"><?= $escape($t('view.interface.source_anime')) ?></label>
                    <select id="persona-anime" name="anime_id" required>
                        <option value=""><?= $escape($t('view.interface.choose_an_anime')) ?></option>
                        <?php foreach ($animes as $animeId => $anime): ?>
                            <option value="<?= $escape($animeId) ?>"><?= $escape($anime['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>
            <fieldset disabled class="ai-creator-fields" aria-describedby="creator-demo-notice">
                <legend><?= $escape($t('view.interface.instructions_for_ollama')) ?></legend>
                <div class="ai-creator-field">
                    <label for="system-prompt"><?= $escape($t('view.interface.system_prompt')) ?></label>
                    <textarea id="system-prompt" name="system_prompt" lang="pl" rows="10" maxlength="4000" required>Jesteś Naruto Uzumaki. Mów po polsku, z entuzjazmem. Wspominaj o treningach, przyjaźni i ramenie. Rozmowa jest fikcyjnym odgrywaniem postaci.</textarea>
                </div>
                <div class="ai-creator-field">
                    <label for="temperature"><?= $escape($t('view.interface.temperature_response_creativity')) ?></label>
                    <input id="temperature" name="temperature" type="number" min="0" max="1.5" step="0.05" value="0.7">
                </div>
                <div class="ai-creator-field">
                    <label for="context-size"><?= $escape($t('view.interface.context_size')) ?></label>
                    <select id="context-size" name="context_size">
                        <option value="1024"><?= $escape($t('view.interface.1024_tokens')) ?></option>
                        <option value="4096" selected><?= $escape($t('view.interface.4096_tokens')) ?></option>
                        <option value="8192"><?= $escape($t('view.interface.8192_tokens')) ?></option>
                    </select>
                </div>
                <div class="ai-creator-actions">
                    <button type="button"><?= $escape($t('view.interface.test_persona')) ?></button>
                    <button type="submit" class="ai-creator-submit"><?= $escape($t('view.interface.save_character')) ?></button>
                </div>
            </fieldset>
        </form>

        <section class="ai-panel ai-personas-panel" aria-labelledby="existing-personas-title">
            <h2 id="existing-personas-title"><?= $escape($t('view.interface.sample_personas')) ?></h2>
            <ul class="ai-persona-list">
                <?php foreach ($personas as $persona): ?>
                    <li>
                        <span class="ai-persona-avatar" aria-hidden="true">✦</span>
                        <div>
                            <strong><?= $escape($persona['name']) ?></strong>
                            <p><?= $escape($persona['anime']) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
    <a href="/dashboard" class="ai-back"><?= $escape($t('view.interface.back_to_dashboard_210bad')) ?></a>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
