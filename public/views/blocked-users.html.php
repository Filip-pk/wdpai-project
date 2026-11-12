<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page community-blocked-page" aria-labelledby="blocked-heading">
    <header class="community-heading social-hero">
        <div class="community-heading-copy">
            <p class="social-kicker">Twoje rozmowy · Twoje zasady</p>
            <h1 id="blocked-heading" class="social-title">Zablokowani użytkownicy</h1>
            <p class="social-description">Blokada zatrzymuje wysyłanie wiadomości w obu kierunkach. Historia rozmów pozostaje dostępna.</p>
        </div>
        <span class="social-pill"><span aria-hidden="true">◌</span> Zablokowani: <?= $escape($blocked['total']) ?></span>
    </header>

    <nav class="social-tabs" aria-label="Sekcje społeczności">
        <a href="/messages">Wiadomości</a>
        <a href="/notifications/comments">Powiadomienia</a>
        <a href="/messages/blocked" aria-current="page">Zablokowani</a>
    </nav>

    <?php if (($notice ?? '') !== ''): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($blocked['entries'] === []): ?>
        <div class="community-panel community-empty social-empty">
            <span class="social-empty-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m12 3 8 3v6c0 4-8 9-8 9s-8-5-8-9V6Z"/><path d="m8 12 3 3 5-6"/></svg>
            </span>
            <h2>Nie blokujesz żadnego użytkownika.</h2>
            <p>Listą blokad możesz zarządzać również z poziomu prywatnej rozmowy.</p>
            <a class="community-back" href="/messages">← Wiadomości</a>
        </div>
    <?php else: ?>
        <ul class="community-list" aria-label="Zablokowani użytkownicy" data-blocked-total="<?= $escape($blocked['total']) ?>">
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
                                <span class="blocked-user-status">Wiadomości zablokowane</span>
                            </span>
                        </a>
                        <form class="community-inline-form" method="post" action="/users/<?= $escape($blockedUser['id']) ?>/unblock">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="returnTo" value="blocked">
                            <button type="submit">Odblokuj</button>
                        </form>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($blocked['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="Strony zablokowanych użytkowników">
            <?php if ($blocked['previousUrl'] !== null): ?>
                <a href="<?= $escape($blocked['previousUrl']) ?>" rel="prev">Poprzednia</a>
            <?php endif; ?>
            <span>Strona <?= $escape($blocked['page']) ?> z <?= $escape($blocked['totalPages']) ?></span>
            <?php if ($blocked['nextUrl'] !== null): ?>
                <a href="<?= $escape($blocked['nextUrl']) ?>" rel="next">Następna</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
