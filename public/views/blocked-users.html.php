<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page community-blocked-page" aria-labelledby="blocked-heading">
    <header class="community-heading social-hero">
        <div class="community-heading-copy">
            <p class="social-kicker"><?= $escape($t('view.interface.your_conversations_your_rules')) ?></p>
            <h1 id="blocked-heading" class="social-title"><?= $escape($t('view.interface.blocked_users')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.blocking_stops_messages_in_both_directions_conversation_history_remains_available')) ?></p>
        </div>
        <span class="social-pill"><span aria-hidden="true">◌</span> <?= $escape($t('view.interface.blocked_d320e2')) ?> <?= $escape($blocked['total']) ?></span>
    </header>

    <nav class="social-tabs" aria-label="<?= $escape($t('view.interface.community_sections')) ?>">
        <a href="/messages"><?= $escape($t('view.interface.messages')) ?></a>
        <a href="/notifications/comments"><?= $escape($t('view.interface.notifications')) ?></a>
        <a href="/messages/blocked" aria-current="page"><?= $escape($t('view.interface.blocked')) ?></a>
    </nav>

    <?php if (($notice ?? '') !== ''): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($translateText($notice)) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($translateText($error)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($blocked['entries'] === []): ?>
        <div class="community-panel community-empty social-empty">
            <span class="social-empty-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m12 3 8 3v6c0 4-8 9-8 9s-8-5-8-9V6Z"/><path d="m8 12 3 3 5-6"/></svg>
            </span>
            <h2><?= $escape($t('view.interface.you_haven_t_blocked_any_users')) ?></h2>
            <p><?= $escape($t('view.interface.you_can_also_manage_blocks_from_a_private_conversation')) ?></p>
            <a class="community-back" href="/messages"><?= $escape($t('view.interface.messages_95907a')) ?></a>
        </div>
    <?php else: ?>
        <ul class="community-list" aria-label="<?= $escape($t('view.interface.blocked_users')) ?>" data-blocked-total="<?= $escape($blocked['total']) ?>">
            <?php foreach ($blocked['entries'] as $blockedUser): ?>
                <li>
                    <article class="community-panel community-blocked-user" data-blocked-user-id="<?= $escape($blockedUser['id']) ?>">
                        <a class="community-user" href="/users/<?= $escape($blockedUser['id']) ?>">
                            <span class="community-avatar" aria-hidden="true">
                                <?php if (($blockedUser['avatarUrl'] ?? null) !== null): ?>
                                    <img src="<?= $escape($blockedUser['avatarUrl']) ?>" alt="" loading="lazy" width="48" height="48">
                                <?php else: ?>
                                    <span>🌸</span>
                                <?php endif; ?>
                            </span>
                            <span class="blocked-user-copy">
                                <span class="community-user-name"><?= $escape($blockedUser['name']) ?></span>
                                <span class="blocked-user-status"><?= $escape($t('view.interface.messages_blocked')) ?></span>
                            </span>
                        </a>
                        <form class="community-inline-form" method="post" action="/users/<?= $escape($blockedUser['id']) ?>/unblock">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="returnTo" value="blocked">
                            <button type="submit"><?= $escape($t('view.interface.unblock')) ?></button>
                        </form>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($blocked['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="<?= $escape($t('view.interface.blocked_user_pages')) ?>">
            <?php if ($blocked['previousUrl'] !== null): ?>
                <a href="<?= $escape($blocked['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous')) ?></a>
            <?php endif; ?>
            <span><?= $escape($t('view.social.page_of', ['page' => $blocked['page'], 'total' => $blocked['totalPages']])) ?></span>
            <?php if ($blocked['nextUrl'] !== null): ?>
                <a href="<?= $escape($blocked['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
