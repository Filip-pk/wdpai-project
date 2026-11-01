<section aria-labelledby="watchlist-title">
    <h2 id="watchlist-title">Twoja watchlista</h2>

    <p>Liczba tytułów: <?= count($watchlist) ?></p>

    <form action="<?= $watchlistOnly ? '/watchlist' : '/profile' ?>" method="get" class="watchlist-toolbar">
        <label for="watchlist-status">Status oglądania</label>
        <select id="watchlist-status" name="status">
            <option value="">Wszystkie</option>
            <?php foreach ($watchlistStatuses as $value => $label): ?>
                <option value="<?= $escape($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filtruj</button>
    </form>

    <?php if ($visibleWatchlist === []): ?>
        <div class="profile-empty">
            <p><?= $watchlist === [] ? 'Twoja watchlista jest pusta. Wybierz pierwsze anime w katalogu.' : 'Brak anime o wybranym statusie.' ?></p>
            <a href="/anime">Odkryj anime</a>
        </div>
    <?php endif; ?>

    <ul class="anime-grid">
        <?php foreach ($visibleWatchlist as $entry): ?>
            <?php
            $animeId = $entry['animeId'];
            $anime = $animes[$animeId];
            ?>

            <li data-anime-id="<?= $escape($animeId) ?>" data-watchlist-status="<?= $escape($entry['status']) ?>" data-watched="<?= $escape($entry['watched']) ?>">
                <article class="watchlist-card">
                <?php require __DIR__ . '/anime-card.html.php'; ?>

                <p>Status: <?= $escape($watchlistStatuses[$entry['status']]) ?></p>

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
                <form action="/watchlist" method="post" class="watchlist-edit">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="animeId" value="<?= $escape($animeId) ?>">
                    <label for="entry-status-<?= $escape($animeId) ?>">Status</label>
                    <select id="entry-status-<?= $escape($animeId) ?>" name="status">
                        <?php foreach ($watchlistStatuses as $value => $label): ?>
                            <option value="<?= $escape($value) ?>" <?= $entry['status'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label for="entry-watched-<?= $escape($animeId) ?>">Obejrzane odcinki</label>
                    <input id="entry-watched-<?= $escape($animeId) ?>" name="watched" type="number" min="0" max="<?= $escape($anime['episodes']) ?>" value="<?= $escape($entry['watched']) ?>" required>
                    <button type="submit">Zapisz postęp</button>
                </form>
                <form action="/watchlist/remove" method="post" class="watchlist-remove">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="animeId" value="<?= $escape($animeId) ?>">
                    <button type="submit" aria-label="<?= $escape('Usuń z watchlisty: ' . $anime['title']) ?>">Usuń z listy</button>
                </form>
                </article>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
