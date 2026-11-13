<?php
use App\Service\ModerationNoticeFormatter;

require __DIR__ . '/partials/header.html.php';
?>

<section class="community-page moderation-page" aria-labelledby="moderation-notices-heading" data-notices-total="<?= $escape($notices['total']) ?>">
    <header class="community-heading social-hero">
        <div>
            <h1 id="moderation-notices-heading" class="social-title"><?= $escape($t('moderation.notices.title')) ?></h1>
            <p class="social-description"><?= $escape($t('moderation.notices.description')) ?></p>
        </div>
    </header>
    <?php if ($restrictedAccount): ?>
        <p><a href="/account/banned"><?= $escape($t('moderation.banned.title')) ?></a></p>
    <?php endif; ?>
    <?php if ($notices['entries'] === []): ?>
        <p class="community-panel community-empty"><?= $escape($t('moderation.notices.empty')) ?></p>
    <?php else: ?>
        <ol class="community-list" aria-label="<?= $escape($t('moderation.notices.title')) ?>">
            <?php foreach ($notices['entries'] as $entry): ?>
                <li>
                    <article class="community-panel moderation-card" data-moderation-notice-id="<?= $escape($entry['id']) ?>" data-notice-read="<?= $entry['readAt'] === null ? '0' : '1' ?>">
                        <h2><?= $escape($t('moderation.decision.' . $entry['actionType'])) ?></h2>
                        <p class="community-content"><?= $escape(ModerationNoticeFormatter::message($entry)) ?></p>
                        <p class="community-meta">
                            <?= $escape($t($entry['readAt'] === null ? 'moderation.notices.unread' : 'moderation.notices.read')) ?> ·
                            <time datetime="<?= $escape($entry['createdAt']) ?>"><?= $escape(ModerationNoticeFormatter::date($entry['createdAt'])) ?></time>
                        </p>
                        <?php if ($entry['readAt'] === null): ?>
                            <form class="community-inline-form" method="post" action="/account/notices/<?= $escape($entry['id']) ?>/read">
                                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="page" value="<?= $escape($notices['page']) ?>">
                                <button type="submit"><?= $escape($t('moderation.notices.acknowledge')) ?></button>
                            </form>
                        <?php endif; ?>
                    </article>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
    <?php if ($notices['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="<?= $escape($t('moderation.notices.title')) ?>">
            <?php if ($notices['previousUrl'] !== null): ?><a href="<?= $escape($notices['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous')) ?></a><?php endif; ?>
            <span><?= $escape($t('view.social.page_of', ['page' => $notices['page'], 'total' => $notices['totalPages']])) ?></span>
            <?php if ($notices['nextUrl'] !== null): ?><a href="<?= $escape($notices['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
