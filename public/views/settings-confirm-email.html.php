<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="settings-page" aria-labelledby="email-confirm-title">
    <header class="settings-heading">
        <h1 id="email-confirm-title"><?= $escape($t('view.interface.confirm_your_new_email_address')) ?></h1>
        <a href="/settings#security"><?= $escape($t('view.interface.account_and_security_641fb5')) ?></a>
    </header>
    <div class="settings-card">
        <?php if (is_string($_SESSION['security_success'] ?? null)): ?>
            <p class="settings-notice" role="status" data-kind="success"><?= $escape($translateText($_SESSION['security_success'])) ?></p>
            <?php unset($_SESSION['security_success']); ?>
        <?php endif; ?>
        <?php if ($securityErrors !== []): ?>
            <div class="settings-notice settings-error" role="alert" data-kind="error">
                <ul>
                    <?php foreach ($securityErrors as $error): ?>
                        <li><?= $escape($translateText($error)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($pendingEmail !== null): ?>
            <p><?= $escape($t('view.interface.enter_the_code_sent_to')) ?> <strong><?= $escape($pendingEmail) ?></strong><?= $escape($t('view.interface.until_the_change_is_confirmed_sign_in_with_your_existing_address')) ?></p>
        <?php else: ?>
            <p><?= $escape($t('view.interface.there_is_no_pending_email_change_if_your_code_expired_request_a_new_one_in_account_setting')) ?></p>
        <?php endif; ?>
        <form method="post" action="/settings/email/confirm" class="settings-form">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <div class="settings-field">
                <label for="settings-email-code"><?= $escape($t('view.interface.confirmation_code')) ?></label>
                <input id="settings-email-code" name="confirmationCode" type="text" autocomplete="one-time-code" spellcheck="false" autocapitalize="none" minlength="32" maxlength="32" pattern="[a-f0-9]{32}" required>
            </div>
            <button type="submit" class="settings-save"><?= $escape($t('view.interface.confirm_and_change_email')) ?></button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
