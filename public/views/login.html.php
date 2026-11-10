<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="auth-page auth-page-login" aria-labelledby="login-title">
    <a href="/" class="auth-back">
        <span aria-hidden="true">←</span>
        Wróć do strony głównej
    </a>

    <div class="auth-card">
        <aside class="auth-showcase" aria-labelledby="showcase-title">
            <img
                class="auth-image"
                src="<?= $assetUrl('/img/neo-tokyo-guide.jpg') ?>"
                alt=""
                fetchpriority="high"
                decoding="async"
            >

            <div class="auth-showcase-content">
                <p class="auth-kicker">
                    <span aria-hidden="true">🌸</span>
                    AniVibe Neural Core
                </p>

                <h2 id="showcase-title">Połącz z cyberświatem</h2>

                <p class="auth-showcase-description">
                    Twoje anime. Twoje historie.
                    Nowy wymiar odkrywania.
                </p>
            </div>
        </aside>

        <div class="auth-panel">
            <header class="auth-heading">
                <p class="auth-kicker"><span class="auth-status-dot" aria-hidden="true"></span> Twoja przygoda trwa dalej</p>

                <h1 id="login-title">
                    Witaj z powrotem!
                    <span aria-hidden="true">👋</span>
                </h1>

                <p>
                    Zaloguj się, aby rozmawiać z postaciami
                    i odkrywać anime z AI.
                </p>
            </header>

            <p id="login-info" class="auth-info">
                Zaloguj się adresem e-mail i hasłem swojego konta.
            </p>

            <?php if ($registered ?? false): ?>
                <p class="auth-notice" role="status">
                    Konto zostało utworzone. Możesz się zalogować.
                </p>
            <?php endif; ?>

            <?php if (($mailStatus ?? null) === 'sent'): ?>
                <p class="auth-notice" role="status" data-mail-status="sent">
                    Wysłaliśmy wiadomość powitalną na podany adres e-mail.
                </p>
            <?php elseif (($mailStatus ?? null) === 'failed'): ?>
                <p class="auth-notice auth-notice--warning" role="status" data-mail-status="failed">
                    Nie udało się wysłać wiadomości powitalnej. Konto jest aktywne i możesz się zalogować.
                </p>
            <?php endif; ?>

            <?php if (($errors ?? []) !== []): ?>
                <div class="auth-notice" role="alert">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= $escape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form
                action="/login"
                method="post"
                class="auth-form"
                aria-describedby="login-info"
            >
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">

                <fieldset class="auth-fields">
                    <legend class="auth-visually-hidden">
                        Dane logowania
                    </legend>

                    <div class="auth-field">
                        <label for="login-email">
                            E-mail
                        </label>

                        <input
                            id="login-email"
                            name="email"
                            type="email"
                            autocomplete="username"
                            maxlength="255"
                            value="<?= $escape($email ?? '') ?>"
                            placeholder="np. otaku@example.com"
                            required
                        >
                    </div>

                    <div class="auth-field">
                        <label for="login-password">
                            Hasło
                        </label>

                        <input
                            id="login-password"
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Wpisz swoje hasło"
                            required
                        >
                    </div>

                    <div class="auth-password-help">
                        <button
                            type="button"
                            class="auth-text-button"
                            disabled
                        >
                            Nie pamiętasz hasła?
                        </button>
                    </div>

                    <button
                        type="submit"
                        class="auth-submit"
                    >
                        <span aria-hidden="true">→</span>
                        Zaloguj się
                    </button>
                </fieldset>
            </form>

            <div class="auth-register">
                <p>Nie masz jeszcze konta?</p>

                <a
                    href="/register"
                    class="auth-text-button"
                >
                    Zarejestruj się
                </a>
            </div>

            <a href="/dashboard" class="auth-demo-link">
                Zobacz przykładowy pulpit
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
