<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
    <header>
        <a href="/" aria-label="AniVibe — strona główna">
            <span aria-hidden="true">🌸</span>
            <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>
        </a>
    </header>

    <main>
        <section aria-labelledby="welcome-title">
            <p>Neo-Tokyo Stream Hub</p>

            <h1 id="welcome-title">
                <?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <h2><?= htmlspecialchars($tagline, ENT_QUOTES, 'UTF-8') ?></h2>

            <p>
                Zanurz się w świecie anime wspieranym przez sztuczną
                inteligencję. Odkrywaj tytuły dopasowane do Twojego
                nastroju, rozmawiaj z wirtualnymi postaciami
                i twórz własną kolekcję.
            </p>

            <section aria-labelledby="features-title">
                <h2 id="features-title">Co znajdziesz w AniVibe?</h2>

                <ul>
                    <li>
                        <h3>Inteligentne rekomendacje</h3>
                        <p>
                            Znajdź anime dopasowane do swojego nastroju
                            dzięki lokalnemu modelowi Ollama.
                        </p>
                    </li>
                    <li>
                        <h3>Czat z postaciami</h3>
                        <p>
                            Porozmawiaj z AI odgrywającą postaci
                            z ulubionych anime.
                        </p>
                    </li>
                    <li>
                        <h3>Twoja kolekcja</h3>
                        <p>
                            Zapisuj interesujące tytuły
                            i oceniaj obejrzane serie.
                        </p>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="start-title">
                <h2 id="start-title">Rozpocznij przygodę</h2>

                <p id="account-info">
                    Logowanie i rejestracja będą dostępne w kolejnym etapie.
                </p>

                <button type="button" disabled aria-describedby="account-info">
                    Zaloguj się
                </button>

                <button type="button" disabled aria-describedby="account-info">
                    Zarejestruj się
                </button>

                <nav aria-label="Przeglądanie bez konta">
                    <a href="/anime">Odkryj bez logowania</a>
                </nav>
            </section>
        </section>
    </main>

    <footer>
        <p>AniVibe — projekt wykorzystujący Ollama AI.</p>
    </footer>
</body>
</html>