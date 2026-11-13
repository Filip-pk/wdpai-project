<section class="settings-card" id="security" aria-labelledby="settings-security-title">
    <header>
        <h2 id="settings-security-title"><?= $escape($t('view.interface.account_and_security')) ?></h2>
        <p><?= $escape($t('view.interface.current_email_address')) ?> <strong><?= $escape((string) ($_SESSION['user_email'] ?? '')) ?></strong></p>
    </header>

    <?php if (($securityErrors ?? []) !== []): ?>
        <div class="settings-notice settings-error" role="alert" data-kind="error">
            <ul>
                <?php foreach ($securityErrors as $error): ?>
                    <li><?= $escape($translateText($error)) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (is_string($_SESSION['security_success'] ?? null)): ?>
        <p class="settings-notice" role="status" data-kind="success"><?= $escape($translateText($_SESSION['security_success'])) ?></p>
        <?php unset($_SESSION['security_success']); ?>
    <?php endif; ?>

    <form method="post" action="/settings/password" class="settings-form">
        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
        <fieldset class="settings-security-fields">
            <legend><?= $escape($t('view.interface.change_password')) ?></legend>
            <div class="settings-field">
                <label for="settings-password-current"><?= $escape($t('view.interface.current_password')) ?></label>
                <input id="settings-password-current" name="currentPassword" type="password" autocomplete="current-password" required>
            </div>
            <div class="settings-field">
                <label for="settings-password-new"><?= $escape($t('view.interface.new_password')) ?></label>
                <input id="settings-password-new" name="newPassword" type="password" autocomplete="new-password" aria-describedby="settings-password-help" required>
                <p id="settings-password-help"><?= $escape($t('view.interface.at_least_8_characters_and_up_to_72_bytes_accented_characters_use_more_than_one_byte')) ?></p>
            </div>
            <div class="settings-field">
                <label for="settings-password-confirm"><?= $escape($t('view.interface.confirm_new_password')) ?></label>
                <input id="settings-password-confirm" name="newPasswordConfirmation" type="password" autocomplete="new-password" required>
            </div>
            <button type="submit" class="settings-save"><?= $escape($t('view.interface.change_password')) ?></button>
        </fieldset>
    </form>

    <form method="post" action="/settings/email" class="settings-form">
        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
        <fieldset class="settings-security-fields">
            <legend><?= $escape($t('view.interface.change_email_address')) ?></legend>
            <p><?= $escape($t('view.interface.we_ll_send_a_code_to_the_new_address_your_email_will_change_only_after_you_enter_the_code_')) ?></p>
            <div class="settings-field">
                <label for="settings-email-new"><?= $escape($t('view.interface.new_email_address')) ?></label>
                <input id="settings-email-new" name="newEmail" type="email" value="<?= $escape($newEmail ?? '') ?>" maxlength="255" autocomplete="email" required>
            </div>
            <div class="settings-field">
                <label for="settings-email-confirm"><?= $escape($t('view.interface.confirm_new_email_address')) ?></label>
                <input id="settings-email-confirm" name="newEmailConfirmation" type="email" maxlength="255" autocomplete="email" required>
            </div>
            <div class="settings-field">
                <label for="settings-email-password"><?= $escape($t('view.interface.current_password')) ?></label>
                <input id="settings-email-password" name="currentPassword" type="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="settings-save"><?= $escape($t('view.interface.send_confirmation_code')) ?></button>
            <a href="/settings/email/confirm"><?= $escape($t('view.interface.confirm_email_with_your_code')) ?></a>
        </fieldset>
    </form>
</section>
