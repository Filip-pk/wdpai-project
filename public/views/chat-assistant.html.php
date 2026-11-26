<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-assistant" data-catalog-chat
    data-waiting="<?= $escape($t('chat.waiting')) ?>"
    data-stopping="<?= $escape($t('chat.stopping')) ?>"
    data-network-error="<?= $escape($t('chat.network_error')) ?>">
    <header class="ai-chat-hero">
        <h1 class="social-title"><?= $escape($t('chat.title')) ?></h1>
        <p class="social-description"><?= $escape($t('chat.intro')) ?></p>
    </header>

    <?php if (!$isLoggedIn): ?>
        <section class="ai-panel ai-chat-guest">
            <h2><?= $escape($t('chat.login')) ?></h2>
            <a class="ai-chat-button ai-chat-primary" href="/login"><?= $escape($t('view.catalog.login')) ?></a>
        </section>
    <?php else: ?>
        <?php
        $criteria = $chatState['criteria'] ?? [];
        $turns = $chatState['turns'] ?? [];
        $cards = $chatState['cards'] ?? [];
        ?>

        <div class="ai-chat-workspace">
            <section class="ai-panel ai-chat-panel" aria-labelledby="assistant-conversation-title">
                <header class="ai-chat-section-head">
                    <h2 id="assistant-conversation-title"><?= $escape($t('view.interface.your_conversation')) ?></h2>
                    <?php if ($turns !== []): ?>
                        <form action="/chat" method="post" data-chat-form>
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="intent" value="reset">
                            <button type="submit" class="ai-chat-button"><?= $escape($t('chat.reset')) ?></button>
                        </form>
                    <?php endif; ?>
                </header>

                <?php if ($turns === []): ?>
                    <div class="ai-chat-start">
                        <noscript><p class="ai-chat-hint"><?= $escape($t('chat.hint')) ?></p></noscript>
                        <div class="ai-chat-examples" data-chat-examples hidden>
                            <h3><?= $escape($t('view.interface.quick_questions')) ?></h3>
                            <?php foreach (['chat.example_similar', 'chat.example_finished'] as $exampleKey): ?>
                                <?php $example = $t($exampleKey); ?>
                                <button type="button" class="ai-chat-example" data-chat-example="<?= $escape($example) ?>">
                                    <?= $escape($example) ?> <span aria-hidden="true">↗</span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <ol class="chat-messages" data-chat-messages tabindex="0" aria-labelledby="assistant-conversation-title">
                        <?php foreach ($turns as $index => $turn): ?>
                            <li>
                                <article class="message message-user">
                                    <h3><?= $escape($t('view.interface.you_ce679e')) ?></h3>
                                    <p><?= $escape($turn['message']) ?></p>
                                </article>
                            </li>
                            <li>
                                <article class="message message-assistant" tabindex="-1" data-chat-reply>
                                    <h3><?= $escape($t('view.interface.ai_assistant')) ?></h3>
                                    <?php
                                    $parameters = $turn['parameters'];
                                    if ($turn['key'] === 'no_matches_filter') {
                                        $parameters['filter'] = $t($parameters['filter']);
                                    }
                                    ?>
                                    <p><?= $escape($t('chat.reply.' . $turn['key'], $parameters)) ?></p>
                                    <?php if ($cards !== [] && $index === array_key_last($turns)): ?>
                                        <a class="ai-chat-results-link" href="#assistant-results-title"><?= $escape($t('chat.view_results')) ?> <span aria-hidden="true">↓</span></a>
                                    <?php endif; ?>
                                </article>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <form action="/chat" method="post" class="chat-form" data-chat-form>
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="intent" value="send">
                    <input type="hidden" name="requestId" value="<?= $escape($chatRequestId) ?>">
                    <label for="assistant-message"><?= $escape($t('view.interface.describe_the_anime_you_re_looking_for')) ?></label>
                    <textarea id="assistant-message" name="message" rows="2" maxlength="1200" required><?= $escape($chatDraft) ?></textarea>
                    <div class="ai-chat-actions">
                        <p class="ai-chat-feedback" role="status" data-chat-progress></p>
                        <div class="ai-chat-buttons">
                            <button type="button" class="ai-chat-button" data-chat-cancel hidden><?= $escape($t('chat.cancel')) ?></button>
                            <button type="submit" class="ai-chat-button ai-chat-primary"><?= $escape($t('view.interface.send')) ?> <span aria-hidden="true">↗</span></button>
                        </div>
                    </div>
                </form>
                <?php if ($chatError !== null): ?>
                    <p class="ai-chat-hint" role="alert" tabindex="-1" data-chat-error><?= $escape($t('chat.error.' . $chatError)) ?></p>
                <?php endif; ?>
            </section>

            <div class="ai-chat-sidebar">
                <?php require __DIR__ . '/partials/chat-criteria.html.php'; ?>
                <?php require __DIR__ . '/partials/chat-results.html.php'; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
