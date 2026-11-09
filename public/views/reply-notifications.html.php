<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page" aria-labelledby="reply-notifications-heading" data-reply-notifications-total="<?= $escape($notifications['total']) ?>">
    <header class="community-heading">
        <h1 id="reply-notifications-heading">Powiadomienia</h1>
        <p>Odpowiedzi innych użytkowników na Twoje komentarze.</p>
    </header>

    <?php if ($notice !== null): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <?php if ($notifications['snapshotId'] > 0): ?>
        <form class="community-inline-form" method="post" action="/notifications/comments/read-all">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="lastNotificationId" value="<?= $escape($notifications['snapshotId']) ?>">
            <input type="hidden" name="page" value="<?= $escape($notifications['page']) ?>">
            <button type="submit">Oznacz wszystkie jako przeczytane</button>
        </form>
    <?php endif; ?>

    <?php if ($notifications['entries'] === []): ?>
        <p class="community-panel community-empty">Nie masz jeszcze powiadomień o odpowiedziach.</p>
    <?php else: ?>
        <ol class="community-list" aria-label="Powiadomienia o odpowiedziach">
            <?php foreach ($notifications['entries'] as $notification): ?>
                <?php $notificationDate = (new DateTimeImmutable($notification['createdAt']))->setTimezone(new DateTimeZone('Europe/Warsaw')); ?>
                <li>
                    <article class="community-panel" data-reply-notification-id="<?= $escape($notification['id']) ?>" data-notification-read="<?= $notification['isRead'] ? '1' : '0' ?>">
                        <h2><?= $escape($notification['authorName']) ?> odpowiedział(a) na Twój komentarz</h2>
                        <p>Anime: <?= $escape($notification['animeTitle']) ?></p>
                        <p class="community-meta">
                            <time datetime="<?= $escape($notificationDate->format(DATE_ATOM)) ?>"><?= $escape($notificationDate->format('d.m.Y H:i')) ?></time>
                            · <?= $notification['isRead'] ? 'Przeczytane' : 'Nowe' ?>
                        </p>
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
