<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-assistant" data-catalog-chat
    data-waiting="<?= $escape($t('chat.waiting')) ?>"
    data-stopping="<?= $escape($t('chat.stopping')) ?>"
    data-network-error="<?= $escape($t('chat.network_error')) ?>">
    <header class="chat-heading social-hero">
        <div>
            <h1 class="social-title"><?= $escape($t('chat.title')) ?></h1>
            <p class="social-description"><?= $escape($t('chat.intro')) ?></p>
        </div>
    </header>

    <?php if (!$isLoggedIn): ?>
        <section class="ai-panel">
            <h2><?= $escape($t('chat.login')) ?></h2>
            <a class="ai-back" href="/login"><?= $escape($t('view.catalog.login')) ?> <span aria-hidden="true">→</span></a>
        </section>
    <?php else: ?>
        <?php
        $criteria = $chatState['criteria'] ?? [];
        $turns = $chatState['turns'] ?? [];
        $cards = $chatState['cards'] ?? [];
        ?>

        <div class="ai-layout ai-chat-workspace">
            <section class="ai-panel ai-chat-panel" aria-labelledby="assistant-conversation-title">
                <header class="ai-chat-title">
                    <h2 id="assistant-conversation-title"><?= $escape($t('view.interface.your_conversation')) ?></h2>
                    <?php if ($turns !== []): ?>
                        <form action="/chat" method="post" data-chat-form>
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="intent" value="reset">
                            <button type="submit" class="ai-secondary-action"><?= $escape($t('chat.reset')) ?></button>
                        </form>
                    <?php endif; ?>
                </header>

                <?php if ($turns === []): ?>
                    <p class="ai-chat-hint"><?= $escape($t('chat.hint')) ?></p>
                <?php else: ?>
                    <ol class="chat-messages" data-chat-messages>
                        <?php foreach ($turns as $turn): ?>
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
                                </article>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php if ($cards !== []): ?>
                        <a class="ai-back" href="#assistant-results-title"><?= $escape($t('chat.view_results')) ?> <span aria-hidden="true">↓</span></a>
                    <?php endif; ?>
                <?php endif; ?>

                <form action="/chat" method="post" class="chat-form" data-chat-form>
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="intent" value="send">
                    <input type="hidden" name="requestId" value="<?= $escape($chatRequestId) ?>">
                    <label for="assistant-message"><?= $escape($t('view.interface.describe_the_anime_you_re_looking_for')) ?></label>
                    <textarea id="assistant-message" name="message" rows="3" maxlength="1200" required><?= $escape($chatDraft) ?></textarea>
                    <div class="ai-chat-actions">
                        <button type="submit"><?= $escape($t('view.interface.send')) ?></button>
                        <button type="button" class="ai-secondary-action" data-chat-cancel hidden><?= $escape($t('chat.cancel')) ?></button>
                    </div>
                </form>
                <p class="ai-chat-hint" role="status" data-chat-progress></p>
                <?php if ($chatError !== null): ?>
                    <p class="ai-chat-hint" role="alert" tabindex="-1" data-chat-error><?= $escape($t('chat.error.' . $chatError)) ?></p>
                <?php endif; ?>
            </section>

            <?php require __DIR__ . '/partials/chat-criteria.html.php'; ?>
        </div>

        <?php if ($cards !== []): ?>
            <section class="ai-panel ai-chat-results" aria-labelledby="assistant-results-title">
                <header class="ai-chat-title">
                    <h2 id="assistant-results-title" tabindex="-1"><?= $escape($t('chat.results')) ?></h2>
                    <?php if ($chatState['hasMore'] ?? false): ?>
                        <form action="/chat" method="post" data-chat-form>
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="intent" value="more">
                            <input type="hidden" name="requestId" value="<?= $escape($chatRequestId) ?>">
                            <button type="submit" class="ai-secondary-action"><?= $escape($t('chat.more')) ?></button>
                        </form>
                    <?php endif; ?>
                </header>
                <ul class="anime-grid ai-chat-result-grid">
                    <?php foreach ($cards as $animeId => $card): ?>
                        <li class="ai-chat-result">
                            <?php $anime = $card['anime']; ?>
                            <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                            <?php if ($card['quote'] !== null): ?>
                                <p class="ai-chat-reason">
                                    <?= $escape($t('chat.source_quote')) ?>
                                    <q lang="<?= $escape($anime['descriptionLanguage']) ?>"><?= $escape($card['quote']) ?></q>
                                </p>
                            <?php elseif (!empty($criteria['themes'])): ?>
                                <p class="ai-chat-reason"><?= $escape($t('chat.uncertain')) ?></p>
                            <?php elseif ($card['sharedTags'] !== []): ?>
                                <p class="ai-chat-reason"><?= $escape($t('chat.shared_tags', [
                                    'value' => implode(', ', array_map($translateText, $card['sharedTags'])),
                                ])) ?></p>
                            <?php else: ?>
                                <p class="ai-chat-reason"><?= $escape($t($card['similarDescription'] ? 'chat.shared_words' : 'chat.catalog_match')) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
