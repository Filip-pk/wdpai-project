<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="welcome" aria-labelledby="welcome-title">
    <header class="welcome-heading">
        <p>Neo-Tokyo Stream Hub</p>

        <h1 id="welcome-title">
            <?= $escape($appName) ?>
        </h1>

        <h2><?= $escape($tagline) ?></h2>

        <p>
            Zanurz się w świecie anime wspieranym przez sztuczną
            inteligencję. Odkrywaj tytuły dopasowane do Twojego
            nastroju, rozmawiaj z wirtualnymi postaciami
            i twórz własną kolekcję.
        </p>
    </header>

    <figure class="welcome-illustration">
        <!-- Tutaj dodamy osobny eksport ilustracji z projektu. -->
        <figcaption>
            Neo-Tokyo AI — Twoja przewodniczka po świecie anime.
        </figcaption>
    </figure>

    <section class="welcome-features" aria-labelledby="features-title">
        <h2 id="features-title">Co znajdziesz w AniVibe?</h2>

        <ul>
            <li>
                <h3>Inteligentne rekomendacje</h3>
                <p>
                    Znajdź anime dopasowane do swojego nastroju
                    dzięki lokalnemu modelowi Ollama.
                    Opisz, na co masz ochotę, i odkryj nowe tytuły.
                </p>
            </li>

            <li>
                <h3>Czat z postaciami</h3>
                <p>
                    Porozmawiaj z AI odgrywającą postaci
                    z ulubionych anime — od Naruto
                    po tajemniczego L Lawlieta.
                </p>
            </li>

            <li>
                <h3>Twoja kolekcja</h3>
                <p>
                    Zapisuj interesujące tytuły na watchliście,
                    śledź postępy oglądania i oceniaj
                    obejrzane serie.
                </p>
            </li>
        </ul>
    </section>

    <section class="welcome-start" aria-labelledby="start-title">
        <h2 id="start-title">Rozpocznij przygodę</h2>

        <p>
            Odkryj katalog anime lub przejdź
            do ekranu logowania.
        </p>

        <nav class="welcome-actions" aria-label="Rozpocznij przygodę">
            <a href="/login" class="button button-primary">
                Zaloguj się
            </a>

            <button
                type="button"
                class="button button-secondary"
                disabled
                aria-describedby="registration-info"
            >
                Zarejestruj się
            </button>

            <a href="/anime" class="welcome-explore">
                Odkryj bez logowania
            </a>
        </nav>

        <p id="registration-info">
            Rejestracja będzie dostępna w kolejnym etapie.
        </p>
    </section>

    <section class="welcome-social" aria-labelledby="social-title">
        <h2 id="social-title">Lub kontynuuj przez</h2>

        <p id="social-info">
            Logowanie przez zewnętrzne konta
            jest na razie elementem makiety.
        </p>

        <button
            type="button"
            disabled
            aria-describedby="social-info"
        >
            Google
        </button>

        <button
            type="button"
            disabled
            aria-describedby="social-info"
        >
            Discord
        </button>
    </section>

    <footer class="welcome-footer">
        <p>Planowana integracja: lokalny model Ollama AI.</p>
    </footer>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>