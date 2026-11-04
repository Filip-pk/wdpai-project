<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page user-profile-page" data-profile-user-id="<?= $escape($profileUser['id']) ?>">
    <a class="user-profile-back" href="/ranking">← Ranking użytkowników</a>

    <header class="profile-summary user-profile-summary">
        <div class="user-profile-identity">
            <div class="profile-avatar" aria-hidden="true">
                <?php if ($profileUser['avatar_url'] !== null): ?>
                    <img src="<?= $escape($profileUser['avatar_url']) ?>" alt="" width="72" height="72" decoding="async">
                <?php else: ?>
                    ✦
                <?php endif; ?>
            </div>
            <div class="profile-heading">
                <p class="eyebrow">Profil użytkownika AniVibe</p>
                <h1><?= $escape($profileUser['name']) ?></h1>
                <p>Dołączono: <time datetime="<?= $escape(substr($profileUser['created_at'], 0, 10)) ?>"><?= $escape(substr($profileUser['created_at'], 0, 10)) ?></time></p>
            </div>
        </div>

        <p class="profile-interests"><?= $escape($profileUser['interests'] === '' ? 'Ten użytkownik nie dodał jeszcze zainteresowań.' : $profileUser['interests']) ?></p>

        <div class="user-profile-actions">
            <a href="#watchlist-title">Zobacz watchlistę</a>
            <?php if ($isOwnProfile): ?>
                <a class="user-profile-action" href="/profile">Edytuj profil</a>
            <?php else: ?>
                <button class="user-profile-action profile-message-button" type="button" data-open-message aria-haspopup="dialog" aria-controls="profile-message-dialog" hidden>Napisz wiadomość</button>
            <?php endif; ?>
        </div>

        <?php if (!$isOwnProfile): ?>
            <details class="profile-message-fallback" data-message-fallback>
                <summary>Napisz wiadomość</summary>
                <p>Prywatne wiadomości nie są jeszcze dostępne. Wysyłanie zostanie udostępnione w kolejnej wersji aplikacji.</p>
            </details>
        <?php endif; ?>
    </header>

    <?php
    $experienceTitle = $isOwnProfile ? 'Twój poziom' : 'Poziom użytkownika';
    require __DIR__ . '/partials/experience.html.php';
    ?>

    <section class="profile-summary user-profile-statistics" aria-labelledby="user-statistics-title">
        <h2 id="user-statistics-title">Statystyki oglądania</h2>
        <dl class="profile-stats">
            <div><dt>Tytuły na watchliście</dt><dd data-watchlist-count="<?= $escape($statistics['watchlistTotal']) ?>"><?= $escape($statistics['watchlistTotal']) ?></dd></div>
            <div><dt>Ukończone tytuły</dt><dd data-completed-count="<?= $escape($statistics['completedCount']) ?>"><?= $escape($statistics['completedCount']) ?></dd></div>
            <div><dt>W trakcie oglądania</dt><dd data-watching-count="<?= $escape($statistics['watchingCount']) ?>"><?= $escape($statistics['watchingCount']) ?></dd></div>
            <div><dt>Obejrzane odcinki</dt><dd data-watched-episodes="<?= $escape($statistics['watchedEpisodes']) ?>"><?= $escape(number_format($statistics['watchedEpisodes'], 0, ',', ' ')) ?></dd></div>
        </dl>
        <p>Statystyki obejmują całą aktualną watchlistę. EXP uwzględnia również postępy zachowane po usunięciu tytułu z listy.</p>
    </section>

    <div class="profile-watchlist">
        <section aria-labelledby="watchlist-title">
            <h2 id="watchlist-title">Watchlista użytkownika</h2>
            <p>Liczba tytułów: <?= $escape($statistics['watchlistTotal']) ?><?php if ($statusFilter !== ''): ?> · W wybranym statusie: <?= $escape($watchlistPagination['total']) ?><?php endif; ?></p>

            <form action="<?= $escape($profilePath) ?>" method="get" class="watchlist-toolbar">
                <label for="watchlist-status">Status oglądania</label>
                <select id="watchlist-status" name="status">
                    <option value="">Wszystkie</option>
                    <?php foreach ($watchlistStatuses as $value => $label): ?>
                        <option value="<?= $escape($value) ?>"<?= $statusFilter === $value ? ' selected' : '' ?>><?= $escape($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Filtruj</button>
            </form>

            <?php if ($visibleWatchlist === []): ?>
                <p class="profile-empty"><?= $statistics['watchlistTotal'] === 0 ? 'Ten użytkownik nie dodał jeszcze anime do watchlisty.' : 'Brak anime o wybranym statusie.' ?></p>
            <?php else: ?>
                <ul class="anime-grid">
                    <?php foreach ($visibleWatchlist as $entry): ?>
                        <?php
                        $animeId = $entry['animeId'];
                        $anime = $animes[$animeId];
                        ?>
                        <li data-anime-id="<?= $escape($animeId) ?>" data-watchlist-status="<?= $escape($entry['status']) ?>" data-watched="<?= $escape($entry['watched']) ?>">
                            <article class="watchlist-card">
                                <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
                                <p>Status: <?= $escape($watchlistStatuses[$entry['status']]) ?></p>
                                <?php if ($anime['episodes'] !== null && $anime['episodes'] > 0): ?>
                                    <label for="user-progress-<?= $escape($animeId) ?>">Obejrzane: <?= $escape($entry['watched']) ?> / <?= $escape($anime['episodes']) ?> odcinków</label>
                                    <progress id="user-progress-<?= $escape($animeId) ?>" value="<?= $escape($entry['watched']) ?>" max="<?= $escape($anime['episodes']) ?>"><?= $escape($entry['watched']) ?> odcinków</progress>
                                <?php else: ?>
                                    <p>Obejrzane: <?= $escape($entry['watched']) ?> odcinków · Łączna liczba odcinków nieznana</p>
                                <?php endif; ?>
                            </article>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($watchlistPagination['totalPages'] > 1): ?>
                <nav class="watchlist-pagination" aria-label="Strony watchlisty użytkownika">
                    <?php if ($watchlistPagination['page'] > 1): ?>
                        <a href="<?= $escape($watchlistPagination['previousUrl']) ?>" rel="prev">← Poprzednia</a>
                    <?php endif; ?>
                    <span>Strona <?= $escape($watchlistPagination['page']) ?> z <?= $escape($watchlistPagination['totalPages']) ?></span>
                    <?php if ($watchlistPagination['page'] < $watchlistPagination['totalPages']): ?>
                        <a href="<?= $escape($watchlistPagination['nextUrl']) ?>" rel="next">Następna →</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>

    <?php if (!$isOwnProfile): ?>
        <dialog id="profile-message-dialog" class="profile-message-dialog" aria-labelledby="profile-message-title" aria-describedby="profile-message-info" hidden>
            <form method="dialog" class="profile-message-form">
                <div class="profile-message-heading">
                    <h2 id="profile-message-title">Napisz wiadomość</h2>
                    <button type="submit" value="cancel" aria-label="Zamknij okno wiadomości">Zamknij</button>
                </div>
                <p>Do: <strong><?= $escape($profileUser['name']) ?></strong></p>
                <p id="profile-message-info" class="profile-notice">Prywatne wiadomości nie są jeszcze dostępne. To podgląd formularza — treść nie jest wysyłana ani zapisywana.</p>
                <label for="profile-message-content">Treść wiadomości</label>
                <textarea id="profile-message-content" rows="5" maxlength="2000" placeholder="Wiadomości będą dostępne w kolejnej wersji…" aria-describedby="profile-message-info"></textarea>
                <button type="button" disabled>Wyślij wiadomość</button>
            </form>
        </dialog>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
