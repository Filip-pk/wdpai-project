<section class="settings-card" id="security" aria-labelledby="settings-security-title">
    <header>
        <h2 id="settings-security-title">Konto i bezpieczeństwo</h2>
        <p>Aktualny adres e-mail: <strong><?= $escape((string) ($_SESSION['user_email'] ?? '')) ?></strong></p>
    </header>

    <?php if (($securityErrors ?? []) !== []): ?>
        <div class="settings-notice settings-error" role="alert" data-kind="error">
            <ul>
                <?php foreach ($securityErrors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (is_string($_SESSION['security_success'] ?? null)): ?>
        <p class="settings-notice" role="status" data-kind="success"><?= $escape($_SESSION['security_success']) ?></p>
        <?php unset($_SESSION['security_success']); ?>
    <?php endif; ?>

    <form method="post" action="/settings/password" class="settings-form">
        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
        <fieldset class="settings-security-fields">
            <legend>Zmień hasło</legend>
            <div class="settings-field">
                <label for="settings-password-current">Obecne hasło</label>
                <input id="settings-password-current" name="currentPassword" type="password" autocomplete="current-password" required>
            </div>
            <div class="settings-field">
                <label for="settings-password-new">Nowe hasło</label>
                <input id="settings-password-new" name="newPassword" type="password" autocomplete="new-password" aria-describedby="settings-password-help" required>
                <p id="settings-password-help">Co najmniej 8 znaków i maksymalnie 72 bajty. Polskie znaki zajmują więcej niż jeden bajt.</p>
            </div>
            <div class="settings-field">
                <label for="settings-password-confirm">Powtórz nowe hasło</label>
                <input id="settings-password-confirm" name="newPasswordConfirmation" type="password" autocomplete="new-password" required>
            </div>
            <button type="submit" class="settings-save">Zmień hasło</button>
        </fieldset>
    </form>

    <form method="post" action="/settings/email" class="settings-form">
        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
        <fieldset class="settings-security-fields">
            <legend>Zmień adres e-mail</legend>
            <p>Wyślemy kod na nowy adres. E-mail zmieni się dopiero po wpisaniu tego kodu w formularzu potwierdzenia. Kod jest ważny przez 30 minut.</p>
            <div class="settings-field">
                <label for="settings-email-new">Nowy adres e-mail</label>
                <input id="settings-email-new" name="newEmail" type="email" value="<?= $escape($newEmail ?? '') ?>" maxlength="255" autocomplete="email" required>
            </div>
            <div class="settings-field">
                <label for="settings-email-confirm">Powtórz nowy adres e-mail</label>
                <input id="settings-email-confirm" name="newEmailConfirmation" type="email" maxlength="255" autocomplete="email" required>
            </div>
            <div class="settings-field">
                <label for="settings-email-password">Obecne hasło</label>
                <input id="settings-email-password" name="currentPassword" type="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="settings-save">Wyślij kod potwierdzenia</button>
            <a href="/settings/email/confirm">Potwierdź e-mail otrzymanym kodem</a>
        </fieldset>
    </form>
</section>
