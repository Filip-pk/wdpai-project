<?php require __DIR__ . '/partials/header.html.php'; ?>

<header class="page-heading">
    <h1>Kreator postaci AI</h1>

    <p>Zdefiniuj postać i instrukcje dla modelu Ollama.</p>

    <p>
        Podgląd formularza administratora.
        Nie przyznaje uprawnień ADMIN i nie zapisuje danych.
    </p>
</header>

<section aria-labelledby="existing-personas-title">
    <h2 id="existing-personas-title">Przykładowe persony</h2>

    <ul>
        <?php foreach ($personas as $persona): ?>
            <li>
                <?= $escape($persona['name']) ?>
                —
                <?= $escape($persona['anime']) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<form
    action="/admin/personas"
    method="post"
    enctype="multipart/form-data"
>
    <fieldset disabled>
        <legend>Tożsamość postaci</legend>

        <label for="persona-avatar">Awatar postaci</label>

        <input
            id="persona-avatar"
            name="avatar"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            aria-describedby="persona-avatar-info"
        >

        <p id="persona-avatar-info">
            Docelowo: PNG, JPEG lub WebP, maksymalnie 4 MB.
        </p>

        <label for="persona-name">Imię postaci</label>

        <input
            id="persona-name"
            name="name"
            type="text"
            maxlength="100"
            placeholder="Naruto Uzumaki"
            required
        >

        <label for="persona-anime">Anime pochodzenia</label>

        <select id="persona-anime" name="anime_id" required>
            <option value="">Wybierz anime</option>

            <?php foreach ($animes as $animeId => $anime): ?>
                <option value="<?= $escape($animeId) ?>">
                    <?= $escape($anime['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </fieldset>

    <fieldset disabled>
        <legend>Instrukcje dla Ollamy</legend>

        <label for="system-prompt">System prompt</label>

        <textarea
            id="system-prompt"
            name="system_prompt"
            rows="10"
            maxlength="4000"
            required
        >Jesteś Naruto Uzumaki. Mów po polsku, z entuzjazmem. Wspominaj o treningach, przyjaźni i ramenie. Rozmowa jest fikcyjnym odgrywaniem postaci.</textarea>

        <label for="temperature">
            Temperatura — kreatywność odpowiedzi
        </label>

        <input
            id="temperature"
            name="temperature"
            type="number"
            min="0"
            max="1.5"
            step="0.05"
            value="0.7"
        >

        <label for="context-size">Rozmiar kontekstu</label>

        <select id="context-size" name="context_size">
            <option value="1024">1024 tokeny</option>
            <option value="4096" selected>4096 tokenów</option>
            <option value="8192">8192 tokeny</option>
        </select>

        <button type="button">Testuj personę</button>
        <button type="submit">Zapisz postać</button>
    </fieldset>
</form>

<a href="/dashboard">Wróć do pulpitu</a>

<?php require __DIR__ . '/partials/footer.html.php'; ?>