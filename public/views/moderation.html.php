<?php
require __DIR__ . '/partials/header.html.php';

$reportDate = static function (string $date): string {
    try {
        return (new DateTimeImmutable($date))
            ->setTimezone(new DateTimeZone('Europe/Warsaw'))
            ->format('d.m.Y, H:i');
    } catch (Throwable) {
        return $date;
    }
};
?>

<section class="community-page moderation-page" aria-labelledby="moderation-heading">
    <header class="community-heading social-hero">
        <div>
            <p class="social-kicker"><?= $escape($t('view.interface.moderation_panel')) ?></p>
            <h1 id="moderation-heading" class="social-title"><?= $escape($t('view.interface.reports')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.hiding_content_leaves_a_moderation_notice')) ?></p>
        </div>
        <span class="social-pill"><?= $escape($t('view.interface.pending_reports')) ?> <?= $escape($reports['total']) ?>.</span>
    </header>

    <aside class="moderation-guide" aria-label="<?= $escape($t('view.interface.reviewing_reports')) ?>">
        <span class="moderation-guide-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m12 3 8 3v6c0 4-8 9-8 9s-8-5-8-9V6Z"/><path d="m8 12 3 3 5-6"/></svg>
        </span>
        <div>
            <h2><?= $escape($t('view.interface.review_the_content_and_report_reason')) ?></h2>
            <p><?= $escape($t('view.interface.hide_content_that_breaks_the_rules_or_dismiss_the_report_the_decision_applies_to_the_speci')) ?></p>
        </div>
    </aside>

    <?php if (($notice ?? '') !== ''): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($translateText($notice)) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div id="moderation-errors" class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($translateText($error)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($reports['entries'] === []): ?>
        <div class="community-panel community-empty social-empty">
            <span class="social-empty-icon" aria-hidden="true">✓</span>
            <h2><?= $escape($t('view.interface.no_reports_to_review')) ?></h2>
            <p><?= $escape($t('view.interface.new_comment_and_message_reports_will_appear_here')) ?></p>
            <a href="/dashboard"><?= $escape($t('view.interface.back_to_dashboard')) ?> <span aria-hidden="true">→</span></a>
        </div>
    <?php else: ?>
        <ol class="community-list" aria-label="<?= $escape($t('view.interface.reports_awaiting_review')) ?>" data-reports-total="<?= $escape($reports['total']) ?>">
            <?php foreach ($reports['entries'] as $report): ?>
                <li>
                    <article class="community-panel moderation-card" data-report-id="<?= $escape($report['id']) ?>" aria-labelledby="report-title-<?= $escape($report['id']) ?>">
                        <header class="community-heading moderation-card-heading">
                            <span class="moderation-kind"><?= $escape($report['targetType'] === 'comment' ? $t('view.interface.public_discussion') : $t('view.interface.reported_message')) ?></span>
                            <h2 id="report-title-<?= $escape($report['id']) ?>"><?= $escape($report['targetType'] === 'comment' ? $t('view.interface.comment') : $t('view.interface.private_message_03012b')) ?> <?= $escape($t('view.interface.report_8873fe')) ?><?= $escape($report['id']) ?></h2>
                            <p class="community-meta"><?= $escape($t('view.interface.content_author')) ?> <?= $escape($report['authorName']) ?></p>
                            <p class="community-meta"><?= $escape($t('view.interface.reported_by')) ?> <?= $escape($report['reporterName']) ?> · <time datetime="<?= $escape($report['createdAt']) ?>"><?= $escape($reportDate($report['createdAt'])) ?></time></p>
                        </header>
                        <div class="moderation-content-grid">
                            <section class="community-report-content" aria-labelledby="report-content-<?= $escape($report['id']) ?>">
                                <h3 id="report-content-<?= $escape($report['id']) ?>"><?= $escape($t('view.interface.reported_content_007bb5')) ?></h3>
                                <blockquote class="community-content"><?= $escape($report['snapshot']) ?></blockquote>
                            </section>
                            <section class="community-report-content" aria-labelledby="report-reason-<?= $escape($report['id']) ?>">
                                <h3 id="report-reason-<?= $escape($report['id']) ?>"><?= $escape($t('view.interface.reason_for_report')) ?></h3>
                                <p class="community-content"><?= $escape($report['reason']) ?></p>
                            </section>
                        </div>
                        <form class="community-form community-resolve-form" method="post" action="/moderation/reports/<?= $escape($report['id']) ?>/resolve" aria-label="<?= $escape($t('view.interface.review_report') . $report['id']) ?>">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="page" value="<?= $escape($reports['page']) ?>">
                            <div class="community-field">
                                <label for="report-action-<?= $escape($report['id']) ?>"><?= $escape($t('view.interface.moderator_decision')) ?></label>
                                <select id="report-action-<?= $escape($report['id']) ?>" name="action" required<?= $errors !== [] ? ' aria-describedby="moderation-errors" aria-invalid="true"' : '' ?>>
                                    <option value="" selected disabled><?= $escape($t('view.interface.choose_a_decision')) ?></option>
                                    <option value="remove"><?= $escape($t('view.interface.hide_content')) ?></option>
                                    <option value="dismiss"><?= $escape($t('view.interface.dismiss_report')) ?></option>
                                </select>
                            </div>
                            <button type="submit"><?= $escape($t('view.interface.confirm_decision')) ?></button>
                        </form>
                    </article>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($reports['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="<?= $escape($t('view.interface.report_pages')) ?>">
            <?php if ($reports['previousUrl'] !== null): ?>
                <a href="<?= $escape($reports['previousUrl']) ?>" rel="prev"><?= $escape($t('view.interface.previous')) ?></a>
            <?php endif; ?>
            <span><?= $escape($t('view.social.page_of', ['page' => $reports['page'], 'total' => $reports['totalPages']])) ?></span>
            <?php if ($reports['nextUrl'] !== null): ?>
                <a href="<?= $escape($reports['nextUrl']) ?>" rel="next"><?= $escape($t('view.interface.next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
