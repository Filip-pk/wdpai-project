<?php require __DIR__ . '/partials/header.html.php'; ?>

<nav class="catalog-back" aria-label="Powrót do katalogu">
    <a href="/anime">← Wróć do katalogu</a>
</nav>

<article class="anime-details">
    <header class="anime-details-heading">
        <div class="anime-cover anime-detail-cover" aria-hidden="true">
            <span class="anime-cover-placeholder"><span>✦</span><span>Brak okładki</span></span>
            <?php if (!empty($anime['coverUrl'])): ?>
                <img data-anime-cover src="<?= $escape($anime['coverUrl']) ?>" alt="" width="300" height="450" decoding="async" referrerpolicy="no-referrer">
            <?php endif; ?>
        </div>
        <div class="anime-details-identity">
            <p class="eyebrow"><?= $escape(empty($anime['type']) || $anime['type'] === 'UNKNOWN' ? 'Poznaj serię' : $anime['type']) ?></p>
            <h1><?= $escape($anime['title']) ?></h1>
            <ul class="anime-genres" aria-label="Gatunki">
                <?php foreach ($anime['genres'] as $genreName): ?>
                    <li><?= $escape($genreName) ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Znajdź informacje o serii i dodaj ją do swojej kolekcji.</p>
        </div>
    </header>

    <section class="anime-details-section" aria-labelledby="synopsis-title">
        <div class="anime-section-heading">
            <h2 id="synopsis-title">Opis fabuły</h2>
            <?php if (($anime['descriptionLanguage'] ?? '') === 'en' && $anime['description'] !== ''): ?>
                <span class="anime-language">Opis w języku angielskim</span>
            <?php endif; ?>
        </div>
        <p class="anime-synopsis"<?= ($anime['descriptionLanguage'] ?? '') === 'en' && $anime['description'] !== '' ? ' lang="en"' : '' ?>><?= $escape($anime['description'] === '' ? 'Opis tego anime nie jest jeszcze dostępny.' : $anime['description']) ?></p>
    </section>

    <section class="anime-details-section" aria-labelledby="anime-facts-title">
        <h2 id="anime-facts-title">Informacje o serii</h2>

        <dl class="anime-detail-facts">
            <div><dt>Studio</dt><dd><?= $escape($anime['studio'] === '' ? 'Nieznane' : $anime['studio']) ?></dd></div>
            <div><dt>Rok premiery</dt><dd><?= isset($anime['year']) ? $escape($anime['year']) : 'Nieznany' ?></dd></div>
            <div><dt>Liczba odcinków</dt><dd><?= isset($anime['episodes']) ? $escape($anime['episodes']) : 'Nieznana' ?></dd></div>
            <div><dt><?= ($anime['malId'] ?? null) !== null ? 'Ocena MAL' : 'Ocena' ?></dt><dd><?= isset($anime['score']) ? $escape(number_format($anime['score'], 2, ',', '')) . ' / 10' : 'Brak oceny' ?></dd></div>
        </dl>
        <?php if (!empty($anime['sourceUrl']) && str_starts_with($anime['sourceUrl'], 'https://')): ?>
            <p class="anime-source">Dane o anime: <a href="<?= $escape($anime['sourceUrl']) ?>" rel="external noreferrer"><?= $escape($anime['sourceLabel']) ?></a>.</p>
        <?php endif; ?>
        <details class="anime-source">
            <summary>Źródła katalogu</summary>
            <p><a href="https://github.com/cedya77/anime-offline-database" rel="external noreferrer">Anime Offline Database — cedya77</a>,
                kontynuacja <a href="https://github.com/manami-project/anime-offline-database" rel="external noreferrer">manami-project</a>;
                opisy i oceny ze <a href="https://huggingface.co/datasets/moviebrain01/anime-dataset-2025" rel="external noreferrer">snapshotu moviebrain01</a>
                oraz <a href="https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime" rel="external noreferrer">TheRealVigilante / Quan Than</a>.</p>
        </details>
    </section>

    <section class="anime-details-section anime-collection-actions" aria-labelledby="anime-collections-title">
        <h2 id="anime-collections-title">Twoje prywatne kolekcje</h2>
        <?php if ($isLoggedIn): ?>
            <?php if ($collectionNotice !== null): ?>
                <p class="anime-collection-notice" data-kind="success" role="status"><?= $escape($collectionNotice) ?></p>
            <?php endif; ?>
            <?php if ($collectionErrors !== []): ?>
                <div class="anime-collection-notice" data-kind="error" role="alert">
                    <ul><?php foreach ($collectionErrors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <details class="anime-collection-picker"<?= $collectionErrors !== [] ? ' open' : '' ?>>
                <summary>Dodaj do kolekcji</summary>
                <form action="/anime/<?= $escape($animeId) ?>/collections" method="post" class="collection-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <fieldset class="collection-choices">
                        <legend>Wybierz jedną lub kilka swoich kolekcji</legend>
                        <?php foreach ($collections as $collection): ?>
                            <?php $alreadyAdded = in_array($collection['id'], $animeCollectionIds, true); ?>
                            <label class="collection-choice" for="anime-collection-<?= $escape($collection['id']) ?>">
                                <input id="anime-collection-<?= $escape($collection['id']) ?>" name="collectionIds[]" type="checkbox" value="<?= $escape($collection['id']) ?>"<?= $alreadyAdded || in_array($collection['id'], $selectedCollectionIds, true) ? ' checked' : '' ?><?= $alreadyAdded ? ' disabled' : '' ?>>
                                <span class="collection-choice-copy">
                                    <span class="collection-choice-name"><?= $escape($collection['name']) ?></span>
                                    <?php if ($alreadyAdded): ?><span class="collection-choice-added">Dodano</span><?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                    <button type="submit">Dodaj do wybranych kolekcji</button>
                </form>
                <p class="collection-help">Możesz dodać ten tytuł do wielu kolekcji. Zachowasz status i postęp na watchliście.</p>
            </details>

            <details class="anime-collection-picker"<?= $collectionForm['name'] !== '' || $collectionForm['description'] !== '' ? ' open' : '' ?>>
                <summary>Utwórz nową kolekcję</summary>
                <form action="/anime/<?= $escape($animeId) ?>/collections/create" method="post" class="collection-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="profile-field">
                        <label for="anime-collection-name">Nazwa kolekcji</label>
                        <input id="anime-collection-name" name="name" type="text" maxlength="120" value="<?= $escape($collectionForm['name']) ?>" placeholder="Np. Do obejrzenia ze znajomymi" required>
                    </div>
                    <div class="profile-field">
                        <label for="anime-collection-description">Krótki opis <span>(opcjonalnie)</span></label>
                        <textarea id="anime-collection-description" name="description" rows="3" maxlength="500"><?= $escape($collectionForm['description']) ?></textarea>
                    </div>
                    <button type="submit">Utwórz kolekcję i dodaj anime</button>
                </form>
            </details>
            <p><a href="/profile/collections">Przejdź do moich kolekcji →</a></p>
        <?php else: ?>
            <p><a href="/login">Zaloguj się</a>, aby tworzyć własne kolekcje i dodawać do nich anime.</p>
        <?php endif; ?>
    </section>

    <section class="anime-details-section" aria-labelledby="anime-actions-title">
        <h2 id="anime-actions-title">Twoja kolekcja i ocena</h2>

        <?php if ($isLoggedIn): ?>
            <form action="/watchlist" method="post">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="animeId" value="<?= $escape($animeId) ?>">
                <input type="hidden" name="mode" value="add">
                <button type="submit">Dodaj do watchlisty</button>
            </form>
        <?php else: ?>
            <p><a href="/login">Zaloguj się</a>, aby dodać anime do swojej watchlisty.</p>
        <?php endif; ?>

        <form action="/anime/review" method="post">
            <fieldset disabled>
                <legend>Twoja ocena</legend>

                <label for="anime-rating">
                    Oceń anime w skali od 1 do 10
                </label>

                <input
                    id="anime-rating"
                    name="rating"
                    type="number"
                    min="1"
                    max="10"
                    step="1"
                    required
                >

                <button type="submit">Zapisz ocenę</button>
            </fieldset>
        </form>

        <a href="/chat">Przejdź do asystenta AI</a>
    </section>

    <section class="anime-details-section" aria-labelledby="trailer-title">
        <h2 id="trailer-title">Podgląd serii</h2>

        <p>Zwiastun zostanie dodany w kolejnym etapie.</p>

        <button type="button" disabled>
            Obejrzyj zwiastun
        </button>
    </section>
</article>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
