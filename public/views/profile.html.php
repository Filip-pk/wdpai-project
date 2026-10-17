<?php require __DIR__ . '/partials/header.html.php'; ?>

<h1><?= $watchlistOnly ? 'Ulubione anime' : 'Twój profil' ?></h1>

<?php if (!$watchlistOnly): ?>
    <section class="profile-summary" aria-labelledby="profile-title">
        <h2 id="profile-title"><?= $escape($user['name']) ?></h2>

        <p><?= $escape($user['email']) ?></p>

        <p>
            Rola konta demonstracyjnego:
            <?= $escape($user['role']) ?>
        </p>

        <p>Zainteresowania: cyberpunk, anime psychologiczne, mecha.</p>

        <dl>
            <dt>Tytuły na przykładowej liście</dt>
            <dd><?= count($watchlist) ?></dd>

            <dt>Ukończone serie na liście</dt>
            <dd><?= $escape($completedCount) ?></dd>
        </dl>
    </section>

    <section aria-labelledby="profile-edit-title">
        <h2 id="profile-edit-title">Edytuj profil</h2>

        <form
            action="/profile"
            method="post"
            enctype="multipart/form-data"
        >
            <fieldset disabled>
                <legend>Dane profilu</legend>

                <label for="profile-name">Nazwa użytkownika</label>

                <input
                    id="profile-name"
                    name="name"
                    type="text"
                    value="<?= $escape($user['name']) ?>"
                    maxlength="50"
                    autocomplete="nickname"
                    required
                >

                <label for="profile-avatar">Awatar</label>

                <input
                    id="profile-avatar"
                    name="avatar"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    aria-describedby="avatar-info"
                >

                <p id="avatar-info">
                    Docelowo: PNG, JPEG lub WebP, maksymalnie 4 MB.
                    Sprawdzanie pliku dodamy po stronie backendu.
                </p>

                <button type="submit">Zapisz profil</button>
            </fieldset>
        </form>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/partials/watchlist.html.php'; ?>

<aside aria-labelledby="import-title">
    <h2 id="import-title">Synchronizacja MyAnimeList</h2>

    <p>
        Opcjonalna funkcja z makiety.
        Na tym etapie import nie jest dostępny.
    </p>

    <button type="button" disabled>Synchronizuj</button>
</aside>

<?php require __DIR__ . '/partials/footer.html.php'; ?>