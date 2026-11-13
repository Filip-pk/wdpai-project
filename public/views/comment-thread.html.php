<?php
$threadMode = true;
require __DIR__ . '/partials/header.html.php';
$threadUrl = '/anime/' . $animeId . '/comments/' . $thread['id'] . '?commentsPage=' . $commentFormPage . '&repliesPage=' . $replies['page'];
$canReply = !$thread['isRemoved'] && !($thread['isDeleted'] ?? false);
?>

<div class="comment-thread-page anime-comments" data-thread-id="<?= $escape($thread['id']) ?>">
    <a class="comment-thread-back" href="/anime/<?= $escape($animeId) ?>?commentsPage=<?= $escape($commentFormPage) ?>#comment-<?= $escape($thread['id']) ?>"><?= $escape($t('view.interface.anime_comments')) ?></a>

    <header class="comments-heading comment-thread-heading social-hero">
        <div>
            <p class="social-kicker"><?= $escape($t('view.interface.anivibe_community')) ?></p>
            <h1 class="social-title"><?= $escape($t('view.interface.comment_replies')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.join_the_anime_discussion_and_hear_from_other_fans')) ?></p>
        </div>
        <span class="social-pill"><?= $escape($t('view.interface.replies')) ?> <?= $escape($replies['total']) ?></span>
    </header>

    <div class="discussion-anime">
        <span class="discussion-anime-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="m10 8 6 4-6 4Z"/></svg>
        </span>
        <div class="discussion-anime-copy">
            <span><?= $escape($t('view.interface.discussing_this_anime')) ?></span>
            <a href="/anime/<?= $escape($animeId) ?>"><?= $escape($anime['title']) ?></a>
        </div>
    </div>

    <?php if ($commentNotice !== null): ?>
        <p class="comment-notice" data-kind="success" role="status"><?= $escape($translateText($commentNotice)) ?></p>
    <?php endif; ?>
    <?php if ($commentErrors !== []): ?>
        <div id="comments-errors" class="comment-notice" data-kind="error" role="alert">
            <ul><?php foreach ($commentErrors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <section class="comment-thread-root" aria-label="<?= $escape($t('view.interface.thread_starter_comment')) ?>">
        <h2 class="discussion-section-label"><?= $escape($t('view.interface.start_of_the_discussion')) ?></h2>
        <?php $comment = $thread; require __DIR__ . '/partials/comment-card.html.php'; ?>
    </section>

    <section id="replies" class="comment-thread-replies" aria-labelledby="replies-title" data-replies-total="<?= $escape($replies['total']) ?>" data-replies-page="<?= $escape($replies['page']) ?>" data-replies-total-pages="<?= $escape($replies['totalPages']) ?>">
        <header class="comments-heading">
            <div>
                <h2 id="replies-title"><?= $escape($t('view.social.replies_count', ['count' => $replies['total']])) ?></h2>
                <p><?= $escape($t('view.interface.oldest_first')) ?></p>
            </div>
        </header>
        <?php if ($replies['entries'] === []): ?>
            <div class="social-empty">
                <span class="social-empty-icon" aria-hidden="true">✦</span>
                <p><?= $escape($t('view.interface.there_are_no_replies_in_this_thread_yet')) ?></p>
                <?php if ($canReply): ?>
                    <a href="#reply-form"><?= $escape($isLoggedIn ? $t('view.interface.write_the_first_reply') : $t('view.interface.sign_in_to_reply')) ?> <span aria-hidden="true">→</span></a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <ol class="comments-list comment-replies-list" aria-label="<?= $escape($t('view.interface.comment_replies')) ?>">
                <?php foreach ($replies['entries'] as $comment): ?>
                    <li><?php require __DIR__ . '/partials/comment-card.html.php'; ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
        <?php if ($replies['totalPages'] > 1): ?>
            <nav class="comments-pagination" aria-label="<?= $escape($t('view.interface.reply_pages')) ?>">
                <?php if ($replies['page'] > 1): ?>
                    <a href="<?= $escape($replies['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous_d3daaf')) ?></a>
                <?php endif; ?>
                <span><?= $escape($t('view.social.page_of', ['page' => $replies['page'], 'total' => $replies['totalPages']])) ?></span>
                <?php if ($replies['page'] < $replies['totalPages']): ?>
                    <a href="<?= $escape($replies['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next_6ff546')) ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <?php if ($isLoggedIn && ($canReply || $editingComment !== null)): ?>
        <form id="reply-form" action="/anime/<?= $escape($animeId) ?>/comments/<?= $escape($editingComment !== null ? $editingComment['id'] : $thread['id']) ?>/<?= $editingComment !== null ? 'edit' : 'replies' ?>" method="post" class="comment-form" aria-labelledby="reply-form-title">
            <h2 id="reply-form-title"><?= $escape($editingComment !== null ? $t('view.interface.edit_your_post') : $t('view.interface.add_reply')) ?></h2>
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="commentsPage" value="<?= $escape($commentFormPage) ?>">
            <input type="hidden" name="repliesPage" value="<?= $escape($replies['page']) ?>">
            <input type="hidden" name="returnThread" value="<?= $escape($thread['id']) ?>">
            <div class="comment-field">
                <label for="comment-body"><?= $escape($editingComment !== null ? $t('view.interface.post_text') : $t('view.interface.reply_text')) ?></label>
                <textarea id="comment-body" name="body" rows="4" maxlength="2000" aria-describedby="comment-help<?= $commentErrors !== [] ? ' comments-errors' : '' ?>"<?= $commentErrors !== [] ? ' aria-invalid="true"' : '' ?> required><?= $escape($commentBody) ?></textarea>
                <p id="comment-help"><?= $escape($t('view.interface.replies_are_public_you_can_enter_up_to_2_000_characters')) ?></p>
            </div>
            <div class="comment-form-actions">
                <button type="submit"><?= $escape($editingComment !== null ? $t('view.interface.save_changes') : $t('view.interface.add_reply')) ?></button>
                <?php if ($editingComment !== null): ?>
                    <a href="<?= $escape($threadUrl) ?>#replies"><?= $escape($t('view.interface.cancel_editing')) ?></a>
                <?php endif; ?>
            </div>
        </form>
    <?php elseif (!$canReply): ?>
        <p class="comments-empty"><?= $escape($t('view.interface.you_cannot_reply_to_a_deleted_or_hidden_comment_the_existing_discussion_remains_available')) ?></p>
    <?php else: ?>
        <p id="reply-form" class="comments-login"><a href="/login"><?= $escape($t('view.interface.sign_in')) ?></a><?= $escape($t('view.interface.to_add_a_reply')) ?></p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
