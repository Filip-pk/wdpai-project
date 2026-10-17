<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="auth" aria-labelledby="login-title">
    <a href="/">Wróć do strony głównej</a>

    <header>
        <p>AniVibe Neural Core</p>
        <p>Połącz z cyberświatem</p>

        <h1 id="login-title">Witaj z powrotem!</h1>

        <p>
            Zaloguj się, aby rozmawiać z postaciami
            i odkrywać anime z AI.
        </p>
    </header>

    <p id="login-info">
        Formularz demonstracyjny.
        Logowanie zostanie podłączone w kolejnym etapie.
    </p>

    <form
        action="/login"
        method="post"
        aria-describedby="login-info"
    >
        <fieldset disabled>
            <legend>Dane logowania</legend>

            <label for="login-identifier">
                E-mail lub nazwa użytkownika
            </label>

            <input
                id="login-identifier"
                name="identifier"
                type="text"
                autocomplete="username"
                maxlength="254"
                required
            >

            <label for="login-password">Hasło</label>

            <input
                id="login-password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Zaloguj się</button>
        </fieldset>
    </form>

    <button type="button" disabled>
        Nie pamiętasz hasła?
    </button>

    <section aria-labelledby="alternative-login-title">
        <h2 id="alternative-login-title">
            Lub zaloguj się przez
        </h2>

        <button type="button" disabled>Google</button>
        <button type="button" disabled>Discord</button>
    </section>

    <p>
        Nie masz jeszcze konta?
        <button type="button" disabled>Zarejestruj się</button>
    </p>

    <a href="/dashboard">Zobacz przykładowy pulpit</a>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>