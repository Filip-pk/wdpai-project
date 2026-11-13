<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="settings-page" aria-labelledby="email-confirm-title">
    <header class="settings-heading">
        <h1 id="email-confirm-title">Potwierdź nowy adres e-mail</h1>
        <a href="/settings#security">← Konto i bezpieczeństwo</a>
    </header>
    <div class="settings-card">
        <?php if (is_string($_SESSION['security_success'] ?? null)): ?>
            <p class="settings-notice" role="status" data-kind="success"><?= $escape($_SESSION['security_success']) ?></p>
            <?php unset($_SESSION['security_success']); ?>
        <?php endif; ?>
        <?php if ($securityErrors !== []): ?>
            <div class="settings-notice settings-error" role="alert" data-kind="error">
                <ul>
                    <?php foreach ($securityErrors as $error): ?>
                        <li><?= $escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($pendingEmail !== null): ?>
            <p>Wpisz kod wysłany na <strong><?= $escape($pendingEmail) ?></strong>. Do zatwierdzenia zmiany loguj się dotychczasowym adresem.</p>
        <?php else: ?>
            <p>Nie ma aktywnej zmiany adresu. Jeśli kod wygasł, wyślij nowy w ustawieniach konta.</p>
        <?php endif; ?>
        <form method="post" action="/settings/email/confirm" class="settings-form">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <div class="settings-field">
                <label for="settings-email-code">Kod potwierdzenia</label>
                <input id="settings-email-code" name="confirmationCode" type="text" autocomplete="one-time-code" spellcheck="false" autocapitalize="none" minlength="32" maxlength="32" pattern="[a-f0-9]{32}" required>
            </div>
            <button type="submit" class="settings-save">Potwierdź i zmień e-mail</button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
