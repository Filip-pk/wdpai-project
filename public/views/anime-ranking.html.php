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
        <div class="anime-ranking-heading-copy">
            <p class="eyebrow"><?= $escape($t('view.catalog.community_choices')) ?></p>
            <h1><?= $escape($t('view.catalog.anime_rankings')) ?></h1>
            <p><?= $escape($t('view.catalog.ranking_intro')) ?></p>
        </div>
        <nav class="anime-ranking-related" aria-label="<?= $escape($t('view.catalog.more_anivibe')) ?>">
            <a href="/anime"><?= $escape($t('view.catalog.browse_catalog')) ?> <span aria-hidden="true">↗</span></a>
            <a href="/ranking"><?= $escape($t('view.catalog.user_ranking')) ?> <span aria-hidden="true">→</span></a>
        </nav>
    </header>

    <nav class="anime-ranking-tabs" aria-label="<?= $escape($t('view.catalog.choose_ranking')) ?>">
        <?php foreach ($criteria::MODES as $mode => $label): ?>
            <a href="<?= $escape($modeUrls[$mode]) ?>"<?= $criteria->mode === $mode ? ' aria-current="page"' : '' ?>><?= $escape($translateText($label)) ?></a>
        <?php endforeach; ?>
    </nav>

    <details class="anime-ranking-explanation">
        <summary><?= $isWatchedRanking ? $escape($t('view.catalog.watchers_calculation')) : $escape($t('view.catalog.rating_calculation')) ?></summary>
        <p class="anime-ranking-description">
            <?php if ($isWatchedRanking): ?>
                <?= $escape($t('view.catalog.watchers_help')) ?>
            <?php else: ?>
                <?= $escape($t('view.catalog.ranking_score_intro', ['count' => $criteria->minVotes])) ?>
                <?= $escape($t('view.catalog.rating_tiebreak')) ?>
            <?php endif; ?>
        </p>
    </details>

    <?php if ($criteria->errors !== []): ?>
        <div id="anime-ranking-errors" class="anime-ranking-errors" role="alert">
            <ul><?php foreach ($criteria->errors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form action="/anime/ranking" method="get" class="anime-ranking-filters" aria-label="<?= $escape($t('view.catalog.ranking_filters')) ?>"<?= $criteria->errors !== [] ? ' aria-describedby="anime-ranking-errors"' : '' ?>>
        <input type="hidden" name="mode" value="<?= $escape($criteria->mode) ?>">
        <div class="anime-ranking-field">
            <label for="anime-ranking-genre"><?= $escape($t('view.catalog.genre')) ?></label>
            <select id="anime-ranking-genre" name="genre">
                <option value=""<?= $genreValue === '' ? ' selected' : '' ?>><?= $escape($t('view.catalog.all_genres')) ?></option>
                <?php if ($genreValue !== '' && !in_array($genreValue, $genreValues, true)): ?>
                    <option value="<?= $escape($genreValue) ?>" selected><?= $escape($t('view.catalog.unknown_genre')) ?></option>
                <?php endif; ?>
                <?php foreach ($genres as $genre): ?>
                    <option value="<?= $escape($genre['id']) ?>"<?= $genreValue === (string) $genre['id'] ? ' selected' : '' ?>><?= $escape($translateText($genre['name'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="anime-ranking-field">
            <label for="anime-ranking-year"><?= $escape($t('view.catalog.release_year')) ?></label>
            <input id="anime-ranking-year" name="year" type="number" min="1900" max="2200" step="1" value="<?= $escape($yearValue) ?>" placeholder="<?= $escape($t('view.catalog.any_year')) ?>">
        </div>
        <div class="anime-ranking-field">
            <label for="anime-ranking-min-votes"><?= $escape($t('view.catalog.min_ratings')) ?></label>
            <input id="anime-ranking-min-votes" name="min_votes" type="number" min="1" max="1000000" step="1" value="<?= $escape($minimumVotesValue) ?>" aria-describedby="anime-ranking-min-votes-help"<?= $isWatchedRanking ? ' disabled' : '' ?>>
            <p id="anime-ranking-min-votes-help"><?= $escape($t('view.catalog.min_ratings_help')) ?></p>
        </div>
        <div class="anime-ranking-filter-actions">
            <button type="submit"><?= $escape($t('view.catalog.apply_filters')) ?></button>
            <a href="<?= $escape($clearUrl) ?>"><?= $escape($t('view.catalog.clear_filters')) ?></a>
        </div>
    </form>

    <section class="anime-ranking-results" aria-labelledby="anime-ranking-results-title">
        <header class="anime-ranking-results-heading">
            <div>
                <p class="anime-ranking-kicker"><?= $escape($t('view.catalog.community_voice')) ?></p>
                <h2 id="anime-ranking-results-title"><?= $escape($translateText($criteria::MODES[$criteria->mode])) ?></h2>
            </div>
            <p class="anime-ranking-total"><?= $escape($t('view.catalog.results')) ?> <strong><?= $escape($ranking['total']) ?></strong></p>
        </header>
        <?php if ($ranking['entries'] === []): ?>
            <div class="anime-ranking-empty">
                <span class="anime-ranking-empty-mark" aria-hidden="true">✦</span>
                <p><?= $escape($t('view.catalog.ranking_empty')) ?></p>
                <p><?= $isWatchedRanking ? $escape($t('view.catalog.ranking_empty_watched')) : $escape($t('view.catalog.ranking_empty_rated')) ?></p>
                <a href="/anime"><?= $escape($t('view.catalog.go_catalog')) ?></a>
            </div>
        <?php else: ?>
            <ol class="anime-ranking-list" aria-label="<?= $escape($translateText($criteria::MODES[$criteria->mode])) ?>" start="<?= $escape(($ranking['page'] - 1) * 20 + 1) ?>">
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
                            <span class="anime-ranking-rank"><span><?= $escape($t('view.catalog.position')) ?></span><strong><?= $escape($entry['rank']) ?></strong></span>
                            <?php if ($isWatchedRanking): ?>
                                <span class="anime-ranking-watchers"><?= $escape($t('view.catalog.watchers_status')) ?> <strong><?= $escape($entry['watcherCount']) ?></strong></span>
                            <?php endif; ?>
                        </div>
                        <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>

    <?php if ($ranking['totalPages'] > 1): ?>
        <nav class="anime-ranking-pagination" aria-label="<?= $escape($t('view.catalog.ranking_pages')) ?>">
            <?php if ($previousUrl !== null): ?>
                <a href="<?= $escape($previousUrl) ?>" rel="prev"><?= $escape($t('view.catalog.previous')) ?></a>
            <?php endif; ?>
            <span><?= $escape($t('view.catalog.page_position', ['page' => $ranking['page'], 'pages' => $ranking['totalPages']])) ?></span>
            <?php if ($nextUrl !== null): ?>
                <a href="<?= $escape($nextUrl) ?>" rel="next"><?= $escape($t('view.catalog.next')) ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
