<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="welcome" aria-labelledby="welcome-title">
    <header class="welcome-heading">
        <p class="eyebrow">
            <span aria-hidden="true">✦</span>
            Neo-Tokyo Stream Hub
        </p>

        <h1 id="welcome-title" class="welcome-title">
            <span class="welcome-flower" aria-hidden="true">🌸</span>
            <span class="gradient-text"><?= $escape($appName) ?></span>
        </h1>

        <h2 class="welcome-tagline">
            <?= $escape($tagline) ?>
        </h2>

        <p class="welcome-description">
            Zanurz się w świecie anime wspieranym przez sztuczną
            inteligencję. Odkrywaj tytuły dopasowane do Twojego
            nastroju, rozmawiaj z wirtualnymi postaciami
            i twórz własną kolekcję.
        </p>
    </header>

    <figure class="welcome-visual">
        <img
            class="welcome-image"
            src="https://lh3.googleusercontent.com/aida-public/AB6AXuCwTAlUwptX9haUuyqwZqeefs8ohDyVw4xfhdc5FUsrkEM5OmZ_7RQqkeSjkzjVAq3yfL0IsJk6MUhjjRlrUtgvA0X9fMydWSCZMknzjqDgWZLr6EvBV_aPF6g-ECwZJFFnsoltLlTVD2O7cFi_FTtDpDgSHbWdqM-kT9hCybq0ZwdWx_f___4PUwUb8ZzT6O5h4iVSi4WYmCUEqyKjm-UYLVKg4OTH9-LARrcWViuVVOYW0TSpolD6GA"
            alt=""
            fetchpriority="high"
            decoding="async"
        >

        <figcaption class="welcome-visual-caption">
            <span class="visual-label">Neo-Tokyo AI</span>
            <span>Twoja przewodniczka po świecie anime</span>
        </figcaption>
    </figure>

    <section class="welcome-features" aria-labelledby="features-title">
        <h2 id="features-title" class="section-title">
            Twój klimat. Twoje anime.
        </h2>

        <ul class="feature-list">
            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">✦</span>

                <div>
                    <h3>Inteligentne rekomendacje</h3>
                    <p>
                        Opisz swój nastrój i odkryj anime dopasowane
                        do Ciebie dzięki lokalnemu modelowi Ollama.
                    </p>
                </div>
            </li>

            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">💬</span>

                <div>
                    <h3>Czat z postaciami</h3>
                    <p>
                        Porozmawiaj z AI odgrywającą postaci
                        z ulubionych anime — od Naruto po L Lawlieta.
                    </p>
                </div>
            </li>

            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">♡</span>

                <div>
                    <h3>Twoja kolekcja</h3>
                    <p>
                        Zapisuj tytuły na watchliście, śledź postępy
                        oglądania i oceniaj obejrzane serie.
                    </p>
                </div>
            </li>
        </ul>
    </section>

    <section class="welcome-start" aria-labelledby="start-title">
        <h2 id="start-title" class="section-title">
            Rozpocznij przygodę
        </h2>

        <nav class="welcome-actions" aria-label="Rozpocznij przygodę">
            <a href="/login" class="button button-primary">
                <span aria-hidden="true">↗</span>
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
                <span aria-hidden="true">→</span>
            </a>
        </nav>

        <p id="registration-info" class="helper-text">
            Rejestracja będzie dostępna w kolejnym etapie.
        </p>
    </section>

    <section class="welcome-social" aria-labelledby="social-title">
        <h2 id="social-title" class="social-title">
            Lub kontynuuj przez
        </h2>

        <div class="social-actions">
            <button
                type="button"
                class="button button-social"
                disabled
                aria-describedby="social-info"
            >
                Google
            </button>

            <button
                type="button"
                class="button button-social"
                disabled
                aria-describedby="social-info"
            >
                Discord
            </button>
        </div>

        <p id="social-info" class="helper-text">
            Logowanie zewnętrzne jest na razie elementem makiety.
        </p>
    </section>

    <footer class="welcome-footer">
        <p class="technology-badge">
            <span aria-hidden="true">✦</span>
            Projekt z integracją Ollama AI
        </p>
    </footer>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>