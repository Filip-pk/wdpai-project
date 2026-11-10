<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="welcome" aria-labelledby="welcome-title">
    <header class="welcome-heading">
        <p class="eyebrow"><span aria-hidden="true">✦</span> Neo-Tokyo Stream Hub</p>
        <h1 id="welcome-title" class="welcome-title"><span class="gradient-text"><?= $escape($appName) ?></span></h1>
        <h2 class="welcome-tagline"><?= $escape($tagline) ?></h2>
        <p class="welcome-description">
            Zanurz się w świecie anime wspieranym przez sztuczną inteligencję.
            Odkrywaj tytuły dopasowane do Twojego nastroju, rozmawiaj
            z wirtualnymi postaciami i twórz własną kolekcję.
        </p>
    </header>

    <figure class="welcome-visual">
        <img class="welcome-image" src="<?= $assetUrl('/img/neo-tokyo-guide.jpg') ?>" alt="" fetchpriority="high" decoding="async" width="512" height="512">
        <div class="welcome-visual-top" aria-hidden="true"><span>✦ NEO-TOKYO</span><span>ANIME × AI</span></div>
        <figcaption class="welcome-visual-caption">
            <span class="visual-label"><span class="welcome-status-dot" aria-hidden="true"></span> Neo-Tokyo AI</span>
            <span>Twoja przewodniczka po świecie anime</span>
        </figcaption>
    </figure>

    <section class="welcome-start" aria-labelledby="start-title">
        <h2 id="start-title" class="section-title">Rozpocznij przygodę</h2>
        <nav class="welcome-actions" aria-label="Rozpocznij przygodę">
            <a href="/login" class="button button-primary"><span aria-hidden="true">↗</span> Zaloguj się</a>
            <a href="/register" class="button button-secondary" aria-describedby="registration-info"><span aria-hidden="true">＋</span> Zarejestruj się</a>
            <a href="/anime" class="welcome-explore">Odkryj bez logowania <span aria-hidden="true">→</span></a>
        </nav>
        <p id="registration-info" class="helper-text">Utwórz konto, aby uzyskać dostęp do projektów.</p>
    </section>

    <section class="welcome-features" aria-labelledby="features-title">
        <h2 id="features-title" class="section-title">Twój klimat. Twoje anime.</h2>
        <ul class="feature-list">
            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">✦</span>
                <div><h3>Inteligentne rekomendacje</h3><p>Opisz swój nastrój i odkryj anime dopasowane do Ciebie dzięki lokalnemu modelowi Ollama.</p></div>
            </li>
            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">💬</span>
                <div><h3>Czat z postaciami</h3><p>Porozmawiaj z AI odgrywającą postaci z ulubionych anime — od Naruto po L Lawlieta.</p></div>
            </li>
            <li class="feature-card">
                <span class="feature-icon" aria-hidden="true">♡</span>
                <div><h3>Twoja kolekcja</h3><p>Zapisuj tytuły na watchliście, śledź postępy oglądania i oceniaj obejrzane serie.</p></div>
            </li>
        </ul>
    </section>

    <footer class="welcome-footer"><p class="technology-badge"><span aria-hidden="true">✦</span> Projekt z integracją Ollama AI</p></footer>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
