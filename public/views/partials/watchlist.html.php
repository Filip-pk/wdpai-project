<section aria-labelledby="watchlist-title">
    <h2 id="watchlist-title">Twoja watchlista</h2>

    <p>Liczba tytułów: <?= count($watchlist) ?></p>

    <label for="watchlist-status">Status oglądania</label>

    <select
        id="watchlist-status"
        name="status"
        disabled
        aria-describedby="watchlist-filter-info"
    >
        <option>Wszystkie</option>
        <option>Oglądam</option>
        <option>Planowane</option>
        <option>Ukończone</option>
    </select>

    <p id="watchlist-filter-info">
        Filtrowanie listy dodamy w kolejnym etapie.
    </p>

    <ul class="anime-grid">
        <?php foreach ($watchlist as $entry): ?>
            <?php
            $animeId = $entry['animeId'];
            $anime = $animes[$animeId];
            ?>

            <li>
                <?php require __DIR__ . '/anime-card.html.php'; ?>

                <p>Status: <?= $escape($entry['status']) ?></p>

                <label for="progress-<?= $escape($animeId) ?>">
                    Obejrzane:
                    <?= $escape($entry['watched']) ?>
                    /
                    <?= $escape($anime['episodes']) ?>
                    odcinków
                </label>

                <progress
                    id="progress-<?= $escape($animeId) ?>"
                    value="<?= $escape($entry['watched']) ?>"
                    max="<?= $escape($anime['episodes']) ?>"
                >
                    <?= $escape($entry['watched']) ?> odcinków
                </progress>
            </li>
        <?php endforeach; ?>
    </ul>
</section>