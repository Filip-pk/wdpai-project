<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page community-notifications-page" aria-labelledby="reply-notifications-heading" data-reply-notifications-total="<?= $escape($notifications['total']) ?>">
    <header class="community-heading social-hero">
        <div class="community-heading-copy">
            <p class="social-kicker"><?= $escape($t('view.interface.conversations_that_continue')) ?></p>
            <h1 id="reply-notifications-heading" class="social-title"><?= $escape($t('view.interface.notifications')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.replies_from_other_users_to_your_comments')) ?></p>
        </div>
        <span class="social-pill"><span aria-hidden="true">↳</span> <?= $escape($t('view.interface.replies')) ?> <?= $escape($notifications['total']) ?></span>
    </header>

    <nav class="social-tabs" aria-label="<?= $escape($t('view.interface.community_sections')) ?>">
        <a href="/messages"><?= $escape($t('view.interface.messages')) ?></a>
        <a href="/notifications/comments" aria-current="page"><?= $escape($t('view.interface.notifications')) ?></a>
        <a href="/messages/blocked"><?= $escape($t('view.interface.blocked')) ?></a>
    </nav>

    <?php if ($notice !== null): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($translateText($notice)) ?></p>
    <?php endif; ?>

    <?php if ($notifications['snapshotId'] > 0): ?>
        <form class="community-inline-form notification-toolbar" method="post" action="/notifications/comments/read-all">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="lastNotificationId" value="<?= $escape($notifications['snapshotId']) ?>">
            <input type="hidden" name="page" value="<?= $escape($notifications['page']) ?>">
            <button type="submit"><?= $escape($t('view.interface.mark_all_as_read')) ?></button>
        </form>
    <?php endif; ?>

    <?php if ($notifications['entries'] === []): ?>
        <div class="community-panel community-empty social-empty">
            <span class="social-empty-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            </span>
            <h2><?= $escape($t('view.interface.you_have_no_reply_notifications_yet')) ?></h2>
            <p><?= $escape($t('view.interface.when_someone_replies_to_your_comment_continue_the_discussion_here')) ?></p>
            <a class="community-back" href="/anime"><?= $escape($t('view.interface.discover_anime')) ?> <span aria-hidden="true">→</span></a>
        </div>
    <?php else: ?>
        <ol class="community-list" aria-label="<?= $escape($t('view.interface.reply_notifications')) ?>">
            <?php foreach ($notifications['entries'] as $notification): ?>
                <?php $notificationDate = (new DateTimeImmutable($notification['createdAt']))->setTimezone(new DateTimeZone('Europe/Warsaw')); ?>
                <li>
                    <article class="community-panel notification-card" data-reply-notification-id="<?= $escape($notification['id']) ?>" data-notification-read="<?= $notification['isRead'] ? '1' : '0' ?>">
                        <span class="notification-icon" aria-hidden="true">↳</span>
                        <div class="notification-copy">
                            <h2><?= $escape($t('view.social.reply_notification', ['user' => $notification['authorName']])) ?></h2>
                            <p class="notification-anime"><?= $escape($t('view.interface.anime')) ?> <?= $escape($notification['animeTitle']) ?></p>
                            <p class="community-meta">
                                <span class="notification-status"><?= $escape($notification['isRead'] ? $t('view.interface.read') : $t('view.interface.new')) ?></span>
                                <time datetime="<?= $escape($notificationDate->format(DATE_ATOM)) ?>"><?= $escape($notificationDate->format('d.m.Y H:i')) ?></time>
                            </p>
                        </div>
                        <form class="community-inline-form" method="post" action="/notifications/comments/<?= $escape($notification['id']) ?>/read">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <button type="submit"><?= $escape($t('view.interface.open_reply')) ?></button>
                        </form>
                    </article>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($notifications['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="<?= $escape($t('view.interface.notification_pages')) ?>">
            <?php if ($notifications['previousUrl'] !== null): ?>
                <a href="<?= $escape($notifications['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous')) ?></a>
            <?php endif; ?>
            <span><?= $escape($t('view.social.page_of', ['page' => $notifications['page'], 'total' => $notifications['totalPages']])) ?></span>
            <?php if ($notifications['nextUrl'] !== null): ?>
                <a href="<?= $escape($notifications['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
