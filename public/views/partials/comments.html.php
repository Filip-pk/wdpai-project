<section id="comments" class="anime-details-section anime-comments" aria-labelledby="comments-title" data-comments-total="<?= $escape($comments['total']) ?>" data-comments-page="<?= $escape($comments['page']) ?>" data-comments-total-pages="<?= $escape($comments['totalPages']) ?>">
    <header class="comments-heading">
        <div>
            <h2 id="comments-title">Komentarze</h2>
            <p><?= $escape($comments['total']) ?> wpisów · Najnowsze na początku</p>
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

    <?php if ($isLoggedIn): ?>
        <form action="/anime/<?= $escape($animeId) ?>/comments<?= $editingComment !== null ? '/' . $escape($editingComment['id']) . '/edit' : '' ?>" method="post" class="comment-form" aria-labelledby="comment-form-title">
            <h3 id="comment-form-title"><?= $editingComment !== null ? 'Edytuj swój komentarz' : 'Dodaj komentarz' ?></h3>
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="commentsPage" value="<?= $escape($commentFormPage) ?>">
            <div class="comment-field">
                <label for="comment-body">Treść komentarza</label>
                <textarea id="comment-body" name="body" rows="4" maxlength="2000" aria-describedby="comment-help<?= $commentErrors !== [] ? ' comments-errors' : '' ?>"<?= $commentErrors !== [] ? ' aria-invalid="true"' : '' ?> required><?= $escape($commentBody) ?></textarea>
                <p id="comment-help">Komentarze są publiczne. Możesz wpisać do 2000 znaków.</p>
            </div>
            <div class="comment-form-actions">
                <button type="submit"><?= $editingComment !== null ? 'Zapisz zmiany' : 'Dodaj komentarz' ?></button>
                <?php if ($editingComment !== null): ?>
                    <a href="/anime/<?= $escape($animeId) ?>?commentsPage=<?= $escape($comments['page']) ?>#comments">Anuluj edycję</a>
                <?php endif; ?>
            </div>
        </form>
    <?php else: ?>
        <p class="comments-login"><a href="/login">Zaloguj się</a>, aby dodać komentarz.</p>
    <?php endif; ?>

    <?php if ($comments['entries'] === []): ?>
        <p class="comments-empty">Nikt jeszcze nie skomentował tego anime. Rozpocznij rozmowę!</p>
    <?php else: ?>
        <ol class="comments-list" aria-label="Komentarze użytkowników">
            <?php foreach ($comments['entries'] as $comment): ?>
                <li>
                    <?php require __DIR__ . '/comment-card.html.php'; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($comments['totalPages'] > 1): ?>
        <nav class="comments-pagination" aria-label="Strony komentarzy">
            <?php if ($comments['page'] > 1): ?>
                <a href="<?= $escape($comments['previousUrl']) ?>" rel="prev">← Poprzednia</a>
            <?php endif; ?>
            <span>Strona <?= $escape($comments['page']) ?> z <?= $escape($comments['totalPages']) ?></span>
            <?php if ($comments['page'] < $comments['totalPages']): ?>
                <a href="<?= $escape($comments['nextUrl']) ?>" rel="next">Następna →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
