<section id="comments" class="anime-details-section anime-comments" aria-labelledby="comments-title" data-comments-total="<?= $escape($comments['total']) ?>" data-comments-page="<?= $escape($comments['page']) ?>" data-comments-total-pages="<?= $escape($comments['totalPages']) ?>">
    <header class="comments-heading">
        <div>
            <h2 id="comments-title"><?= $escape($t('view.interface.comments')) ?></h2>
            <p><?= $escape($comments['total']) ?> <?= $escape($t('view.interface.posts_newest_first')) ?></p>
        </div>
    </header>

    <?php if ($commentNotice !== null): ?>
        <p class="comment-notice" data-kind="success" role="status"><?= $escape($translateText($commentNotice)) ?></p>
    <?php endif; ?>
    <?php if ($commentErrors !== []): ?>
        <div id="comments-errors" class="comment-notice" data-kind="error" role="alert">
            <ul><?php foreach ($commentErrors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($isLoggedIn): ?>
        <form action="/anime/<?= $escape($animeId) ?>/comments<?= $editingComment !== null ? '/' . $escape($editingComment['id']) . '/edit' : '' ?>" method="post" class="comment-form" aria-labelledby="comment-form-title">
            <h3 id="comment-form-title"><?= $escape($editingComment !== null ? $t('view.interface.edit_your_comment') : $t('view.interface.add_comment')) ?></h3>
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="commentsPage" value="<?= $escape($commentFormPage) ?>">
            <div class="comment-field">
                <label for="comment-body"><?= $escape($t('view.interface.comment_text')) ?></label>
                <textarea id="comment-body" name="body" rows="4" maxlength="2000" aria-describedby="comment-help<?= $commentErrors !== [] ? ' comments-errors' : '' ?>"<?= $commentErrors !== [] ? ' aria-invalid="true"' : '' ?> required><?= $escape($commentBody) ?></textarea>
                <p id="comment-help"><?= $escape($t('view.interface.comments_are_public_you_can_enter_up_to_2_000_characters')) ?></p>
            </div>
            <div class="comment-form-actions">
                <button type="submit"><?= $escape($editingComment !== null ? $t('view.interface.save_changes') : $t('view.interface.add_comment')) ?></button>
                <?php if ($editingComment !== null): ?>
                    <a href="/anime/<?= $escape($animeId) ?>?commentsPage=<?= $escape($comments['page']) ?>#comments"><?= $escape($t('view.interface.cancel_editing')) ?></a>
                <?php endif; ?>
            </div>
        </form>
    <?php else: ?>
        <p class="comments-login"><a href="/login"><?= $escape($t('view.interface.sign_in')) ?></a><?= $escape($t('view.interface.to_add_a_comment')) ?></p>
    <?php endif; ?>

    <?php if ($comments['entries'] === []): ?>
        <p class="comments-empty"><?= $escape($t('view.interface.nobody_has_commented_on_this_anime_yet_start_the_conversation')) ?></p>
    <?php else: ?>
        <ol class="comments-list" aria-label="<?= $escape($t('view.interface.user_comments')) ?>">
            <?php foreach ($comments['entries'] as $comment): ?>
                <li>
                    <?php require __DIR__ . '/comment-card.html.php'; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($comments['totalPages'] > 1): ?>
        <nav class="comments-pagination" aria-label="<?= $escape($t('view.interface.comment_pages')) ?>">
            <?php if ($comments['page'] > 1): ?>
                <a href="<?= $escape($comments['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous_d3daaf')) ?></a>
            <?php endif; ?>
            <span><?= $escape($t('view.social.page_of', ['page' => $comments['page'], 'total' => $comments['totalPages']])) ?></span>
            <?php if ($comments['page'] < $comments['totalPages']): ?>
                <a href="<?= $escape($comments['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next_6ff546')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
