<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page" aria-labelledby="blocked-heading">
    <header class="community-heading">
        <a class="community-back" href="/messages">← Wiadomości</a>
        <h1 id="blocked-heading">Zablokowani użytkownicy</h1>
        <p>Blokada zatrzymuje wysyłanie wiadomości w obu kierunkach. Historia rozmów pozostaje dostępna.</p>
    </header>

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
        <p class="community-panel community-empty">Nie blokujesz żadnego użytkownika.</p>
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
                            <span class="community-user-name"><?= $escape($blockedUser['name']) ?></span>
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
