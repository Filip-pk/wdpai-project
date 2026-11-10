<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page profile-overview user-profile-page" data-profile-user-id="<?= $escape($profileUser['id']) ?>">
    <div class="user-profile-toolbar">
        <a class="user-profile-back" href="/ranking">← Ranking użytkowników</a>
        <?php if ($isOwnProfile): ?>
            <a class="profile-photo-link" href="/profile">Wróć do ustawień konta</a>
        <?php endif; ?>
    </div>
    <?php if ($blockNotice !== null): ?>
        <p class="profile-notice" role="status"><?= $escape($blockNotice) ?></p>
    <?php endif; ?>

    <div class="profile-grid">
        <div class="profile-sidebar">
            <header class="profile-summary user-profile-summary">
                <div class="profile-summary-top">
                    <div class="profile-avatar" aria-hidden="true">
                        <?php if ($profileUser['avatar_url'] !== null): ?>
                            <img src="<?= $escape($profileUser['avatar_url']) ?>" alt="" width="80" height="80" decoding="async">
                        <?php else: ?>
                            ✦
                        <?php endif; ?>
                    </div>
                    <span class="user-profile-badge">Profil publiczny</span>
                </div>
                <div class="profile-identity">
                    <p class="profile-card-label"><?= $isOwnProfile ? 'Moja przestrzeń' : 'Społeczność AniVibe' ?></p>
                    <h1><?= $escape($profileUser['name']) ?></h1>
                    <p class="user-profile-joined">Dołączono: <time datetime="<?= $escape(substr($profileUser['created_at'], 0, 10)) ?>"><?= $escape(substr($profileUser['created_at'], 0, 10)) ?></time></p>
                </div>

                <p class="profile-interests"><?= $escape($profileUser['interests'] === '' ? 'Ten użytkownik nie dodał jeszcze zainteresowań.' : $profileUser['interests']) ?></p>

                <div class="user-profile-actions">
                    <a class="profile-public-link" href="#watchlist-title">Zobacz watchlistę <span aria-hidden="true">↓</span></a>
                    <?php if ($isOwnProfile): ?>
                        <a class="user-profile-action" href="/profile">Edytuj profil</a>
                    <?php else: ?>
                        <?php if (!$blockState['blockedEitherWay']): ?>
                            <a class="user-profile-action" href="<?= $isLoggedIn ? '/messages/new/' . $escape($profileUser['id']) : '/login' ?>">Napisz wiadomość</a>
                        <?php else: ?>
                            <p>Wysyłanie wiadomości do tego konta jest niedostępne.</p>
                        <?php endif; ?>
                        <?php if ($isLoggedIn): ?>
                            <form class="community-inline-form" action="/users/<?= $escape($profileUser['id']) ?>/<?= $blockState['blockedByMe'] ? 'unblock' : 'block' ?>" method="post">
                                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                                <button type="submit" data-block-user-id="<?= $escape($profileUser['id']) ?>"><?= $blockState['blockedByMe'] ? 'Odblokuj użytkownika' : 'Zablokuj użytkownika' ?></button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </header>

            <?php
            $experienceTitle = $isOwnProfile ? 'Twój poziom' : 'Poziom użytkownika';
            require __DIR__ . '/partials/experience.html.php';
            ?>
        </div>

        <div class="user-profile-content">
            <section class="profile-summary user-profile-statistics" aria-labelledby="user-statistics-title">
                <header class="profile-editor-heading">
                    <span class="profile-editor-symbol" aria-hidden="true">♡</span>
                    <div>
                        <h2 id="user-statistics-title">Statystyki oglądania</h2>
                        <p>Anime, odcinki i ukończone historie.</p>
                    </div>
                </header>
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
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
