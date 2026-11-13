<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-persona">
    <a href="/dashboard#personas-title" class="ai-back"><?= $escape($t('view.interface.back_to_characters')) ?></a>
    <header class="chat-heading social-hero">
        <div class="ai-persona-intro">
            <span class="ai-persona-avatar" aria-hidden="true">✦</span>
            <div class="ai-persona-copy">
                <p class="social-kicker"><?= $escape($t('view.interface.anime')) ?> <?= $escape($persona['anime']) ?></p>
                <h1 class="social-title"><?= $escape($t('view.interface.chat_with_character')) ?> <?= $escape($persona['name']) ?></h1>
                <p class="social-description"><?= $escape($t('view.interface.chat_with_ai_playing_a_fictional_character')) ?></p>
            </div>
        </div>
        <span class="social-pill"><?= $escape($t('view.interface.persona_preview')) ?></span>
    </header>

    <p id="persona-demo-notice" class="ai-demo-notice"><span aria-hidden="true">◇</span> <?= $escape($t('view.interface.demo_version_messages_and_bond_features_are_examples_sending_messages_is_not_available_yet')) ?></p>

    <div class="ai-layout<?= !$isNaruto ? ' ai-layout-single' : '' ?>">
        <section class="ai-panel ai-chat-panel" aria-labelledby="persona-conversation-title">
            <header class="ai-chat-title">
                <h2 id="persona-conversation-title"><?= $escape($isNaruto ? $t('view.interface.sample_conversation') : $t('view.interface.your_conversation')) ?></h2>
                <span class="social-pill"><?= $escape($t('view.interface.fictional_character')) ?></span>
            </header>
            <?php if ($isNaruto): ?>
                <ol class="chat-messages">
                    <li>
                        <article class="message message-user">
                            <h3><?= $escape($t('view.interface.you_ce679e')) ?></h3>
                            <p lang="pl">Siemanko Naruto! Jak tam trening z Jiraiyą? Trudno jest?</p>
                            <time datetime="14:36">14:36</time>
                        </article>
                    </li>
                    <li>
                        <article class="message message-persona">
                            <h3>Naruto Uzumaki</h3>
                            <p lang="pl">Siemano! Dattebayo! Jiraiya-sama daje mi niezły wycisk, ale nie poddam się, bo zostanę Hokage!</p>
                            <p lang="pl">Choć teraz chętnie wciągnąłbym wielką miskę ramenu w Ichiraku...</p>
                            <p lang="pl">A u Ciebie co słychać?! Masz dzisiaj wolną chwilę na mały sparing?</p>
                            <time datetime="14:37">14:37</time>
                        </article>
                    </li>
                </ol>
            <?php else: ?>
                <div class="social-empty">
                    <span class="social-empty-icon" aria-hidden="true">✦</span>
                    <p><?= $escape($t('view.interface.no_conversation_has_started_this_is_a_preview_of_another_persona_template')) ?></p>
                    <a href="/dashboard#personas-title"><?= $escape($t('view.interface.meet_the_other_characters')) ?> <span aria-hidden="true">→</span></a>
                </div>
            <?php endif; ?>

            <form action="/chat/message" method="post" class="chat-form">
                <fieldset disabled aria-describedby="persona-demo-notice">
                    <legend><?= $escape($t('view.interface.message_to_the_character')) ?></legend>
                    <label for="persona-message"><?= $escape($t('view.interface.write_a_message')) ?></label>
                    <textarea id="persona-message" name="message" rows="3" maxlength="2000" required></textarea>
                    <button type="submit"><?= $escape($t('view.interface.send')) ?> <span aria-hidden="true">↗</span></button>
                </fieldset>
            </form>
        </section>

        <?php if ($isNaruto): ?>
            <aside class="ai-rail" aria-label="<?= $escape($t('view.interface.bond_and_extra_persona_features')) ?>">
                <section class="ai-panel ninja-bond" aria-labelledby="bond-title">
                    <h2 id="bond-title"><?= $escape($t('view.interface.ninja_bond_friend_level_4')) ?></h2>
                    <label for="ninja-progress"><?= $escape($t('view.interface.sample_progress_720_1000_xp')) ?></label>
                    <progress id="ninja-progress" value="720" max="1000">720 / 1000 XP</progress>
                    <p class="ai-bond-rank"><?= $escape($t('view.interface.rank_genin')) ?></p>
                </section>
                <aside class="ai-panel ramen-card" aria-labelledby="ramen-title">
                    <span class="ai-card-symbol" aria-hidden="true">🍜</span>
                    <h2 id="ramen-title"><?= $escape($t('view.interface.bowl_of_ichiraku_ramen')) ?></h2>
                    <p><?= $escape($t('view.interface.virtual_gift_and_bond_points_a_demo_feature')) ?></p>
                    <button type="button" disabled><?= $escape($t('view.interface.give_ramen')) ?></button>
                </aside>
                <section class="ai-panel" aria-labelledby="quick-replies-title">
                    <h2 id="quick-replies-title"><?= $escape($t('view.interface.quick_replies')) ?></h2>
                    <div class="ai-quick-actions">
                        <button type="button" disabled><?= $escape($t('view.interface.good_luck_with_training')) ?></button>
                        <button type="button" disabled><?= $escape($t('view.interface.let_s_get_ramen_together')) ?></button>
                        <button type="button" disabled><?= $escape($t('view.interface.teach_me_the_rasengan')) ?></button>
                    </div>
                </section>
                <article class="ai-panel adventure-card" aria-labelledby="adventure-title">
                    <span class="ai-card-symbol" aria-hidden="true">◇</span>
                    <h2 id="adventure-title"><?= $escape($t('view.interface.adventure_card_konoha_mission')) ?></h2>
                    <p><?= $escape($t('view.interface.a_shared_mission_with_kakashi_s_team')) ?></p>
                    <p><?= $escape($t('view.interface.preview_of_a_mockup_feature')) ?></p>
                    <button type="button" disabled><?= $escape($t('view.interface.view_adventure')) ?></button>
                </article>
            </aside>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
