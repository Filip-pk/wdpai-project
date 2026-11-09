<?php
$createdDate = (new DateTimeImmutable($comment['createdAt']))->setTimezone(new DateTimeZone('Europe/Warsaw'));
$updatedDate = $comment['updatedAt'] !== null
    ? (new DateTimeImmutable($comment['updatedAt']))->setTimezone(new DateTimeZone('Europe/Warsaw'))
    : null;
$isOwnComment = $isLoggedIn && $accountId === $comment['userId'];
$isDeletedComment = $comment['isDeleted'] ?? false;
$isReplyComment = ($comment['parentId'] ?? null) !== null;
$isThreadView = $threadMode ?? false;
$rootCommentId = $isThreadView ? $thread['id'] : $comment['id'];
$mainCommentsPage = $isThreadView ? $commentFormPage : $comments['page'];
$replyPage = $isThreadView ? $replies['page'] : 1;
$threadUrl = '/anime/' . $animeId . '/comments/' . $rootCommentId . '?commentsPage=' . $mainCommentsPage . '&repliesPage=' . $replyPage;
$editUrl = $isThreadView
    ? $threadUrl . '&editComment=' . $comment['id'] . '#reply-form'
    : '/anime/' . $animeId . '?commentsPage=' . $mainCommentsPage . '&editComment=' . $comment['id'] . '#comments';
$reportUrl = '/reports/comment/' . $comment['id'] . '?page=' . ($isReplyComment ? $replyPage : $mainCommentsPage)
    . ($isThreadView ? '&thread=' . $rootCommentId . '&commentsPage=' . $mainCommentsPage . '&repliesPage=' . $replyPage : '');
?>
<article id="comment-<?= $escape($comment['id']) ?>" class="comment-card<?= $isReplyComment ? ' comment-reply-card' : '' ?>" aria-labelledby="comment-author-<?= $escape($comment['id']) ?>" data-comment-id="<?= $escape($comment['id']) ?>" data-comment-user-id="<?= $escape($comment['userId']) ?>"<?php if ($isReplyComment): ?> data-comment-parent-id="<?= $escape($comment['parentId']) ?>"<?php endif; ?>>
    <header class="comment-header">
        <a id="comment-author-<?= $escape($comment['id']) ?>" class="comment-author" href="/users/<?= $escape($comment['userId']) ?>" aria-label="<?= $escape('Profil autora: ' . $comment['authorName']) ?>">
            <span class="comment-avatar" aria-hidden="true">
                <?php if ($comment['avatarUrl'] !== null): ?>
                    <img src="<?= $escape($comment['avatarUrl']) ?>" alt="" width="48" height="48" loading="lazy" decoding="async">
                <?php else: ?>
                    ✦
                <?php endif; ?>
            </span>
            <span class="comment-author-name"><?= $escape($comment['authorName']) ?></span>
        </a>
        <div class="comment-dates">
            <time datetime="<?= $escape($createdDate->format(DATE_ATOM)) ?>"><?= $escape($createdDate->format('d.m.Y H:i')) ?></time>
            <?php if ($updatedDate !== null && !$isDeletedComment): ?>
                <span>Edytowano: <time datetime="<?= $escape($updatedDate->format(DATE_ATOM)) ?>"><?= $escape($updatedDate->format('d.m.Y H:i')) ?></time></span>
            <?php endif; ?>
        </div>
    </header>
    <p class="comment-body"><?= $isDeletedComment ? 'Komentarz usunięty przez autora.' : ($comment['isRemoved'] ? 'Komentarz ukryty przez moderację.' : $escape($comment['body'])) ?></p>
    <?php if (!$isDeletedComment && !$comment['isRemoved']): ?>
        <?php require __DIR__ . '/comment-reactions.html.php'; ?>
    <?php endif; ?>
    <footer class="comment-owner-actions comment-card-actions">
        <?php if (!$isReplyComment && !$isThreadView): ?>
            <a class="comment-thread-link" href="<?= $escape($threadUrl) ?>#replies">Odpowiedzi: <?= $escape($comment['replyCount'] ?? 0) ?></a>
        <?php endif; ?>
        <?php if (!$isReplyComment && !$comment['isRemoved'] && !$isDeletedComment && !$isThreadView): ?>
            <a class="comment-reply-link" href="<?= $escape($threadUrl) ?>#reply-form">Odpowiedz</a>
        <?php endif; ?>
        <?php if ($isLoggedIn && !$isOwnComment && !$comment['isRemoved'] && !$isDeletedComment): ?>
            <a class="community-report-link" href="<?= $escape($reportUrl) ?>">Zgłoś <?= $isReplyComment ? 'odpowiedź' : 'komentarz' ?></a>
        <?php endif; ?>
        <?php if ($isOwnComment && !$isDeletedComment): ?>
            <?php if (!$comment['isRemoved']): ?>
                <a class="comment-edit" href="<?= $escape($editUrl) ?>">Edytuj</a>
            <?php endif; ?>
            <details class="comment-delete">
                <summary>Usuń</summary>
                <form action="/anime/<?= $escape($animeId) ?>/comments/<?= $escape($comment['id']) ?>/delete" method="post" class="comment-delete-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="commentsPage" value="<?= $escape($mainCommentsPage) ?>">
                    <?php if ($isThreadView): ?>
                        <input type="hidden" name="returnThread" value="<?= $escape($rootCommentId) ?>">
                        <input type="hidden" name="repliesPage" value="<?= $escape($replyPage) ?>">
                    <?php endif; ?>
                    <p><?= $isReplyComment ? 'Usunąć tę odpowiedź?' : 'Usunąć ten komentarz?' ?></p>
                    <button type="submit">Potwierdź usunięcie</button>
                </form>
            </details>
        <?php endif; ?>
    </footer>
</article>
