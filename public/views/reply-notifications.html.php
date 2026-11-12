<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page community-notifications-page" aria-labelledby="reply-notifications-heading" data-reply-notifications-total="<?= $escape($notifications['total']) ?>">
    <header class="community-heading social-hero">
        <div class="community-heading-copy">
            <p class="social-kicker">Rozmowy, które trwają dalej</p>
            <h1 id="reply-notifications-heading" class="social-title">Powiadomienia</h1>
            <p class="social-description">Odpowiedzi innych użytkowników na Twoje komentarze.</p>
        </div>
        <span class="social-pill"><span aria-hidden="true">↳</span> Odpowiedzi: <?= $escape($notifications['total']) ?></span>
    </header>

    <nav class="social-tabs" aria-label="Sekcje społeczności">
        <a href="/messages">Wiadomości</a>
        <a href="/notifications/comments" aria-current="page">Powiadomienia</a>
        <a href="/messages/blocked">Zablokowani</a>
    </nav>

    <?php if ($notice !== null): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <?php if ($notifications['snapshotId'] > 0): ?>
        <form class="community-inline-form notification-toolbar" method="post" action="/notifications/comments/read-all">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="lastNotificationId" value="<?= $escape($notifications['snapshotId']) ?>">
            <input type="hidden" name="page" value="<?= $escape($notifications['page']) ?>">
            <button type="submit">Oznacz wszystkie jako przeczytane</button>
        </form>
    <?php endif; ?>

    <?php if ($notifications['entries'] === []): ?>
        <div class="community-panel community-empty social-empty">
            <span class="social-empty-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
            </span>
            <h2>Nie masz jeszcze powiadomień o odpowiedziach.</h2>
            <p>Gdy ktoś odpowie na Twój komentarz, znajdziesz tutaj dalszy ciąg dyskusji.</p>
            <a class="community-back" href="/anime">Odkryj anime <span aria-hidden="true">→</span></a>
        </div>
    <?php else: ?>
        <ol class="community-list" aria-label="Powiadomienia o odpowiedziach">
            <?php foreach ($notifications['entries'] as $notification): ?>
                <?php $notificationDate = (new DateTimeImmutable($notification['createdAt']))->setTimezone(new DateTimeZone('Europe/Warsaw')); ?>
                <li>
                    <article class="community-panel notification-card" data-reply-notification-id="<?= $escape($notification['id']) ?>" data-notification-read="<?= $notification['isRead'] ? '1' : '0' ?>">
                        <span class="notification-icon" aria-hidden="true">↳</span>
                        <div class="notification-copy">
                            <h2><?= $escape($notification['authorName']) ?> odpowiedział(a) na Twój komentarz</h2>
                            <p class="notification-anime">Anime: <?= $escape($notification['animeTitle']) ?></p>
                            <p class="community-meta">
                                <span class="notification-status"><?= $notification['isRead'] ? 'Przeczytane' : 'Nowe' ?></span>
                                <time datetime="<?= $escape($notificationDate->format(DATE_ATOM)) ?>"><?= $escape($notificationDate->format('d.m.Y H:i')) ?></time>
                            </p>
                        </div>
                        <form class="community-inline-form" method="post" action="/notifications/comments/<?= $escape($notification['id']) ?>/read">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <button type="submit">Otwórz odpowiedź</button>
                        </form>
                    </article>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($notifications['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="Strony powiadomień">
            <?php if ($notifications['previousUrl'] !== null): ?>
                <a href="<?= $escape($notifications['previousUrl']) ?>" rel="prev">Poprzednia</a>
            <?php endif; ?>
            <span>Strona <?= $escape($notifications['page']) ?> z <?= $escape($notifications['totalPages']) ?></span>
            <?php if ($notifications['nextUrl'] !== null): ?>
                <a href="<?= $escape($notifications['nextUrl']) ?>" rel="next">Następna</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
