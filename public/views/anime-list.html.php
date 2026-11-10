<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="catalog-page">
    <header class="catalog-heading">
        <div class="catalog-heading-copy">
            <p class="eyebrow">Znajdź swój następny seans</p>
            <h1>Odkrywaj anime</h1>
            <p>Przeglądaj tytuły, poznawaj historie i wybieraj serie do swojej watchlisty.</p>
        </div>
        <div class="catalog-heading-meta">
            <p class="catalog-size"><strong data-catalog-count="<?= $escape($catalogTotal) ?>"><?= $escape($catalogTotal) ?></strong> anime w katalogu</p>
            <a class="catalog-ranking-link" href="/anime/ranking">Rankingi anime społeczności <span aria-hidden="true">→</span></a>
        </div>
    </header>

    <?php if (!empty($searchError)): ?>
        <p class="catalog-search-error" id="catalog-search-error" role="alert"><?= $escape($searchError) ?></p>
    <?php endif; ?>

    <form action="/anime" method="get" role="search" class="catalog-search" aria-label="Wyszukiwarka anime"<?= !empty($searchError) ? ' aria-describedby="catalog-search-error"' : '' ?>>
        <div class="catalog-search-primary">
            <div class="catalog-field catalog-query">
                <label for="anime-search">Szukaj po tytule</label>
                <input id="anime-search" name="q" type="search" maxlength="100" value="<?= $escape($query) ?>" placeholder="Tytuł lub nazwa alternatywna…" aria-describedby="anime-search-help">
                <p id="anime-search-help">Połącz tytuł z dowolnymi kryteriami poniżej.</p>
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
            <div class="catalog-field">
                <label for="anime-sort">Sortuj wyniki</label>
                <select id="anime-sort" name="sort">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= $escape($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <details class="catalog-advanced-filters"<?= implode('', $filters) !== '' || !empty($searchError) ? ' open' : '' ?>>
            <summary>
                <span>Dodatkowe filtry</span>
                <span class="catalog-filters-summary">Format, odcinki, ocena i rok premiery</span>
            </summary>
            <div class="catalog-search-filters">
                <fieldset class="catalog-filter-group">
                    <legend>Format</legend>
                    <div class="catalog-field">
                        <label for="anime-type">Typ anime</label>
                        <select id="anime-type" name="type">
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?= $escape($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </fieldset>

                <fieldset class="catalog-filter-group">
                    <legend>Liczba odcinków</legend>
                    <div class="catalog-range">
                        <div class="catalog-field">
                            <label for="anime-episodes-min">Od</label>
                            <input id="anime-episodes-min" name="episodes_min" type="number" min="1" max="2147483647" step="1" value="<?= $escape($filters['episodes_min']) ?>" placeholder="np. 1">
                        </div>
                        <div class="catalog-field">
                            <label for="anime-episodes-max">Do</label>
                            <input id="anime-episodes-max" name="episodes_max" type="number" min="1" max="2147483647" step="1" value="<?= $escape($filters['episodes_max']) ?>" placeholder="np. 26">
                        </div>
                    </div>
                </fieldset>

                <fieldset class="catalog-filter-group">
                    <legend>Ocena źródłowa</legend>
                    <div class="catalog-range">
                        <div class="catalog-field">
                            <label for="anime-score-min">Od</label>
                            <input id="anime-score-min" name="score_min" type="number" min="0" max="10" step="0.01" value="<?= $escape($filters['score_min']) ?>" placeholder="np. 7.0">
                        </div>
                        <div class="catalog-field">
                            <label for="anime-score-max">Do</label>
                            <input id="anime-score-max" name="score_max" type="number" min="0" max="10" step="0.01" value="<?= $escape($filters['score_max']) ?>" placeholder="np. 10">
                        </div>
                    </div>
                    <p class="catalog-filter-hint">Skala od 0 do 10.</p>
                </fieldset>

                <fieldset class="catalog-filter-group">
                    <legend>Rok premiery</legend>
                    <div class="catalog-range">
                        <div class="catalog-field">
                            <label for="anime-year-min">Od</label>
                            <input id="anime-year-min" name="year_min" type="number" min="1900" max="2200" step="1" value="<?= $escape($filters['year_min']) ?>" placeholder="np. 2000">
                        </div>
                        <div class="catalog-field">
                            <label for="anime-year-max">Do</label>
                            <input id="anime-year-max" name="year_max" type="number" min="1900" max="2200" step="1" value="<?= $escape($filters['year_max']) ?>" placeholder="Dowolny">
                        </div>
                    </div>
                </fieldset>
            </div>
            <p class="catalog-filter-help">Puste pola oznaczają dowolną wartość. Po wybraniu zakresu pomijamy anime bez tych danych.</p>
        </details>
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
                <p class="catalog-results-order">Kolejność: <?= $escape($sortOptions[$sort]) ?></p>
            </div>
            <?php if ($total > 0): ?>
                <p>Strona <?= $escape($page) ?> z <?= $escape($totalPages) ?></p>
            <?php endif; ?>
        </header>

        <?php if ($animes === []): ?>
            <div class="catalog-empty">
                <span aria-hidden="true">✦</span>
                <h3>Nie znaleziono pasującego anime</h3>
                <p>Sprawdź pisownię lub poszerz wybrane kryteria wyszukiwania.</p>
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
