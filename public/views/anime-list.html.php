<?php require __DIR__ . '/partials/header.html.php'; ?>

<h1>Odkrywaj anime</h1>

<form action="/anime" method="get" role="search">
    <label for="anime-search">Szukaj po tytule</label>

    <input
        id="anime-search"
        name="q"
        type="search"
        maxlength="100"
        value="<?= $escape($query) ?>"
    >

    <button type="submit">Szukaj</button>
    <a href="/anime">Wyczyść wyszukiwanie</a>
</form>

<div class="catalog-filters">
    <label for="anime-genre">Gatunek</label>

    <select
        id="anime-genre"
        name="genre"
        disabled
        aria-describedby="genre-info"
    >
        <option>Wszystkie</option>
        <option>Cyberpunk</option>
        <option>Mroczne fantasy</option>
        <option>Sci-Fi</option>
        <option>Psychologiczne</option>
    </select>

    <p id="genre-info">
        Filtrowanie po gatunku dodamy w kolejnym etapie.
    </p>
</div>

<section aria-labelledby="catalog-title">
    <h2 id="catalog-title">Katalog i rekomendacje</h2>

    <p>Liczba wyników: <?= count($animes) ?></p>

    <?php if ($animes === []): ?>
        <p>Nie znaleziono anime pasującego do podanej nazwy.</p>
    <?php else: ?>
        <ul class="anime-grid">
            <?php foreach ($animes as $animeId => $anime): ?>
                <li>
                    <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<aside aria-labelledby="catalog-help-title">
    <h2 id="catalog-help-title">Nie wiesz, co wybrać?</h2>

    <p>Asystent AI pomoże znaleźć anime dopasowane do nastroju.</p>

    <a href="/chat">Zobacz asystenta AI</a>
</aside>

<?php require __DIR__ . '/partials/footer.html.php'; ?>