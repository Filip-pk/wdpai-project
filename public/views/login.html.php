<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="auth-page" aria-labelledby="login-title">
    <a href="/" class="auth-back">
        <span aria-hidden="true">←</span>
        Wróć do strony głównej
    </a>

    <div class="auth-card">
        <aside class="auth-showcase" aria-labelledby="showcase-title">
            <img
                class="auth-image"
                src="https://lh3.googleusercontent.com/aida-public/AB6AXuCwTAlUwptX9haUuyqwZqeefs8ohDyVw4xfhdc5FUsrkEM5OmZ_7RQqkeSjkzjVAq3yfL0IsJk6MUhjjRlrUtgvA0X9fMydWSCZMknzjqDgWZLr6EvBV_aPF6g-ECwZJFFnsoltLlTVD2O7cFi_FTtDpDgSHbWdqM-kT9hCybq0ZwdWx_f___4PUwUb8ZzT6O5h4iVSi4WYmCUEqyKjm-UYLVKg4OTH9-LARrcWViuVVOYW0TSpolD6GA"
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
                <p class="auth-kicker">Twoja przygoda trwa dalej</p>

                <h1 id="login-title">
                    Witaj z powrotem!
                    <span aria-hidden="true">👋</span>
                </h1>

                <p>
                    Zaloguj się, aby rozmawiać z postaciami
                    i odkrywać anime z AI.
                </p>
            </header>

            <p id="login-info" class="auth-notice">
                Podgląd formularza — logowanie dodamy
                w kolejnym etapie.
            </p>

            <form
                action="/login"
                method="post"
                class="auth-form"
                aria-describedby="login-info"
            >
                <fieldset class="auth-fields" disabled>
                    <legend class="auth-visually-hidden">
                        Dane logowania
                    </legend>

                    <div class="auth-field">
                        <label for="login-identifier">
                            E-mail lub nazwa użytkownika
                        </label>

                        <input
                            id="login-identifier"
                            name="identifier"
                            type="text"
                            autocomplete="username"
                            maxlength="254"
                            placeholder="np. cyber_otaku"
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
                        disabled
                    >
                        <span aria-hidden="true">↗</span>
                        Zaloguj się
                    </button>
                </fieldset>
            </form>

            <section
                class="auth-social"
                aria-labelledby="alternative-login-title"
            >
                <h2 id="alternative-login-title" class="auth-divider">
                    Lub zaloguj się przez
                </h2>

                <div class="auth-social-actions">
                    <button
                        type="button"
                        class="auth-provider"
                        disabled
                        aria-describedby="providers-info"
                    >
                        <span class="auth-provider-icon" aria-hidden="true">
                            G
                        </span>
                        Google
                    </button>

                    <button
                        type="button"
                        class="auth-provider"
                        disabled
                        aria-describedby="providers-info"
                    >
                        <span class="auth-provider-icon" aria-hidden="true">
                            ◈
                        </span>
                        Discord
                    </button>
                </div>

                <p id="providers-info" class="auth-help">
                    Logowanie zewnętrzne nie jest jeszcze dostępne.
                </p>
            </section>

            <div class="auth-register">
                <p>Nie masz jeszcze konta?</p>

                <button
                    type="button"
                    class="auth-text-button"
                    disabled
                >
                    Zarejestruj się
                </button>
            </div>

            <a href="/dashboard" class="auth-demo-link">
                Zobacz przykładowy pulpit
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>