<?php
use App\Service\ModerationNoticeFormatter;

require __DIR__ . '/partials/header.html.php';

$decisionForm = static function (string $action, string $label, string $formId, array $extra = [], bool $withDuration = false) use ($csrfToken, $escape, $t): void {
    require __DIR__ . '/partials/moderation-decision.html.php';
};
?>

<section class="community-page moderation-page" aria-labelledby="moderation-heading">
    <header class="community-heading social-hero">
        <div>
            <p class="social-kicker">AniVibe</p>
            <h1 id="moderation-heading" class="social-title"><?= $escape($t('moderation.title')) ?></h1>
            <p class="social-description"><?= $escape($t('moderation.description')) ?></p>
        </div>
        <span class="social-pill"><?= $escape($listing['total']) ?></span>
    </header>

    <nav class="social-tabs moderation-tabs" aria-label="<?= $escape($t('moderation.title')) ?>">
        <?php foreach (['reports', 'comments', 'users', 'history'] as $section): ?>
            <a href="/moderation?tab=<?= $escape($section) ?>"<?= $section === $tab ? ' aria-current="page"' : '' ?>><?= $escape($t('moderation.' . $section)) ?></a>
        <?php endforeach; ?>
    </nav>

    <form class="community-panel community-form moderation-filters" method="get" action="/moderation" aria-label="<?= $escape($t('moderation.filters')) ?>">
        <input type="hidden" name="tab" value="<?= $escape($tab) ?>">
        <div class="community-field">
            <label for="moderation-author"><?= $escape($t('moderation.author')) ?></label>
            <input id="moderation-author" name="author" type="search" maxlength="200" value="<?= $escape($filters['author']) ?>">
        </div>
        <?php if ($tab !== 'users'): ?>
            <div class="community-field">
                <label for="moderation-anime"><?= $escape($t('moderation.anime')) ?></label>
                <input id="moderation-anime" name="anime" type="search" maxlength="200" value="<?= $escape($filters['anime']) ?>">
            </div>
        <?php endif; ?>
        <?php if (in_array($tab, ['reports', 'comments'], true)): ?>
            <div class="community-field">
                <label for="moderation-status"><?= $escape($t('moderation.status')) ?></label>
                <select id="moderation-status" name="status">
                    <?php foreach ($tab === 'reports' ? ['all', 'open', 'dismissed', 'removed'] : ['all', 'active', 'removed', 'deleted'] as $status): ?>
                        <option value="<?= $escape($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= $escape($t('moderation.' . $status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <button type="submit"><?= $escape($t('moderation.filter')) ?></button>
    </form>

    <?php if ($notice !== null): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <?php if ($listing['entries'] === []): ?>
        <p class="community-panel community-empty"><?= $escape($t('moderation.empty')) ?></p>
    <?php else: ?>
        <ol class="community-list" aria-label="<?= $escape($t('moderation.' . $tab)) ?>" data-moderation-total="<?= $escape($listing['total']) ?>"<?= $tab === 'reports' ? ' data-reports-total="' . $escape($listing['total']) . '"' : '' ?>>
            <?php foreach ($listing['entries'] as $entry): ?>
                <li>
                    <?php if (in_array($tab, ['reports', 'comments'], true)): ?>
                        <?php
                        $commentId = $tab === 'reports' ? $entry['commentId'] : $entry['id'];
                        $commentStatus = $tab === 'reports' ? $entry['commentStatus'] : $entry['status'];
                        $cardId = $tab . '-' . $entry['id'];
                        $threadId = $entry['parentId'] ?? $commentId;
                        ?>
                        <article class="community-panel moderation-card" data-comment-id="<?= $escape($commentId) ?>"<?= $tab === 'reports' ? ' data-report-id="' . $escape($entry['id']) . '"' : '' ?> aria-labelledby="title-<?= $escape($cardId) ?>">
                            <header class="community-heading moderation-card-heading">
                                <span class="moderation-kind"><?= $escape($t('moderation.' . $entry['status'])) ?></span>
                                <h2 id="title-<?= $escape($cardId) ?>"><?= $escape($t('moderation.comment')) ?> #<?= $escape($commentId) ?> · <?= $escape($entry['animeTitle']) ?></h2>
                                <p class="community-meta"><?= $escape($t('moderation.author')) ?>: <a href="/users/<?= $escape($entry['userId']) ?>"><?= $escape($entry['authorName']) ?></a></p>
                                <time class="community-meta" datetime="<?= $escape($entry['createdAt']) ?>"><?= $escape(ModerationNoticeFormatter::date($entry['createdAt'])) ?></time>
                            </header>
                            <blockquote class="community-content"><?= $escape($tab === 'reports' ? $entry['snapshot'] : $entry['body']) ?></blockquote>
                            <?php if ($tab === 'reports'): ?>
                                <p class="community-meta"><?= $escape($t('moderation.reporter')) ?>: <?= $escape($entry['reporterName']) ?></p>
                                <p class="community-content"><strong><?= $escape($t('moderation.report_reason')) ?>:</strong> <?= $escape($entry['reason']) ?></p>
                            <?php endif; ?>
                            <div class="moderation-links">
                                <a href="/anime/<?= $escape($entry['animeId']) ?>/comments/<?= $escape($threadId) ?>#comment-<?= $escape($commentId) ?>"><?= $escape($t('moderation.comment')) ?> #<?= $escape($commentId) ?></a>
                                <a href="/moderation?tab=users&amp;author=<?= $escape($entry['userId']) ?>#user-<?= $escape($entry['userId']) ?>"><?= $escape($t('moderation.ban')) ?></a>
                            </div>
                            <div class="moderation-actions">
                                <?php if ($commentStatus === 'active'): ?>
                                    <?php $decisionForm('/moderation/comments/' . $commentId . '/remove', $t('moderation.remove'), $cardId . '-remove', $tab === 'reports' ? ['reportId' => $entry['id']] : []); ?>
                                <?php endif; ?>
                                <?php $decisionForm('/moderation/comments/' . $commentId . '/warn', $t('moderation.warn'), $cardId . '-warn', ['userId' => $entry['userId']]); ?>
                                <?php if ($tab === 'reports' && $entry['status'] === 'open'): ?>
                                    <?php $decisionForm('/moderation/reports/' . $entry['id'] . '/dismiss', $t('moderation.dismiss'), $cardId . '-dismiss'); ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php elseif ($tab === 'users'): ?>
                        <article id="user-<?= $escape($entry['id']) ?>" class="community-panel moderation-card" data-user-id="<?= $escape($entry['id']) ?>" aria-labelledby="user-title-<?= $escape($entry['id']) ?>">
                            <h2 id="user-title-<?= $escape($entry['id']) ?>"><a href="/users/<?= $escape($entry['id']) ?>"><?= $escape($entry['name']) ?></a> #<?= $escape($entry['id']) ?></h2>
                            <?php if ($entry['isModerator']): ?>
                                <p class="moderation-kind"><?= $escape($t('moderation.moderator_account')) ?></p>
                            <?php endif; ?>
                            <?php if ($entry['activeBan'] !== null): ?>
                                <p><strong><?= $escape($t('moderation.active_ban')) ?></strong></p>
                                <p><?= $escape($t('moderation.ends')) ?>: <time datetime="<?= $escape($entry['activeBan']['endsAt']) ?>"><?= $escape(ModerationNoticeFormatter::date($entry['activeBan']['endsAt'])) ?></time></p>
                                <p class="community-content"><?= $escape($entry['activeBan']['reason']) ?></p>
                                <?php if ($entry['id'] !== $accountId): ?>
                                    <?php $decisionForm('/moderation/bans/' . $entry['activeBan']['id'] . '/revoke', $t('moderation.revoke'), 'ban-' . $entry['activeBan']['id'] . '-revoke'); ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="community-meta"><?= $escape($t('moderation.no_active_ban')) ?></p>
                                <?php if (!$entry['isModerator'] && $entry['id'] !== $accountId): ?>
                                    <?php $decisionForm('/moderation/users/' . $entry['id'] . '/ban', $t('moderation.ban'), 'user-' . $entry['id'] . '-ban', [], true); ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </article>
                    <?php else: ?>
                        <article class="community-panel moderation-card" data-action-id="<?= $escape($entry['id']) ?>">
                            <h2><?= $escape($t('moderation.decision.' . $entry['actionType'])) ?> #<?= $escape($entry['id']) ?></h2>
                            <dl class="moderation-details">
                                <dt><?= $escape($t('moderation.user')) ?></dt><dd><?= $escape($entry['userName'] ?? '—') ?></dd>
                                <dt><?= $escape($t('moderation.moderator')) ?></dt><dd><?= $escape($entry['moderatorName'] ?? '—') ?></dd>
                                <dt><?= $escape($t('moderation.created')) ?></dt><dd><time datetime="<?= $escape($entry['createdAt']) ?>"><?= $escape(ModerationNoticeFormatter::date($entry['createdAt'])) ?></time></dd>
                                <?php if ($entry['animeTitle'] !== null): ?>
                                    <dt><?= $escape($t('moderation.anime')) ?></dt><dd><?= $escape($entry['animeTitle']) ?></dd>
                                <?php endif; ?>
                                <?php if ($entry['endsAt'] !== null): ?>
                                    <dt><?= $escape($t('moderation.ends')) ?></dt><dd><?= $escape(ModerationNoticeFormatter::date($entry['endsAt'])) ?></dd>
                                <?php endif; ?>
                            </dl>
                            <p class="community-content"><strong><?= $escape($t('moderation.reason')) ?>:</strong> <?= $escape($entry['reason']) ?></p>
                        </article>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($listing['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="<?= $escape($t('moderation.' . $tab)) ?>">
            <?php if ($listing['previousUrl'] !== null): ?><a href="<?= $escape($listing['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous')) ?></a><?php endif; ?>
            <span><?= $escape($t('view.social.page_of', ['page' => $listing['page'], 'total' => $listing['totalPages']])) ?></span>
            <?php if ($listing['nextUrl'] !== null): ?><a href="<?= $escape($listing['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
