<?php require __DIR__ . '/partials/header.html.php'; ?>

<nav aria-label="Powrót do katalogu">
    <a href="/anime">Wróć do katalogu</a>
</nav>

<article class="anime-details">
    <header>
        <h1><?= $escape($anime['title']) ?></h1>

        <p>
            <?= $escape(implode(' · ', $anime['genres'])) ?>
        </p>
    </header>

    <section aria-labelledby="synopsis-title">
        <h2 id="synopsis-title">Opis fabuły</h2>

        <p><?= $escape($anime['description']) ?></p>
    </section>

    <section aria-labelledby="anime-facts-title">
        <h2 id="anime-facts-title">Informacje o serii</h2>

        <dl>
            <dt>Studio</dt>
            <dd><?= $escape($anime['studio']) ?></dd>

            <dt>Rok premiery</dt>
            <dd><?= $escape($anime['year']) ?></dd>

            <dt>Liczba odcinków</dt>
            <dd><?= $escape($anime['episodes']) ?></dd>

            <dt>Ocena demonstracyjna</dt>
            <dd><?= $escape($anime['score']) ?> / 10</dd>
        </dl>
    </section>

    <section aria-labelledby="anime-actions-title">
        <h2 id="anime-actions-title">Twoja kolekcja i ocena</h2>

        <button type="button" disabled>
            Dodaj do watchlisty
        </button>

        <form action="/anime/review" method="post">
            <fieldset disabled>
                <legend>Twoja ocena</legend>

                <label for="anime-rating">
                    Oceń anime w skali od 1 do 10
                </label>

                <input
                    id="anime-rating"
                    name="rating"
                    type="number"
                    min="1"
                    max="10"
                    step="1"
                    required
                >

                <button type="submit">Zapisz ocenę</button>
            </fieldset>
        </form>

        <a href="/chat">Przejdź do asystenta AI</a>
    </section>

    <section aria-labelledby="trailer-title">
        <h2 id="trailer-title">Podgląd serii</h2>

        <p>Zwiastun zostanie dodany w kolejnym etapie.</p>

        <button type="button" disabled>
            Obejrzyj zwiastun
        </button>
    </section>
</article>

<?php require __DIR__ . '/partials/footer.html.php'; ?>