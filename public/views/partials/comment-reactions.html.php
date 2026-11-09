<?php
$counts = $reactionCounts[$comment['id']] ?? ['likes' => 0, 'dislikes' => 0, 'userReaction' => 0];
$currentReaction = (int) $counts['userReaction'];
?>
<div class="comment-reactions" role="group" aria-label="Reakcje na wpis">
    <?php if ($isLoggedIn && !$isOwnComment): ?>
        <form class="comment-reaction-form" action="/anime/<?= $escape($animeId) ?>/comments/<?= $escape($comment['id']) ?>/reaction" method="post">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="commentsPage" value="<?= $escape($mainCommentsPage) ?>">
            <?php if ($isThreadView): ?>
                <input type="hidden" name="returnThread" value="<?= $escape($rootCommentId) ?>">
                <input type="hidden" name="repliesPage" value="<?= $escape($replyPage) ?>">
            <?php endif; ?>
            <button type="submit" name="value" value="<?= $currentReaction === 1 ? '0' : '1' ?>" data-comment-reaction="like" aria-pressed="<?= $currentReaction === 1 ? 'true' : 'false' ?>">
                <span aria-hidden="true">👍</span>
                <span>Lubię to</span>
                <span class="comment-reaction-count" data-comment-likes="<?= $escape($counts['likes']) ?>"><?= $escape($counts['likes']) ?></span>
            </button>
            <button type="submit" name="value" value="<?= $currentReaction === -1 ? '0' : '-1' ?>" data-comment-reaction="dislike" aria-pressed="<?= $currentReaction === -1 ? 'true' : 'false' ?>">
                <span aria-hidden="true">👎</span>
                <span>Nie lubię</span>
                <span class="comment-reaction-count" data-comment-dislikes="<?= $escape($counts['dislikes']) ?>"><?= $escape($counts['dislikes']) ?></span>
            </button>
        </form>
    <?php else: ?>
        <span class="comment-reaction-total">
            <span aria-hidden="true">👍</span>
            <span>Lubię to</span>
            <span class="comment-reaction-count" data-comment-likes="<?= $escape($counts['likes']) ?>"><?= $escape($counts['likes']) ?></span>
        </span>
        <span class="comment-reaction-total">
            <span aria-hidden="true">👎</span>
            <span>Nie lubię</span>
            <span class="comment-reaction-count" data-comment-dislikes="<?= $escape($counts['dislikes']) ?>"><?= $escape($counts['dislikes']) ?></span>
        </span>
        <?php if (!$isLoggedIn): ?>
            <a class="comment-reaction-login" href="/login">Zaloguj się, aby ocenić</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
