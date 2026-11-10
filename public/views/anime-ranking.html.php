<?php
require __DIR__ . '/partials/header.html.php';

$genreValue = $criteria->form['genre'] ?? (string) ($criteria->genreId ?? '');
$yearValue = $criteria->form['year'] ?? (string) ($criteria->year ?? '');
$minimumVotesValue = $criteria->form['min_votes'] ?? (string) $criteria->minVotes;
$isWatchedRanking = $criteria->mode === 'watched';
$genreValues = array_map(static fn (array $genre): string => (string) $genre['id'], $genres);
$clearUrl = '/anime/ranking?' . http_build_query(['mode' => $criteria->mode]);
?>

<div class="anime-ranking-page" data-ranking-mode="<?= $escape($criteria->mode) ?>" data-ranking-page="<?= $escape($ranking['page']) ?>" data-ranking-total="<?= $escape($ranking['total']) ?>" data-ranking-total-pages="<?= $escape($ranking['totalPages']) ?>">
    <header class="anime-ranking-heading">
        <p class="eyebrow">Wybory społeczności AniVibe</p>
        <h1>Rankingi anime</h1>
        <p>Odkryj tytuły najlepiej oceniane i najczęściej oglądane przez użytkowników AniVibe.</p>
        <div class="anime-ranking-related">
            <a href="/anime">Przeglądaj katalog anime</a>
            <a href="/ranking">Ranking użytkowników</a>
        </div>
    </header>

    <nav class="anime-ranking-tabs" aria-label="Wybierz ranking anime">
        <?php foreach ($criteria::MODES as $mode => $label): ?>
            <a href="<?= $escape($modeUrls[$mode]) ?>"<?= $criteria->mode === $mode ? ' aria-current="page"' : '' ?>><?= $escape($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <p class="anime-ranking-description">
        <?php if ($isWatchedRanking): ?>
            Liczymy konta, które mają anime na watchliście ze statusem „Oglądam” lub „Ukończone”. Każde konto liczy się raz przy danym tytule.
        <?php else: ?>
            Kolejność według średniej ocen AniVibe. Minimalna liczba ocen: <?= $escape($criteria->minVotes) ?>.
            Przy tej samej średniej wyżej jest tytuł z większą liczbą ocen; kolejne remisy porządkuje tytuł anime.
        <?php endif; ?>
    </p>

    <?php if ($criteria->errors !== []): ?>
        <div id="anime-ranking-errors" class="anime-ranking-errors" role="alert">
            <ul><?php foreach ($criteria->errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form action="/anime/ranking" method="get" class="anime-ranking-filters" aria-label="Filtry rankingu anime"<?= $criteria->errors !== [] ? ' aria-describedby="anime-ranking-errors"' : '' ?>>
        <input type="hidden" name="mode" value="<?= $escape($criteria->mode) ?>">
        <div class="anime-ranking-field">
            <label for="anime-ranking-genre">Gatunek</label>
            <select id="anime-ranking-genre" name="genre">
                <option value=""<?= $genreValue === '' ? ' selected' : '' ?>>Wszystkie gatunki</option>
                <?php if ($genreValue !== '' && !in_array($genreValue, $genreValues, true)): ?>
                    <option value="<?= $escape($genreValue) ?>" selected>Nieznany gatunek</option>
                <?php endif; ?>
                <?php foreach ($genres as $genre): ?>
                    <option value="<?= $escape($genre['id']) ?>"<?= $genreValue === (string) $genre['id'] ? ' selected' : '' ?>><?= $escape($genre['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="anime-ranking-field">
            <label for="anime-ranking-year">Rok premiery</label>
            <input id="anime-ranking-year" name="year" type="number" min="1900" max="2200" step="1" value="<?= $escape($yearValue) ?>" placeholder="Dowolny rok">
        </div>
        <div class="anime-ranking-field">
            <label for="anime-ranking-min-votes">Minimalna liczba ocen</label>
            <input id="anime-ranking-min-votes" name="min_votes" type="number" min="1" max="1000000" step="1" value="<?= $escape($minimumVotesValue) ?>" aria-describedby="anime-ranking-min-votes-help"<?= $isWatchedRanking ? ' disabled' : '' ?>>
            <p id="anime-ranking-min-votes-help">Dotyczy tylko rankingu najlepiej ocenianych anime. Domyślnie: 5 ocen.</p>
        </div>
        <div class="anime-ranking-filter-actions">
            <button type="submit">Zastosuj filtry</button>
            <a href="<?= $escape($clearUrl) ?>">Wyczyść filtry</a>
        </div>
    </form>

    <section class="anime-ranking-results" aria-labelledby="anime-ranking-results-title">
        <header class="anime-ranking-results-heading">
            <h2 id="anime-ranking-results-title"><?= $escape($criteria::MODES[$criteria->mode]) ?></h2>
            <p>Wyników: <?= $escape($ranking['total']) ?></p>
        </header>
        <?php if ($ranking['entries'] === []): ?>
            <div class="anime-ranking-empty">
                <p>Brak anime spełniających wybrane kryteria.</p>
                <p><?= $isWatchedRanking ? 'Zmień filtry lub dodaj oglądane anime do swojej watchlisty.' : 'Zmień filtry lub zmniejsz minimalną liczbę ocen. Możesz też ocenić anime w katalogu.' ?></p>
                <a href="/anime">Przejdź do katalogu anime</a>
            </div>
        <?php else: ?>
            <ol class="anime-ranking-list" aria-label="<?= $escape($criteria::MODES[$criteria->mode]) ?>" start="<?= $escape(($ranking['page'] - 1) * 20 + 1) ?>">
                <?php foreach ($ranking['entries'] as $entry): ?>
                    <?php
                    $animeId = $entry['id'];
                    $anime = $animes[$animeId] ?? null;
                    if ($anime === null) {
                        continue;
                    }
                    $rankAverage = $entry['communityAverage'] === null ? '' : json_encode($entry['communityAverage'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                    ?>
                    <li class="anime-ranking-item" data-ranked-anime-id="<?= $escape($animeId) ?>" data-rank="<?= $escape($entry['rank']) ?>" data-community-average="<?= $escape($rankAverage) ?>" data-community-count="<?= $escape($entry['communityCount']) ?>" data-watcher-count="<?= $escape($entry['watcherCount']) ?>" data-year="<?= $escape($anime['year'] ?? '') ?>">
                        <div class="anime-ranking-position">
                            <span class="anime-ranking-rank">Pozycja <?= $escape($entry['rank']) ?></span>
                            <?php if ($isWatchedRanking): ?>
                                <span class="anime-ranking-watchers">Oglądam lub Ukończone: <strong><?= $escape($entry['watcherCount']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php if ($ranking['totalPages'] > 1): ?>
        <nav class="anime-ranking-pagination" aria-label="Strony rankingu anime">
            <?php if ($previousUrl !== null): ?>
                <a href="<?= $escape($previousUrl) ?>" rel="prev">← Poprzednia</a>
            <?php endif; ?>
            <span>Strona <?= $escape($ranking['page']) ?> z <?= $escape($ranking['totalPages']) ?></span>
            <?php if ($nextUrl !== null): ?>
                <a href="<?= $escape($nextUrl) ?>" rel="next">Następna →</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
