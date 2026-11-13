<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="welcome" aria-labelledby="welcome-title">
    <header class="welcome-heading">
        <p class="eyebrow"><span aria-hidden="true">✦</span> Neo-Tokyo Stream Hub</p>
        <h1 id="welcome-title" class="welcome-title"><span class="gradient-text"><?= $escape($appName) ?></span></h1>
        <h2 class="welcome-tagline"><?= $escape($translateText($tagline)) ?></h2>
        <p class="welcome-description">
            <?= $escape($t('view.interface.immerse_yourself_in_the_world_of_anime_powered_by_ai_discover_titles_to_match_your_mood_ch')) ?>
        </p>
    </header>

    <figure class="welcome-visual">
        <img class="welcome-image" src="<?= $assetUrl('/img/neo-tokyo-guide.jpg') ?>" alt="" fetchpriority="high" decoding="async" width="512" height="512">
        <div class="welcome-visual-top" aria-hidden="true"><span>✦ NEO-TOKYO</span><span>ANIME × AI</span></div>
        <figcaption class="welcome-visual-caption">
            <span class="visual-label"><span class="welcome-status-dot" aria-hidden="true"></span> Neo-Tokyo AI</span>
            <span><?= $escape($t('view.interface.your_guide_to_the_world_of_anime')) ?></span>
        </figcaption>
    </figure>

    <section class="welcome-start" aria-labelledby="start-title">
        <h2 id="start-title" class="section-title"><?= $escape($t('view.interface.start_your_adventure')) ?></h2>
        <nav class="welcome-actions" aria-label="<?= $escape($t('view.interface.start_your_adventure')) ?>">
            <a href="/login" class="button button-primary"><span aria-hidden="true">↗</span> <?= $escape($t('view.interface.sign_in')) ?></a>
            <a href="/register" class="button button-secondary" aria-describedby="registration-info"><span aria-hidden="true">＋</span> <?= $escape($t('view.interface.sign_up')) ?></a>
            <a href="/anime" class="welcome-explore"><?= $escape($t('view.interface.explore_without_signing_in')) ?> <span aria-hidden="true">→</span></a>
        </nav>
        <p id="registration-info" class="helper-text"><?= $escape($t('view.interface.create_an_account_to_access_projects')) ?></p>
    </section>

    <section class="welcome-features" aria-labelledby="features-title">
        <h2 id="features-title" class="section-title"><?= $escape($t('view.interface.your_mood_your_anime')) ?></h2>
        <ul class="feature-list">
            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">✦</span>
                <div><h3><?= $escape($t('view.interface.smart_recommendations')) ?></h3><p><?= $escape($t('view.interface.describe_your_mood_and_discover_anime_for_you_with_the_local_ollama_model')) ?></p></div>
            </li>
            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">💬</span>
                <div><h3><?= $escape($t('view.interface.character_chats')) ?></h3><p><?= $escape($t('view.interface.chat_with_ai_playing_characters_from_your_favourite_anime_from_naruto_to_l_lawliet')) ?></p></div>
            </li>
            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">♡</span>
                <div><h3><?= $escape($t('view.interface.your_collection')) ?></h3><p><?= $escape($t('view.interface.save_titles_to_your_watchlist_track_your_progress_and_rate_the_series_you_watch')) ?></p></div>
            </li>
        </ul>
    </section>

    <footer class="welcome-footer"><p class="technology-badge"><span aria-hidden="true">✦</span> <?= $escape($t('view.interface.project_with_ollama_ai_integration')) ?></p></footer>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
