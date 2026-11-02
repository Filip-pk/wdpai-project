<?php require __DIR__ . '/partials/header.html.php'; ?>

<nav class="catalog-back" aria-label="Powrót do katalogu">
    <a href="/anime">← Wróć do katalogu</a>
</nav>

<article class="anime-details">
    <header class="anime-details-heading">
        <div class="anime-cover anime-detail-cover" aria-hidden="true">
            <span class="anime-cover-placeholder"><span>✦</span><span>Brak okładki</span></span>
            <?php if (!empty($anime['coverUrl'])): ?>
                <img data-anime-cover src="<?= $escape($anime['coverUrl']) ?>" alt="" width="300" height="450" decoding="async" referrerpolicy="no-referrer">
            <?php endif; ?>
        </div>
        <div class="anime-details-identity">
            <p class="eyebrow"><?= $escape(($anime['type'] ?? '') === '' ? 'Poznaj serię' : $anime['type']) ?></p>
            <h1><?= $escape($anime['title']) ?></h1>
            <ul class="anime-genres" aria-label="Gatunki">
                <?php foreach ($anime['genres'] as $genreName): ?>
                    <li><?= $escape($genreName) ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Znajdź informacje o serii i dodaj ją do swojej kolekcji.</p>
        </div>
    </header>

    <section class="anime-details-section" aria-labelledby="synopsis-title">
        <div class="anime-section-heading">
            <h2 id="synopsis-title">Opis fabuły</h2>
            <?php if (($anime['descriptionLanguage'] ?? '') === 'en' && $anime['description'] !== ''): ?>
                <span class="anime-language">Opis w języku angielskim</span>
            <?php endif; ?>
        </div>
        <p class="anime-synopsis"<?= ($anime['descriptionLanguage'] ?? '') === 'en' ? ' lang="en"' : '' ?>><?= $escape($anime['description'] === '' ? 'Opis tego anime nie jest jeszcze dostępny.' : $anime['description']) ?></p>
    </section>

    <section class="anime-details-section" aria-labelledby="anime-facts-title">
        <h2 id="anime-facts-title">Informacje o serii</h2>

        <dl class="anime-detail-facts">
            <div><dt>Studio</dt><dd><?= $escape($anime['studio'] === '' ? 'Nieznane' : $anime['studio']) ?></dd></div>
            <div><dt>Rok premiery</dt><dd><?= isset($anime['year']) ? $escape($anime['year']) : 'Nieznany' ?></dd></div>
            <div><dt>Liczba odcinków</dt><dd><?= isset($anime['episodes']) ? $escape($anime['episodes']) : 'Nieznana' ?></dd></div>
            <div><dt>Ocena MAL</dt><dd><?= isset($anime['score']) ? $escape(number_format($anime['score'], 2, ',', '')) . ' / 10' : 'Brak oceny' ?></dd></div>
        </dl>
        <?php if (!empty($anime['sourceUrl']) && str_starts_with($anime['sourceUrl'], 'https://')): ?>
            <p class="anime-source">Dane o anime: <a href="<?= $escape($anime['sourceUrl']) ?>" rel="external noreferrer">MyAnimeList</a>.
                Próbka katalogu: <a href="https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime" rel="external noreferrer">Processed Top 15k Anime</a> — TheRealVigilante, na podstawie zbioru Quan Than.</p>
        <?php endif; ?>
    </section>

    <section class="anime-details-section" aria-labelledby="anime-actions-title">
        <h2 id="anime-actions-title">Twoja kolekcja i ocena</h2>

        <?php if ($isLoggedIn): ?>
            <form action="/watchlist" method="post">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="animeId" value="<?= $escape($animeId) ?>">
                <input type="hidden" name="mode" value="add">
                <button type="submit">Dodaj do watchlisty</button>
            </form>
        <?php else: ?>
            <p><a href="/login">Zaloguj się</a>, aby dodać anime do swojej watchlisty.</p>
        <?php endif; ?>

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

    <section class="anime-details-section" aria-labelledby="trailer-title">
        <h2 id="trailer-title">Podgląd serii</h2>

        <p>Zwiastun zostanie dodany w kolejnym etapie.</p>

        <button type="button" disabled>
            Obejrzyj zwiastun
        </button>
    </section>
</article>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
