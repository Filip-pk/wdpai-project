<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="dashboard-page">
    <header class="page-heading dashboard-heading">
        <div class="dashboard-welcome">
            <?php if ($user !== null): ?>
                <a class="dashboard-avatar" href="/users/<?= $escape($accountId) ?>" aria-label="<?= $escape($t('view.catalog.view_your_profile_label', ['user' => $user['name']])) ?>">
                    <?php if (!empty($currentUserAvatarUrl)): ?>
                        <img src="<?= $escape($currentUserAvatarUrl) ?>" alt="" width="48" height="48" decoding="async">
                    <?php else: ?>
                        <span aria-hidden="true">✦</span>
                    <?php endif; ?>
                </a>
            <?php else: ?>
                <span class="dashboard-avatar" aria-hidden="true">✦</span>
            <?php endif; ?>
            <div>
                <p class="dashboard-eyebrow"><?= $escape($t('view.catalog.your_world')) ?></p>
                <h1><?= $user === null ? $escape($t('view.catalog.welcome')) : $escape($t('view.catalog.greeting', ['user' => $user['name']])) ?> <span aria-hidden="true">👋</span></h1>
                <p><?= $escape($t('view.catalog.dashboard_intro')) ?></p>
            </div>
        </div>
        <?php if ($user !== null): ?>
            <a class="dashboard-profile-link" href="/profile"><?= $escape($t('view.catalog.account_settings')) ?> <span aria-hidden="true">↗</span></a>
        <?php else: ?>
            <p class="dashboard-login-info"><a href="/login"><?= $escape($t('view.catalog.login')) ?></a><?= $escape($t('view.catalog.login_profile_watchlist')) ?></p>
        <?php endif; ?>
        <p class="dashboard-demo-notice"><?= $escape($t('view.catalog.dashboard_demo')) ?></p>
    </header>

    <?php require __DIR__ . '/partials/daily-quiz.html.php'; ?>

    <section class="mood-panel" aria-labelledby="mood-title">
        <header class="dashboard-section-heading">
            <div class="dashboard-section-title">
                <span class="dashboard-section-icon" aria-hidden="true">✧</span>
                <h2 id="mood-title"><?= $escape($t('view.catalog.mood_title')) ?></h2>
            </div>
            <span class="dashboard-demo-badge"><?= $escape($t('view.catalog.ai_preview')) ?></span>
        </header>

        <p>
            <?= $escape($t('view.catalog.mood_intro')) ?>
        </p>

        <form action="/chat" method="post">
            <fieldset class="mood-fields" disabled>
                <legend><?= $escape($t('view.catalog.mood_recommendations')) ?></legend>

                <label for="mood-message">
                    <?= $escape($t('view.catalog.mood_preferences')) ?>
                </label>

                <textarea
                    id="mood-message"
                    name="message"
                    rows="4"
                    maxlength="2000"
                    placeholder="<?= $escape($t('view.catalog.mood_placeholder')) ?>"
                    required
                ></textarea>

                <ul class="dashboard-moods" aria-label="<?= $escape($t('view.catalog.mood_examples')) ?>">
                    <li><span aria-hidden="true">✦</span> <?= $escape($t('view.catalog.genre_cyberpunk')) ?></li>
                    <li><span aria-hidden="true">⚔</span> <?= $escape($t('view.catalog.dark_fantasy')) ?></li>
                    <li><span aria-hidden="true">🌸</span> <?= $escape($t('view.catalog.romantic')) ?></li>
                    <li><span aria-hidden="true">ϟ</span> <?= $escape($t('view.catalog.fast_action')) ?></li>
                </ul>

                <button type="submit"><?= $escape($t('view.catalog.ask_ai')) ?></button>
            </fieldset>
        </form>

        <a class="dashboard-demo-link" href="/chat"><?= $escape($t('view.catalog.sample_assistant')) ?> <span aria-hidden="true">→</span></a>
    </section>

    <section class="dashboard-personas" aria-labelledby="personas-title">
        <header class="dashboard-section-heading">
            <div class="dashboard-section-title">
                <span class="dashboard-section-icon" aria-hidden="true">♧</span>
                <h2 id="personas-title"><?= $escape($t('view.catalog.talk_character')) ?></h2>
            </div>
            <p><?= $escape($t('view.catalog.demo_conversations')) ?></p>
        </header>

        <ul class="persona-grid">
            <?php foreach ($personas as $personaId => $persona): ?>
                <li>
                    <article class="persona-card">
                        <h3>
                            <a href="/chat/<?= $escape($personaId) ?>">
                                <span class="dashboard-persona-mark" aria-hidden="true"><?= $escape([1 => '🍥', 2 => 'L', 3 => '⚔', 4 => 'ϟ'][$personaId] ?? '✦') ?></span>
                                <span><?= $escape($persona['name']) ?></span>
                            </a>
                        </h3>

                        <p><?= $escape($persona['anime']) ?></p>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="dashboard-recommendations" aria-labelledby="recommendations-title" data-recommendation-mode="<?= $escape($recommendationMode) ?>" data-recommendation-source-count="<?= $escape($recommendationSourceCount) ?>">
        <div class="dashboard-recommendations-heading">
            <div>
                <p class="dashboard-eyebrow"><?= $recommendationMode === 'personalized' ? $escape($t('view.catalog.from_watchlist')) : $escape($t('view.catalog.discover_new')) ?></p>
                <h2 id="recommendations-title"><?= $recommendationMode === 'personalized' ? $escape($t('view.catalog.for_you')) : $escape($t('view.catalog.starter_recommendations')) ?></h2>
            </div>
            <a href="/anime"><?= $escape($t('view.catalog.see_full_catalog')) ?> <span aria-hidden="true">→</span></a>
        </div>

        <?php if ($recommendationMode === 'personalized'): ?>
            <p><?= $escape($t('view.catalog.personalized_help')) ?></p>
            <div class="dashboard-preferences">
                <h3><?= $escape($t('view.catalog.favorite_genres')) ?></h3>
                <ul class="dashboard-preference-list">
                    <?php foreach ($preferredGenres as $genre): ?>
                        <li><a href="/anime?genre=<?= $escape($genre['id']) ?>" data-preferred-genre-id="<?= $escape($genre['id']) ?>"><?= $escape($translateText($genre['name'])) ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <p><?= $escape($t('view.catalog.recommendation_updates', ['count' => $recommendationSourceCount])) ?></p>
            </div>
        <?php elseif ($recommendationMode === 'guest'): ?>
            <p><?= $escape($t('view.catalog.guest_recommendations_before')) ?> <a href="/login"><?= $escape($t('view.catalog.login')) ?></a> <?= $escape($t('view.catalog.guest_recommendations_after')) ?></p>
        <?php else: ?>
            <p><?= $escape($t('view.catalog.starter_help')) ?></p>
        <?php endif; ?>

        <?php if ($recommendations !== []): ?>
            <ul class="anime-grid dashboard-recommendation-grid">
                <?php foreach ($recommendations as $animeId => $anime): ?>
                    <li data-recommended-anime-id="<?= $escape($animeId) ?>">
                        <?php
                        $reason = $recommendationReasons[$animeId] ?? null;
                        $seed = $reason['seed'] ?? null;
                        ?>
                        <?php if ($reason !== null && ($seed !== null || $reason['genres'] !== [])): ?>
                            <div class="dashboard-recommendation-reason"<?php if ($seed !== null): ?> data-recommendation-seed-id="<?= $escape($seed['animeId']) ?>"<?php endif; ?>>
                                <p class="dashboard-recommendation-source">
                                    <?php if ($seed !== null): ?>
                                        <?php if ($seed['rating'] !== null && $seed['rating'] >= 7): ?>
                                            <?= $escape($t('view.catalog.reason_high_rating')) ?>
                                        <?php elseif ($seed['status'] === 'completed'): ?>
                                            <?= $escape($t('view.catalog.reason_completed')) ?>
                                        <?php elseif ($seed['status'] === 'watching'): ?>
                                            <?= $escape($t('view.catalog.reason_watching')) ?>
                                        <?php else: ?>
                                            <?= $escape($t('view.catalog.reason_watchlist')) ?>
                                        <?php endif; ?>
                                        <a href="/anime/<?= $escape($seed['animeId']) ?>"><?= $escape($seed['title']) ?></a><?php if ($seed['rating'] !== null && $seed['rating'] >= 7): ?> <span>(<?= $escape($seed['rating']) ?>/10)</span><?php endif; ?>
                                    <?php else: ?>
                                        <?= $escape($t('view.catalog.reason_preferences')) ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($reason['genres'] !== []): ?>
                                    <p class="dashboard-recommendation-details"><?= $seed !== null && $seed['sharedStudio'] ? $escape($t('view.catalog.shared_studio_themes')) : ($seed !== null ? $escape($t('view.catalog.shared_themes')) : $escape($t('view.catalog.themes'))) ?> <?= $escape(implode(', ', array_map($translateText, $reason['genres']))) ?></p>
                                <?php elseif ($seed !== null && $seed['sharedStudio']): ?>
                                    <p class="dashboard-recommendation-details"><?= $escape($t('view.catalog.shared_studio')) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="dashboard-recommendations-empty">
                <h3><?= $escape($t('view.catalog.no_recommendations')) ?></h3>
                <p><?= $recommendationMode === 'personalized' ? $escape($t('view.catalog.no_personalized')) : $escape($t('view.catalog.no_starter')) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($user !== null): ?>
            <a class="dashboard-watchlist-link" href="/watchlist"><?= $escape($t('view.catalog.go_your_watchlist')) ?> <span aria-hidden="true">→</span></a>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
