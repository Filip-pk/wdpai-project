<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="auth-page" aria-labelledby="register-title">
    <a href="/login" class="auth-back">Wróć do logowania</a>

    <div class="auth-panel">
        <header class="auth-heading">
            <h1 id="register-title">Utwórz konto AniVibe</h1>
            <p id="register-info">Wypełnij formularz, aby uzyskać dostęp do projektów.</p>
        </header>

        <?php if (($errors ?? []) !== []): ?>
            <div class="auth-notice" role="alert">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= $escape($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form action="/register" method="post" class="auth-form" aria-describedby="register-info">
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">

            <fieldset class="auth-fields">
                <legend>Dane konta</legend>

                <div class="auth-field">
                    <label for="register-name">Imię i nazwisko</label>
                    <input
                        id="register-name"
                        name="name"
                        type="text"
                        autocomplete="name"
                        maxlength="120"
                        value="<?= $escape($form['name'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="auth-field">
                    <label for="register-email">E-mail</label>
                    <input
                        id="register-email"
                        name="email"
                        type="email"
                        autocomplete="email"
                        maxlength="255"
                        value="<?= $escape($form['email'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="auth-field">
                    <label for="register-password">Hasło</label>
                    <input
                        id="register-password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        minlength="8"
                        aria-describedby="register-password-help"
                        required
                    >
                    <p id="register-password-help" class="auth-help">Co najmniej 8 znaków, maksymalnie 72 bajty.</p>
                </div>

                <div class="auth-field">
                    <label for="register-password-confirmation">Powtórz hasło</label>
                    <input
                        id="register-password-confirmation"
                        name="passwordConfirmation"
                        type="password"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >
                </div>

                <button type="submit" class="auth-submit">Zarejestruj się</button>
            </fieldset>
        </form>

        <p>Masz już konto? <a href="/login">Zaloguj się</a></p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
