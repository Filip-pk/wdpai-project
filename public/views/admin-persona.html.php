<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-creator">
    <header class="page-heading social-hero">
        <div>
            <p class="social-kicker">Twoja postać · Twoja historia</p>
            <h1 class="social-title">Kreator postaci AI</h1>
            <p class="social-description">Zdefiniuj postać i instrukcje dla modelu Ollama.</p>
        </div>
        <span class="social-pill">Podgląd kreatora</span>
    </header>

    <p id="creator-demo-notice" class="ai-demo-notice"><span aria-hidden="true">◇</span> Podgląd formularza administratora. Nie przyznaje uprawnień ADMIN i nie zapisuje danych.</p>

    <div class="ai-layout">
        <form action="/admin/personas" method="post" enctype="multipart/form-data" class="ai-creator-form">
            <fieldset disabled class="ai-creator-fields" aria-describedby="creator-demo-notice">
                <legend>Tożsamość postaci</legend>
                <div class="ai-creator-field">
                    <label for="persona-avatar">Awatar postaci</label>
                    <input id="persona-avatar" name="avatar" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="persona-avatar-info">
                    <p id="persona-avatar-info">Docelowo: PNG, JPEG lub WebP, maksymalnie 4 MB.</p>
                </div>
                <div class="ai-creator-field">
                    <label for="persona-name">Imię postaci</label>
                    <input id="persona-name" name="name" type="text" maxlength="100" placeholder="Naruto Uzumaki" required>
                </div>
                <div class="ai-creator-field">
                    <label for="persona-anime">Anime pochodzenia</label>
                    <select id="persona-anime" name="anime_id" required>
                        <option value="">Wybierz anime</option>
                        <?php foreach ($animes as $animeId => $anime): ?>
                            <option value="<?= $escape($animeId) ?>"><?= $escape($anime['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </fieldset>
            <fieldset disabled class="ai-creator-fields" aria-describedby="creator-demo-notice">
                <legend>Instrukcje dla Ollamy</legend>
                <div class="ai-creator-field">
                    <label for="system-prompt">System prompt</label>
                    <textarea id="system-prompt" name="system_prompt" rows="10" maxlength="4000" required>Jesteś Naruto Uzumaki. Mów po polsku, z entuzjazmem. Wspominaj o treningach, przyjaźni i ramenie. Rozmowa jest fikcyjnym odgrywaniem postaci.</textarea>
                </div>
                <div class="ai-creator-field">
                    <label for="temperature">Temperatura — kreatywność odpowiedzi</label>
                    <input id="temperature" name="temperature" type="number" min="0" max="1.5" step="0.05" value="0.7">
                </div>
                <div class="ai-creator-field">
                    <label for="context-size">Rozmiar kontekstu</label>
                    <select id="context-size" name="context_size">
                        <option value="1024">1024 tokeny</option>
                        <option value="4096" selected>4096 tokenów</option>
                        <option value="8192">8192 tokeny</option>
                    </select>
                </div>
                <div class="ai-creator-actions">
                    <button type="button">Testuj personę</button>
                    <button type="submit" class="ai-creator-submit">Zapisz postać</button>
                </div>
            </fieldset>
        </form>

        <section class="ai-panel ai-personas-panel" aria-labelledby="existing-personas-title">
            <h2 id="existing-personas-title">Przykładowe persony</h2>
            <ul class="ai-persona-list">
                <?php foreach ($personas as $persona): ?>
                    <li>
                        <span class="ai-persona-avatar" aria-hidden="true">✦</span>
                        <div>
                            <strong><?= $escape($persona['name']) ?></strong>
                            <p><?= $escape($persona['anime']) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    </div>
    <a href="/dashboard" class="ai-back">← Wróć do pulpitu</a>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
