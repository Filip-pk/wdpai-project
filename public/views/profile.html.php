<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="profile-page<?= $watchlistOnly ? ' watchlist-page' : ' profile-account profile-overview' ?>">
    <header class="profile-heading<?= $watchlistOnly ? ' watchlist-heading' : '' ?>">
        <p class="eyebrow">Twoje konto AniVibe</p>
        <h1><?= $watchlistOnly ? 'Ulubione anime' : 'Twój profil' ?></h1>
        <p><?= $watchlistOnly ? 'Twoje serie, statusy i postępy oglądania.' : 'Zarządzaj swoimi danymi i ustawieniami.' ?></p>
        <?php if ($watchlistOnly): ?>
            <dl class="watchlist-overview-stats">
                <div><dt>Na Twojej liście</dt><dd data-watchlist-count="<?= $escape($watchlistTotal) ?>"><?= $escape($watchlistTotal) ?></dd></div>
                <div><dt>Ukończone serie</dt><dd data-completed-count="<?= $escape($completedCount) ?>"><?= $escape($completedCount) ?></dd></div>
            </dl>
        <?php endif; ?>
    </header>

    <?php require __DIR__ . '/partials/profile-tabs.html.php'; ?>

    <?php if ($saved): ?>
        <p class="profile-notice" data-kind="success" role="status">Profil i ustawienia zostały zapisane.</p>
    <?php endif; ?>
    <?php if ($avatarSaved ?? false): ?>
        <p class="profile-notice" data-kind="success" role="status">Zdjęcie profilowe zostało zapisane.</p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="profile-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($watchlistOnly): ?>
        <?php require __DIR__ . '/partials/experience.html.php'; ?>
    <?php endif; ?>

    <?php if (!$watchlistOnly): ?>
        <div class="profile-grid">
            <div class="profile-sidebar">
                <section class="profile-summary" aria-labelledby="profile-title">
                    <div class="profile-summary-top">
                        <a class="profile-avatar" href="/users/<?= $escape($user['id']) ?>" aria-label="<?= $escape('Zobacz profil: ' . $user['name']) ?>">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?= $escape($user['avatar_url']) ?>" alt="" width="80" height="80" decoding="async">
                            <?php else: ?>
                                ✦
                            <?php endif; ?>
                        </a>
                        <a class="profile-photo-link" href="#profile-avatar-title">Zmień zdjęcie</a>
                    </div>
                    <div class="profile-identity">
                        <p class="profile-card-label">Moja przestrzeń</p>
                        <h2 id="profile-title"><a href="/users/<?= $escape($user['id']) ?>"><?= $escape($user['name']) ?></a></h2>
                        <p class="profile-email"><?= $escape($user['email']) ?></p>
                    </div>
                    <p class="profile-interests"><?= $escape($user['interests'] === '' ? 'Dodaj zainteresowania, aby opowiedzieć o swoim anime klimacie.' : $user['interests']) ?></p>
                    <dl class="profile-stats">
                        <div><dt>Tytuły na Twojej liście</dt><dd data-watchlist-count="<?= $escape($watchlistTotal) ?>"><?= $escape($watchlistTotal) ?></dd></div>
                        <div><dt>Ukończone serie</dt><dd data-completed-count="<?= $escape($completedCount) ?>"><?= $escape($completedCount) ?></dd></div>
                    </dl>
                    <a class="profile-public-link" href="/users/<?= $escape($user['id']) ?>">Zobacz profil publiczny <span aria-hidden="true">↗</span></a>
                </section>

                <?php require __DIR__ . '/partials/experience.html.php'; ?>
            </div>

            <section class="profile-editor" aria-labelledby="profile-edit-title">
                <header class="profile-editor-heading">
                    <span class="profile-editor-symbol" aria-hidden="true">⚙</span>
                    <div>
                        <h2 id="profile-edit-title">Dane i ustawienia</h2>
                        <p>Twój profil, na Twoich zasadach.</p>
                    </div>
                </header>
                <form action="/profile" method="post" class="profile-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="profile-field">
                        <label for="profile-name">Nazwa użytkownika</label>
                        <input id="profile-name" name="name" type="text" value="<?= $escape($form['name']) ?>" maxlength="120" autocomplete="nickname" required>
                    </div>
                    <div class="profile-field">
                        <label for="profile-email">E-mail</label>
                        <input id="profile-email" name="email" type="email" value="<?= $escape($form['email']) ?>" maxlength="255" autocomplete="email" required>
                    </div>
                    <div class="profile-field profile-field-wide">
                        <label for="profile-current-password">Aktualne hasło</label>
                        <input id="profile-current-password" name="currentPassword" type="password" autocomplete="current-password" aria-describedby="profile-password-help">
                        <p id="profile-password-help">Wymagane tylko przy zmianie adresu e-mail.</p>
                    </div>
                    <div class="profile-field profile-field-wide">
                        <label for="profile-interests">Zainteresowania</label>
                        <textarea id="profile-interests" name="interests" rows="4" maxlength="1000" placeholder="Ulubione gatunki, serie i postacie…"><?= $escape($form['interests']) ?></textarea>
                    </div>
                    <div class="profile-field profile-field-wide">
                        <label for="profile-theme">Motyw aplikacji</label>
                        <select id="profile-theme" name="theme">
                            <?php foreach (['system' => 'Zgodny z systemem', 'light' => 'Jasny', 'dark' => 'Ciemny'] as $value => $label): ?>
                                <option value="<?= $escape($value) ?>" <?= $form['theme'] === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p>Twój wybór zostanie zapisany na koncie.</p>
                    </div>
                    <div class="profile-actions"><button type="submit">Zapisz profil</button></div>
                </form>
                <form action="/profile/avatar" method="post" enctype="multipart/form-data" class="profile-avatar-upload" aria-labelledby="profile-avatar-title">
                    <h3 id="profile-avatar-title">Zdjęcie profilowe</h3>
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <label for="profile-avatar">Wybierz zdjęcie</label>
                    <input id="profile-avatar" name="avatar" type="file" accept="image/png,image/jpeg" aria-describedby="avatar-info" required>
                    <p id="avatar-info">JPEG lub PNG, do 4 MB. Zdjęcie będzie widoczne w Twoim profilu i przy nazwie konta.</p>
                    <div class="profile-actions"><button type="submit">Zapisz zdjęcie</button></div>
                </form>
            </section>
        </div>
    <?php endif; ?>

    <div class="profile-watchlist"><?php require __DIR__ . '/partials/watchlist.html.php'; ?></div>

    <aside class="profile-empty" aria-labelledby="import-title">
        <h2 id="import-title">Import z MyAnimeList</h2>
        <p>Wgraj eksport swojej listy XML lub XML.GZ. Sprawdź podgląd, a następnie przenieś statusy, obejrzane odcinki i własne oceny.</p>
        <a href="/profile/import">Importuj z MyAnimeList <span aria-hidden="true">→</span></a>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
