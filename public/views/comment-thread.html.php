<?php
require __DIR__ . '/partials/header.html.php';
$threadMode = true;
$threadUrl = '/anime/' . $animeId . '/comments/' . $thread['id'] . '?commentsPage=' . $commentFormPage . '&repliesPage=' . $replies['page'];
$canReply = !$thread['isRemoved'] && !($thread['isDeleted'] ?? false);
?>

<div class="comment-thread-page anime-comments" data-thread-id="<?= $escape($thread['id']) ?>">
    <header class="comments-heading comment-thread-heading">
        <div>
            <a class="comment-thread-back" href="/anime/<?= $escape($animeId) ?>?commentsPage=<?= $escape($commentFormPage) ?>#comment-<?= $escape($thread['id']) ?>">← Komentarze do anime</a>
            <h1>Odpowiedzi na komentarz</h1>
            <p><?= $escape($anime['title']) ?></p>
        </div>
    </header>

    <?php if ($commentNotice !== null): ?>
        <p class="comment-notice" data-kind="success" role="status"><?= $escape($commentNotice) ?></p>
    <?php endif; ?>
    <?php if ($commentErrors !== []): ?>
        <div id="comments-errors" class="comment-notice" data-kind="error" role="alert">
            <ul><?php foreach ($commentErrors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <section class="comment-thread-root" aria-label="Komentarz rozpoczynający wątek">
        <?php $comment = $thread; require __DIR__ . '/partials/comment-card.html.php'; ?>
    </section>

    <section id="replies" class="comment-thread-replies" aria-labelledby="replies-title" data-replies-total="<?= $escape($replies['total']) ?>" data-replies-page="<?= $escape($replies['page']) ?>" data-replies-total-pages="<?= $escape($replies['totalPages']) ?>">
        <header class="comments-heading">
            <div>
                <h2 id="replies-title">Odpowiedzi (<?= $escape($replies['total']) ?>)</h2>
                <p>Od najstarszej do najnowszej</p>
            </div>
        </header>
        <?php if ($replies['entries'] === []): ?>
            <p class="comments-empty">Nie ma jeszcze odpowiedzi w tym wątku.</p>
        <?php else: ?>
            <ol class="comments-list comment-replies-list" aria-label="Odpowiedzi na komentarz">
                <?php foreach ($replies['entries'] as $comment): ?>
                    <li><?php require __DIR__ . '/partials/comment-card.html.php'; ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <?php if ($replies['totalPages'] > 1): ?>
            <nav class="comments-pagination" aria-label="Strony odpowiedzi">
                <?php if ($replies['page'] > 1): ?>
                    <a href="<?= $escape($replies['previousUrl']) ?>" rel="prev">← Poprzednia</a>
                <?php endif; ?>
                <span>Strona <?= $escape($replies['page']) ?> z <?= $escape($replies['totalPages']) ?></span>
                <?php if ($replies['page'] < $replies['totalPages']): ?>
                    <a href="<?= $escape($replies['nextUrl']) ?>" rel="next">Następna →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <?php if ($isLoggedIn && ($canReply || $editingComment !== null)): ?>
        <form id="reply-form" action="/anime/<?= $escape($animeId) ?>/comments/<?= $escape($editingComment !== null ? $editingComment['id'] : $thread['id']) ?>/<?= $editingComment !== null ? 'edit' : 'replies' ?>" method="post" class="comment-form" aria-labelledby="reply-form-title">
            <h2 id="reply-form-title"><?= $editingComment !== null ? 'Edytuj swój wpis' : 'Dodaj odpowiedź' ?></h2>
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="commentsPage" value="<?= $escape($commentFormPage) ?>">
            <input type="hidden" name="repliesPage" value="<?= $escape($replies['page']) ?>">
            <input type="hidden" name="returnThread" value="<?= $escape($thread['id']) ?>">
            <div class="comment-field">
                <label for="comment-body"><?= $editingComment !== null ? 'Treść wpisu' : 'Treść odpowiedzi' ?></label>
                <textarea id="comment-body" name="body" rows="4" maxlength="2000" aria-describedby="comment-help<?= $commentErrors !== [] ? ' comments-errors' : '' ?>"<?= $commentErrors !== [] ? ' aria-invalid="true"' : '' ?> required><?= $escape($commentBody) ?></textarea>
                <p id="comment-help">Odpowiedzi są publiczne. Możesz wpisać do 2000 znaków.</p>
            </div>
            <div class="comment-form-actions">
                <button type="submit"><?= $editingComment !== null ? 'Zapisz zmiany' : 'Dodaj odpowiedź' ?></button>
                <?php if ($editingComment !== null): ?>
                    <a href="<?= $escape($threadUrl) ?>#replies">Anuluj edycję</a>
                <?php endif; ?>
            </div>
        </form>
    <?php elseif (!$canReply): ?>
        <p class="comments-empty">Do usuniętego lub ukrytego komentarza nie można dodawać odpowiedzi. Dotychczasowa dyskusja pozostaje dostępna.</p>
    <?php else: ?>
        <p id="reply-form" class="comments-login"><a href="/login">Zaloguj się</a>, aby dodać odpowiedź.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
