<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page collections-page">
    <?php require __DIR__ . '/partials/profile-tabs.html.php'; ?>

    <header class="profile-heading">
        <p class="eyebrow">Tylko dla Ciebie</p>
        <h1><?= $selectedCollection === null ? 'Moje kolekcje' : $escape($selectedCollection['name']) ?></h1>
        <p><?= $selectedCollection === null ? 'Porządkuj odkrycia po swojemu. Twórz prywatne listy na każdy anime nastrój.' : $escape($selectedCollection['description'] === '' ? 'Twoja prywatna lista anime.' : $selectedCollection['description']) ?></p>
    </header>

    <?php if ($notice !== null): ?>
        <p class="profile-notice" data-kind="success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="profile-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($selectedCollection === null): ?>
        <div class="collections-layout">
            <section class="collections-library" aria-labelledby="collections-title">
                <div class="collections-section-heading">
                    <h2 id="collections-title">Twoje listy</h2>
                    <span class="collection-private" data-collections-total="<?= $escape($collectionsPagination['total']) ?>">Prywatne · <?= $escape($collectionsPagination['total']) ?></span>
                </div>
                <ul class="collection-grid">
                    <?php foreach ($collections as $collection): ?>
                        <li>
                            <article class="collection-card" data-collection-id="<?= $escape($collection['id']) ?>">
                                <a class="collection-card-link" href="/profile/collections/<?= $escape($collection['id']) ?>">
                                    <div class="collection-previews" aria-hidden="true">
                                        <?php for ($previewIndex = 0; $previewIndex < 4; $previewIndex++): ?>
                                            <span class="collection-preview">
                                                <span>✦</span>
                                                <?php if (!empty($collection['previews'][$previewIndex]['coverUrl'])): ?>
                                                    <img data-anime-cover src="<?= $escape($collection['previews'][$previewIndex]['coverUrl']) ?>" alt="" width="120" height="180" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                                <?php endif; ?>
                                            </span>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="collection-card-copy">
                                        <span class="collection-private"><?= $collection['isDefault'] ? 'Kolekcja startowa · Prywatna' : 'Prywatna kolekcja' ?></span>
                                        <h3><?= $escape($collection['name']) ?></h3>
                                        <p class="collection-description"><?= $escape($collection['description'] === '' ? 'Dodaj anime i stwórz własny zestaw odkryć.' : $collection['description']) ?></p>
                                        <span class="collection-count">Liczba anime: <?= $escape($collection['animeCount']) ?> <span aria-hidden="true">→</span></span>
                                    </div>
                                </a>
                            </article>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($collectionsPagination['totalPages'] > 1): ?>
                    <nav class="collection-pagination" aria-label="Strony listy kolekcji">
                        <?php if ($collectionsPagination['page'] > 1): ?>
                            <a href="/profile/collections?page=<?= $escape($collectionsPagination['page'] - 1) ?>" rel="prev">← Poprzednia</a>
                        <?php endif; ?>
                        <span>Strona <?= $escape($collectionsPagination['page']) ?> z <?= $escape($collectionsPagination['totalPages']) ?></span>
                        <?php if ($collectionsPagination['page'] < $collectionsPagination['totalPages']): ?>
                            <a href="/profile/collections?page=<?= $escape($collectionsPagination['page'] + 1) ?>" rel="next">Następna →</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>

            <section class="collection-panel" aria-labelledby="collection-create-title">
                <h2 id="collection-create-title">Nowa kolekcja</h2>
                <p>Na weekend, ze znajomymi albo do ponownego obejrzenia — wybierz własną nazwę.</p>
                <form action="/profile/collections" method="post" class="collection-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="profile-field">
                        <label for="collection-name">Nazwa kolekcji</label>
                        <input id="collection-name" name="name" type="text" maxlength="120" value="<?= $escape($collectionForm['name']) ?>" placeholder="Np. Anime na weekend" required>
                    </div>
                    <div class="profile-field">
                        <label for="collection-description">Krótki opis <span>(opcjonalnie)</span></label>
                        <textarea id="collection-description" name="description" rows="3" maxlength="500"><?= $escape($collectionForm['description']) ?></textarea>
                    </div>
                    <button type="submit">Utwórz kolekcję</button>
                </form>
                <p class="collection-help">Kolekcje widzisz tylko Ty. Anime dodasz na stronie wybranego tytułu.</p>
            </section>
        </div>
    <?php else: ?>
        <div class="collection-toolbar">
            <a href="/profile/collections">← Wszystkie kolekcje</a>
            <span class="collection-private">Prywatna · Liczba anime: <?= $escape($pagination['total']) ?></span>
        </div>

        <details class="collection-panel collection-editor"<?= $editForm['name'] !== $selectedCollection['name'] || $editForm['description'] !== $selectedCollection['description'] ? ' open' : '' ?>>
            <summary>Edytuj nazwę i opis</summary>
            <form action="/profile/collections/<?= $escape($selectedCollection['id']) ?><?= $pagination['page'] > 1 ? '?page=' . $escape($pagination['page']) : '' ?>" method="post" class="collection-form">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <div class="profile-field">
                    <label for="collection-edit-name">Nazwa kolekcji</label>
                    <input id="collection-edit-name" name="name" type="text" maxlength="120" value="<?= $escape($editForm['name']) ?>" required>
                </div>
                <div class="profile-field">
                    <label for="collection-edit-description">Krótki opis <span>(opcjonalnie)</span></label>
                    <textarea id="collection-edit-description" name="description" rows="3" maxlength="500"><?= $escape($editForm['description']) ?></textarea>
                </div>
                <button type="submit">Zapisz kolekcję</button>
            </form>
        </details>

        <section class="collection-content" aria-labelledby="collection-anime-title">
            <div class="collections-section-heading">
                <h2 id="collection-anime-title">Anime w kolekcji</h2>
                <a href="/anime">Znajdź anime do kolekcji →</a>
            </div>
            <p class="collection-help">Ten sam tytuł może być w kilku kolekcjach. Status i postęp oglądania zapisujesz na watchliście.</p>

            <?php if ($collectionAnimes === []): ?>
                <div class="collection-empty">
                    <span aria-hidden="true">✦</span>
                    <h3>Tu zaczyna się Twoja kolekcja</h3>
                    <p>Otwórz dowolny tytuł w katalogu i wybierz „Dodaj do kolekcji”.</p>
                    <a href="/anime">Przeglądaj katalog anime →</a>
                </div>
            <?php else: ?>
                <ul class="collection-anime-grid">
                    <?php foreach ($collectionAnimes as $collectionAnimeId => $collectionAnime): ?>
                        <li>
                            <article class="collection-anime-card" data-collection-anime-id="<?= $escape($collectionAnimeId) ?>">
                                <a class="anime-cover collection-anime-cover" href="/anime/<?= $escape($collectionAnimeId) ?>" aria-label="<?= $escape('Szczegóły: ' . $collectionAnime['title']) ?>">
                                    <span class="anime-cover-placeholder" aria-hidden="true"><span>✦</span><span>Brak okładki</span></span>
                                    <?php if (!empty($collectionAnime['coverUrl'])): ?>
                                        <img data-anime-cover src="<?= $escape($collectionAnime['coverUrl']) ?>" alt="" width="200" height="300" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                    <?php endif; ?>
                                </a>
                                <div class="collection-anime-copy">
                                    <h3><a href="/anime/<?= $escape($collectionAnimeId) ?>"><?= $escape($collectionAnime['title']) ?></a></h3>
                                    <?php $entry = $collectionWatchlist[$collectionAnimeId] ?? null; ?>
                                    <?php if ($entry !== null): ?>
                                        <p class="collection-watchlist-status"><?= $escape(['planned' => 'Planowane', 'watching' => 'Oglądam', 'completed' => 'Ukończone', 'on_hold' => 'Wstrzymane', 'dropped' => 'Porzucone'][$entry['status']] ?? $entry['status']) ?></p>
                                        <p class="collection-help">Obejrzane odcinki: <?= $escape($entry['watched']) ?><?= ($collectionAnime['episodes'] ?? 0) > 0 ? ' / ' . $escape($collectionAnime['episodes']) : '' ?></p>
                                    <?php else: ?>
                                        <p class="collection-help">Jeszcze poza watchlistą</p>
                                    <?php endif; ?>
                                    <form action="/profile/collections/<?= $escape($selectedCollection['id']) ?>/remove<?= $pagination['page'] > 1 ? '?page=' . $escape($pagination['page']) : '' ?>" method="post" class="collection-remove-form">
                                        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="animeId" value="<?= $escape($collectionAnimeId) ?>">
                                        <button type="submit" aria-label="<?= $escape('Usuń z kolekcji: ' . $collectionAnime['title']) ?>">Usuń z tej kolekcji</button>
                                    </form>
                                </div>
                            </article>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($pagination['totalPages'] > 1): ?>
                <nav class="collection-pagination" aria-label="Strony kolekcji">
                    <?php if ($pagination['page'] > 1): ?>
                        <a href="/profile/collections/<?= $escape($selectedCollection['id']) ?>?page=<?= $escape($pagination['page'] - 1) ?>" rel="prev">← Poprzednia</a>
                    <?php endif; ?>
                    <span>Strona <?= $escape($pagination['page']) ?> z <?= $escape($pagination['totalPages']) ?></span>
                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                        <a href="/profile/collections/<?= $escape($selectedCollection['id']) ?>?page=<?= $escape($pagination['page'] + 1) ?>" rel="next">Następna →</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
