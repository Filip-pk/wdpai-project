<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page community-report-page" aria-labelledby="report-heading">
    <header class="community-heading social-hero">
        <div class="community-heading-copy">
            <p class="social-kicker"><?= $escape($t('view.interface.a_safe_community')) ?></p>
            <h1 id="report-heading" class="social-title"><?= $escape($t($target['targetType'] === 'comment' ? 'view.social.report_comment' : 'view.interface.report_message')) ?></h1>
            <p class="social-description"><?= $escape($t('view.interface.a_moderator_will_receive_the_reported_content_and_your_reason')) ?></p>
        </div>
        <span class="social-pill"><?= $escape($target['targetType'] === 'comment' ? $t('view.interface.comment') : $t('view.interface.private_message')) ?></span>
    </header>

    <a class="community-back" href="<?= $escape($backUrl) ?>">← <?= $escape($t($target['targetType'] === 'comment' ? 'view.interface.back_to_discussion' : 'view.social.back_to_conversation')) ?></a>

    <?php if ($errors !== []): ?>
        <div id="report-errors" class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($translateText($error)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="report-layout">
        <section class="community-panel report-preview" aria-labelledby="reported-content-heading">
            <p class="report-step"><?= $escape($t('view.interface.01_reported_content')) ?></p>
            <h2 id="reported-content-heading"><?= $escape($t('view.interface.reported_content')) ?></h2>
            <p class="community-meta"><?= $escape($t('view.interface.author')) ?> <?= $escape($target['authorName']) ?></p>
            <blockquote class="community-content"><?= $escape($target['body']) ?></blockquote>
            <?php if ($target['targetType'] === 'message'): ?>
                <p class="report-privacy"><span aria-hidden="true">◇</span> <?= $escape($t('view.interface.other_messages_in_the_conversation_will_remain_private')) ?></p>
            <?php endif; ?>
        </section>

        <form class="community-panel community-form report-form" method="post" action="/reports/<?= $escape($target['targetType']) ?>/<?= $escape($target['targetId']) ?>" aria-labelledby="report-form-heading">
            <p class="report-step"><?= $escape($t('view.interface.02_your_report')) ?></p>
            <h2 id="report-form-heading"><?= $escape($t('view.interface.report_details')) ?></h2>
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="returnPage" value="<?= $escape($returnPage) ?>">
            <?php if ($returnThread !== null): ?>
                <input type="hidden" name="returnThread" value="<?= $escape($returnThread) ?>">
                <input type="hidden" name="commentsPage" value="<?= $escape($mainCommentsPage) ?>">
                <input type="hidden" name="repliesPage" value="<?= $escape($repliesPage) ?>">
            <?php endif; ?>
            <div class="community-field">
                <label for="report-reason"><?= $escape($t('view.interface.reason_for_report')) ?></label>
                <p id="report-reason-help"><?= $escape($t('view.interface.describe_the_rule_violation_up_to_1_000_characters')) ?></p>
                <textarea id="report-reason" name="reason" rows="5" maxlength="1000" required aria-describedby="report-reason-help<?= $errors !== [] ? ' report-errors' : '' ?>"<?= $errors !== [] ? ' aria-invalid="true"' : '' ?>><?= $escape($reason) ?></textarea>
            </div>
            <div class="community-actions">
                <button type="submit"><?= $escape($t('view.interface.submit_report')) ?></button>
                <a href="<?= $escape($backUrl) ?>"><?= $escape($t('view.interface.cancel_and_go_back')) ?></a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
