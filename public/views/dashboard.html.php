<?php require __DIR__ . '/partials/header.html.php'; ?>

<header class="page-heading">
    <h1><?= $user === null ? 'Witaj w AniVibe!' : 'Hej, ' . $escape($user['name']) . '!' ?></h1>
    <p>Odkrywaj anime i buduj własną kolekcję.</p>
    <p>Rozmowy z AI i dobieranie anime według nastroju są demonstracyjne; licznik zapytań nie jest jeszcze aktywny.</p>
    <?php if ($user === null): ?>
        <p><a href="/login">Zaloguj się</a>, aby zobaczyć swój profil i watchlistę.</p>
    <?php endif; ?>
</header>

<section class="dashboard-recommendations" aria-labelledby="recommendations-title" data-recommendation-mode="<?= $escape($recommendationMode) ?>" data-recommendation-source-count="<?= $escape($recommendationSourceCount) ?>">
    <div class="dashboard-recommendations-heading">
        <div>
            <p class="dashboard-eyebrow"><?= $recommendationMode === 'personalized' ? 'Z Twojej watchlisty' : 'Odkryj coś nowego' ?></p>
            <h2 id="recommendations-title"><?= $recommendationMode === 'personalized' ? 'Dla Ciebie' : 'Propozycje na początek' ?></h2>
        </div>
        <a href="/anime">Zobacz cały katalog <span aria-hidden="true">→</span></a>
    </div>

    <?php if ($recommendationMode === 'personalized'): ?>
        <p>Dobieramy anime do gatunków z Twojej watchlisty, uwzględniając statusy oglądania, Twoje oceny i preferowany format. Rekomendacje działają bez AI.</p>
        <div class="dashboard-preferences">
            <h3>Twoje ulubione gatunki</h3>
            <ul class="dashboard-preference-list">
                <?php foreach ($preferredGenres as $genre): ?>
                    <li><a href="/anime?genre=<?= $escape($genre['id']) ?>" data-preferred-genre-id="<?= $escape($genre['id']) ?>"><?= $escape($genre['name']) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <p>Pozycje na Twojej watchliście: <?= $escape($recommendationSourceCount) ?>. Zmiany na liście i w ocenach uwzględniamy przy kolejnym otwarciu pulpitu.</p>
        </div>
    <?php elseif ($recommendationMode === 'guest'): ?>
        <p>Na początek poznaj dobrze oceniane tytuły z katalogu. <a href="/login">Zaloguj się</a> i zbuduj watchlistę, aby otrzymywać własne rekomendacje.</p>
    <?php else: ?>
        <p>To dobrze oceniane propozycje z katalogu. Potrzebujemy więcej informacji o Twoich ulubionych gatunkach, aby dopasować je do Ciebie. Dodaj anime do watchlisty, oznacz obejrzane tytuły i wystaw im oceny.</p>
    <?php endif; ?>

    <?php if ($recommendations !== []): ?>
        <ul class="anime-grid dashboard-recommendation-grid">
            <?php foreach ($recommendations as $animeId => $anime): ?>
                <li data-recommended-anime-id="<?= $escape($animeId) ?>">
                    <?php if (($recommendationReasons[$animeId] ?? []) !== []): ?>
                        <p class="dashboard-recommendation-reason">Pasuje do Twoich gustów: <strong><?= $escape(implode(', ', $recommendationReasons[$animeId])) ?></strong></p>
                    <?php endif; ?>
                    <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <div class="dashboard-recommendations-empty">
            <h3>Na razie brak nowych propozycji</h3>
            <p><?= $recommendationMode === 'personalized' ? 'Nie znaleźliśmy kolejnych anime pasujących do Twoich preferencji poza tytułami już obecnymi na Twojej watchliście. Odkryj nowe gatunki w katalogu lub uzupełnij swoje oceny.' : 'Nie znaleźliśmy dostępnych propozycji. Zajrzyj do katalogu i wybierz kolejne anime do swojej watchlisty.' ?></p>
        </div>
    <?php endif; ?>

    <?php if ($user !== null): ?>
        <a class="dashboard-watchlist-link" href="/watchlist">Przejdź do swojej watchlisty <span aria-hidden="true">→</span></a>
    <?php endif; ?>
</section>

<section class="mood-panel" aria-labelledby="mood-title">
    <h2 id="mood-title">Jak się dziś czujesz?</h2>

    <p>
        Opisz swój nastrój, a asystent pomoże
        dobrać anime na dzisiejszy seans.
    </p>

    <form action="/chat" method="post">
        <fieldset disabled>
            <legend>Rekomendacje według nastroju</legend>

            <label for="mood-message">
                Twój nastrój i preferencje
            </label>

            <textarea
                id="mood-message"
                name="message"
                rows="4"
                maxlength="2000"
                placeholder="Chcę coś z magią i komedią..."
                required
            ></textarea>

            <button type="submit">Zapytaj AI</button>
        </fieldset>
    </form>

    <ul aria-label="Przykładowe nastroje">
        <li>Cyberpunk</li>
        <li>Mroczne fantasy</li>
        <li>Romantyczne</li>
        <li>Szybka akcja</li>
    </ul>

    <a href="/chat">Zobacz przykładową rozmowę z asystentem</a>
</section>

<section aria-labelledby="personas-title">
    <h2 id="personas-title">Porozmawiaj z postacią AI</h2>

    <ul class="persona-grid">
        <?php foreach ($personas as $personaId => $persona): ?>
            <li>
                <article class="persona-card">
                    <h3>
                        <a href="/chat/<?= $escape($personaId) ?>">
                            <?= $escape($persona['name']) ?>
                        </a>
                    </h3>

                    <p><?= $escape($persona['anime']) ?></p>
                </article>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
