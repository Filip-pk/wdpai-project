<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="catalog-page">
    <header class="catalog-heading">
        <p class="eyebrow">Znajdź swój następny seans</p>
        <h1>Odkrywaj anime</h1>
        <p>Przeglądaj tytuły, poznawaj historie i wybieraj serie do swojej watchlisty.</p>
        <p class="catalog-size"><strong data-catalog-count="<?= $escape($catalogTotal) ?>"><?= $escape($catalogTotal) ?></strong> anime w katalogu</p>
    </header>

    <?php if (!empty($searchError)): ?>
        <p class="catalog-search-error" id="catalog-search-error" role="alert"><?= $escape($searchError) ?></p>
    <?php endif; ?>

    <form action="/anime" method="get" role="search" class="catalog-search" aria-label="Wyszukiwarka anime"<?= !empty($searchError) ? ' aria-describedby="catalog-search-error"' : '' ?>>
        <div class="catalog-field catalog-query">
            <label for="anime-search">Szukaj po tytule</label>
            <input id="anime-search" name="q" type="search" maxlength="100" value="<?= $escape($query) ?>" placeholder="Tytuł lub nazwa alternatywna…" aria-describedby="anime-search-help">
            <p id="anime-search-help">Możesz połączyć nazwę z wybranym gatunkiem.</p>
        </div>
        <div class="catalog-field">
            <label for="anime-genre">Gatunek</label>
            <select id="anime-genre" name="genre">
                <option value="">Wszystkie gatunki</option>
                <?php foreach ($genres as $genre): ?>
                    <option value="<?= $escape($genre['id']) ?>" <?= $genreId === $genre['id'] ? 'selected' : '' ?>><?= $escape($genre['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="catalog-search-actions">
            <button type="submit">Szukaj anime</button>
            <a href="/anime">Wyczyść filtry</a>
        </div>
    </form>

    <section class="catalog-results" aria-labelledby="catalog-title" data-page="<?= $escape($page) ?>">
        <header class="catalog-results-heading">
            <div>
                <h2 id="catalog-title">Katalog anime</h2>
                <p>Liczba wyników: <strong data-result-count="<?= $escape($total) ?>"><?= $escape($total) ?></strong></p>
            </div>
            <?php if ($total > 0): ?>
                <p>Strona <?= $escape($page) ?> z <?= $escape($totalPages) ?></p>
            <?php endif; ?>
        </header>

        <?php if ($animes === []): ?>
            <div class="catalog-empty">
                <span aria-hidden="true">✦</span>
                <h3>Nie znaleziono pasującego anime</h3>
                <p>Sprawdź pisownię, wpisz krótszy tytuł lub wybierz inny gatunek.</p>
                <a href="/anime">Pokaż cały katalog</a>
            </div>
        <?php else: ?>
            <ul class="anime-grid">
                <?php foreach ($animes as $animeId => $anime): ?>
                    <li><?php require __DIR__ . '/partials/anime-card.html.php'; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav class="catalog-pagination" aria-label="Paginacja katalogu">
                <?php if ($previousUrl !== null): ?>
                    <a href="<?= $escape($previousUrl) ?>" rel="prev" class="catalog-page-direction">← Poprzednia</a>
                <?php endif; ?>
                <ol>
                    <?php foreach ($pagination as $item): ?>
                        <li>
                            <a href="<?= $escape($item['url']) ?>" aria-label="<?= $escape('Strona ' . $item['page']) ?>" <?= $item['current'] ? 'aria-current="page"' : '' ?>><?= $escape($item['page']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($nextUrl !== null): ?>
                    <a href="<?= $escape($nextUrl) ?>" rel="next" class="catalog-page-direction">Następna →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>

    <aside class="catalog-help" aria-labelledby="catalog-help-title">
        <h2 id="catalog-help-title">Nie wiesz, co wybrać?</h2>
        <p>Możesz też zobaczyć demonstracyjnego asystenta i przykładowe rozmowy o anime.</p>
        <a href="/chat">Zobacz asystenta AI</a>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
