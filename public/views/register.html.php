<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="auth-page auth-page-register" aria-labelledby="register-title">
    <a href="/login" class="auth-back">
        <span aria-hidden="true">←</span>
        <?= $escape($t('view.interface.back_to_sign_in')) ?>
    </a>

    <div class="auth-panel">
        <header class="auth-heading">
            <p class="auth-kicker"><span class="auth-status-dot" aria-hidden="true"></span> <?= $escape($t('view.interface.your_adventure_begins')) ?></p>
            <h1 id="register-title"><?= $escape($t('view.interface.create_an_anivibe_account')) ?></h1>
            <p id="register-info"><?= $escape($t('view.interface.complete_the_form_to_access_projects')) ?></p>
        </header>

        <?php if (($errors ?? []) !== []): ?>
            <div class="auth-notice" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $escape($translateText($error)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form action="/register" method="post" class="auth-form" aria-describedby="register-info">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">

            <fieldset class="auth-fields">
                <legend class="auth-visually-hidden"><?= $escape($t('view.interface.account_details')) ?></legend>

                <div class="auth-field">
                    <label for="register-name"><?= $escape($t('view.interface.full_name')) ?></label>
                    <input
                        id="register-name"
                        name="name"
                        type="text"
                        autocomplete="name"
                        maxlength="120"
                        value="<?= $escape($form['name'] ?? '') ?>"
                        placeholder="<?= $escape($t('view.interface.what_s_your_name')) ?>"
                        required
                    >
                </div>

                <div class="auth-field">
                    <label for="register-email"><?= $escape($t('view.interface.email')) ?></label>
                    <input
                        id="register-email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        maxlength="255"
                        value="<?= $escape($form['email'] ?? '') ?>"
                        placeholder="<?= $escape($t('view.interface.e_g_otaku_example_com')) ?>"
                        required
                    >
                </div>

                <div class="auth-field">
                    <label for="register-password"><?= $escape($t('view.interface.password')) ?></label>
                    <input
                        id="register-password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        minlength="8"
                        aria-describedby="register-password-help"
                        placeholder="<?= $escape($t('view.interface.at_least_8_characters')) ?>"
                        required
                    >
                    <p id="register-password-help" class="auth-help"><?= $escape($t('view.interface.at_least_8_characters_up_to_72_bytes')) ?></p>
                </div>

                <div class="auth-field">
                    <label for="register-password-confirmation"><?= $escape($t('view.interface.confirm_password')) ?></label>
                    <input
                        id="register-password-confirmation"
                        name="passwordConfirmation"
                        type="password"
                        autocomplete="new-password"
                        minlength="8"
                        placeholder="<?= $escape($t('view.interface.enter_your_password_again')) ?>"
                        required
                    >
                </div>

                <button type="submit" class="auth-submit"><span aria-hidden="true">→</span> <?= $escape($t('view.interface.sign_up')) ?></button>
            </fieldset>
        </form>

        <div class="auth-register">
            <p><?= $escape($t('view.interface.already_have_an_account')) ?></p>
            <a href="/login" class="auth-text-button"><?= $escape($t('view.interface.sign_in')) ?></a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
