<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="settings-page">
    <header class="settings-heading">
        <p class="eyebrow">Twoja przestrzeń</p>
        <h1>Ustawienia</h1>
        <p>Dostosuj AniVibe do siebie. Preferencje są zapisane na Twoim koncie.</p>
    </header>
    <?php if (isset($_SESSION['settings_notice'])): ?>
        <p class="settings-notice" role="status"><?= $escape($_SESSION['settings_notice']) ?></p>
        <?php unset($_SESSION['settings_notice']); ?>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="settings-notice settings-error" role="alert"><ul>
            <?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <div class="settings-layout">
        <div class="settings-sections">
            <section class="settings-card" id="appearance" aria-labelledby="appearance-title">
                <header><p class="eyebrow">Po Twojemu</p><h2 id="appearance-title">Wygląd</h2></header>
                <form method="post" action="/settings" data-appearance-form>
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <div class="settings-fields">
                        <?php foreach (['theme' => 'Motyw', 'font_size' => 'Rozmiar czcionki', 'line_height' => 'Interlinia', 'interface_density' => 'Gęstość interfejsu'] as $key => $label): ?>
                            <div class="settings-field">
                                <label for="settings-<?= $escape($key) ?>"><?= $escape($label) ?></label>
                                <select id="settings-<?= $escape($key) ?>" name="<?= $escape($key) ?>" data-preference="<?= $escape($key) ?>">
                                    <?php foreach ($appearanceChoices[$key] as $value => $choice): ?>
                                        <option value="<?= $escape($value) ?>"<?= ($form[$key] ?? '') === $value ? ' selected' : '' ?>><?= $escape($choice) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="settings-help">Podgląd zmienia się od razu. Użyj przycisku, aby zachować wybór na koncie.</p>
                    <div class="settings-actions"><button type="submit">Zapisz wygląd</button></div>
                </form>
                <form method="post" action="/settings/appearance/reset" class="settings-reset">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <button type="submit" class="settings-secondary">Przywróć domyślny wygląd</button>
                </form>
            </section>
        </div>

        <aside class="settings-preview settings-card" aria-labelledby="preview-title">
            <p class="eyebrow">Na żywo</p><h2 id="preview-title">Twój podgląd</h2>
            <p>Tak będzie wyglądać tekst w AniVibe. Każda historia zaczyna się od pierwszego odcinka — znajdź anime, do którego chcesz wracać.</p>
            <article class="settings-example-card">
                <span class="settings-example-art" aria-hidden="true">✦</span>
                <div><p class="eyebrow">Przykładowa karta</p><h3>Podróż w nieznane</h3><p>Przygoda · Fantasy · 12 odcinków</p></div>
                <button type="button" data-preview-button>Sprawdź wygląd przycisku</button>
            </article>
            <p class="settings-help">Podgląd przycisku jest przykładem. Zapisz ustawienia w formularzu obok.</p>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
