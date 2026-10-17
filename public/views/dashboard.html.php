<?php require __DIR__ . '/partials/header.html.php'; ?>

<header class="page-heading">
    <h1>Hej, <?= $escape($user['name']) ?>!</h1>

    <p>
        Rola konta demonstracyjnego:
        <?= $escape($user['role']) ?>
    </p>

    <p>
        Przykładowe wykorzystanie AI:
        <?= $escape($user['aiUsed']) ?>
        /
        <?= $escape($user['aiLimit']) ?>
        zapytań.
    </p>
</header>

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

<section aria-labelledby="recommendations-title">
    <h2 id="recommendations-title">
        Dla Ciebie — przykładowe rekomendacje
    </h2>

    <ul class="anime-grid">
        <?php foreach ($recommendations as $animeId => $anime): ?>
            <li>
                <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <a href="/anime">Zobacz cały katalog</a>
    <a href="/watchlist">Przejdź do watchlisty</a>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>