<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-assistant">
    <header class="chat-heading social-hero">
        <div>
            <p class="social-kicker"><?= $escape($t('view.interface.discover_with_ai')) ?></p>
            <h1 class="social-title"><?= $escape($t('view.interface.ai_assistant_ollama')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.help_finding_anime_to_match_your_mood')) ?></p>
        </div>
        <span class="social-pill"><?= $escape($t('view.interface.conversation_preview')) ?></span>
    </header>

    <p id="assistant-demo-notice" class="ai-demo-notice"><span aria-hidden="true">◇</span> <?= $escape($t('view.interface.demo_version_the_conversation_and_suggestions_below_are_examples_sending_messages_is_not_a')) ?></p>

    <div class="ai-layout">
        <section class="ai-panel ai-chat-panel" aria-labelledby="assistant-conversation-title">
            <header class="ai-chat-title">
                <h2 id="assistant-conversation-title"><?= $escape($t('view.interface.sample_conversation')) ?></h2>
                <span class="social-pill" aria-label="<?= $escape($t('view.interface.sample_quota_usage')) ?>"><?= $escape($t('view.interface.sample_quota_usage_d830d3')) ?> <?= $escape($user['aiUsed']) ?> / <?= $escape($user['aiLimit']) ?> <?= $escape($t('view.interface.requests')) ?></span>
            </header>
            <ol class="chat-messages">
                <li>
                    <article class="message message-user">
                        <h3><?= $escape($t('view.interface.you_ce679e')) ?></h3>
                        <p lang="pl">Szukam anime w klimacie cyberpunka z ciekawą muzyką. Co polecasz?</p>
                        <time datetime="14:22">14:22</time>
                    </article>
                </li>
                <li>
                    <article class="message message-assistant">
                        <h3><?= $escape($t('view.interface.ai_assistant')) ?></h3>
                        <p lang="pl">Sprawdź Cyberpunk: Edgerunners — historię Davida i Lucy w futurystycznym Night City.</p>
                        <time datetime="14:23">14:23</time>
                    </article>
                </li>
            </ol>
            <form action="/chat" method="post" class="chat-form">
                <fieldset disabled aria-describedby="assistant-demo-notice">
                    <legend><?= $escape($t('view.interface.message_to_the_assistant')) ?></legend>
                    <label for="assistant-message"><?= $escape($t('view.interface.describe_the_anime_you_re_looking_for')) ?></label>
                    <textarea id="assistant-message" name="message" rows="3" maxlength="2000" required></textarea>
                    <button type="submit"><?= $escape($t('view.interface.send')) ?> <span aria-hidden="true">↗</span></button>
                </fieldset>
            </form>
        </section>

        <aside class="ai-rail" aria-label="<?= $escape($t('view.interface.ideas_for_your_next_anime')) ?>">
            <section class="ai-panel ai-recommendation" aria-labelledby="suggested-anime-title">
                <h2 id="suggested-anime-title"><?= $escape($t('view.interface.suggested_title')) ?></h2>
                <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
            </section>
            <section class="ai-panel" aria-labelledby="quick-questions-title">
                <h2 id="quick-questions-title"><?= $escape($t('view.interface.quick_questions')) ?></h2>
                <div class="ai-quick-actions">
                    <button type="button" disabled><?= $escape($t('view.interface.recommend_something_similar')) ?></button>
                    <button type="button" disabled><?= $escape($t('view.interface.something_with_magic')) ?></button>
                    <button type="button" disabled><?= $escape($t('view.interface.best_soundtracks')) ?></button>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
