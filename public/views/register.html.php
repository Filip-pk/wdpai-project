<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="auth-page auth-page-register" aria-labelledby="register-title">
    <a href="/login" class="auth-back">
        <span aria-hidden="true">←</span>
        Wróć do logowania
    </a>

    <div class="auth-panel">
        <header class="auth-heading">
            <p class="auth-kicker"><span class="auth-status-dot" aria-hidden="true"></span> Początek Twojej przygody</p>
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
                <legend class="auth-visually-hidden">Dane konta</legend>

                <div class="auth-field">
                    <label for="register-name">Imię i nazwisko</label>
                    <input
                        id="register-name"
                        name="name"
                        type="text"
                        autocomplete="name"
                        maxlength="120"
                        value="<?= $escape($form['name'] ?? '') ?>"
                        placeholder="Jak się nazywasz?"
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
                        placeholder="np. otaku@example.com"
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
                        placeholder="Co najmniej 8 znaków"
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
                        placeholder="Wpisz hasło ponownie"
                        required
                    >
                </div>

                <button type="submit" class="auth-submit"><span aria-hidden="true">→</span> Zarejestruj się</button>
            </fieldset>
        </form>

        <div class="auth-register">
            <p>Masz już konto?</p>
            <a href="/login" class="auth-text-button">Zaloguj się</a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
