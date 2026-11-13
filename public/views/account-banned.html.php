<?php
use App\Service\ModerationNoticeFormatter;

require __DIR__ . '/partials/header.html.php';
?>

<section class="community-page moderation-page account-ban-page" aria-labelledby="account-ban-heading">
    <header class="community-heading social-hero">
        <div>
            <h1 id="account-ban-heading" class="social-title"><?= $escape($t('moderation.banned.title')) ?></h1>
            <p class="social-description"><?= $escape($t('moderation.banned.description')) ?></p>
        </div>
    </header>
    <div class="community-panel moderation-card" data-active-ban-id="<?= $escape($ban['id']) ?>">
        <dl class="moderation-details">
            <dt><?= $escape($t('moderation.banned.ends')) ?></dt>
            <dd><time datetime="<?= $escape($ban['endsAt']) ?>"><?= $escape(ModerationNoticeFormatter::date($ban['endsAt'])) ?></time></dd>
            <dt><?= $escape($t('moderation.banned.reason')) ?></dt>
            <dd class="community-content"><?= $escape($ban['reason']) ?></dd>
        </dl>
        <p><a href="/account/notices"><?= $escape($t('moderation.notices.title')) ?></a></p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
