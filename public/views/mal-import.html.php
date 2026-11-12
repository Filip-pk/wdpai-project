<?php require __DIR__ . '/partials/header.html.php'; ?>
<?php
$statusLabels = [
    'planned' => 'Planowane',
    'watching' => 'Oglądam',
    'completed' => 'Ukończone',
    'on_hold' => 'Wstrzymane',
    'dropped' => 'Porzucone',
];
$actionLabels = $report !== null
    ? ['add' => 'Dodano', 'update' => 'Zaktualizowano', 'unchanged' => 'Bez zmian', 'skipped' => 'Pominięte']
    : ['add' => 'Dodajemy', 'update' => 'Aktualizujemy', 'unchanged' => 'Bez zmian', 'skipped' => 'Pominięte'];
$hasResult = $preview !== null || $report !== null;
$visibleRows = $preview !== null ? $previewRows : $reportRows;
$currentImportStep = $report !== null ? 3 : ($preview !== null ? 2 : 1);
$importSteps = [1 => 'Wczytaj plik', 2 => 'Sprawdź dane', 3 => 'Zapisano'];
?>

<div class="profile-page import-page">
    <?php require __DIR__ . '/partials/profile-tabs.html.php'; ?>

    <header class="profile-heading">
        <div class="import-heading-copy">
            <p class="eyebrow">Twoja historia oglądania</p>
            <h1>Import z MyAnimeList</h1>
            <p>Przenieś swoją listę anime, postępy i oceny. Najpierw sprawdzisz podgląd, a potem zatwierdzisz zapis na swoim koncie.</p>
        </div>
        <span class="import-private"><span aria-hidden="true">◇</span> Prywatny import</span>
    </header>

    <ol class="import-progress" aria-label="Etapy importu">
        <?php foreach ($importSteps as $stepNumber => $stepLabel): ?>
            <li<?= $stepNumber === $currentImportStep ? ' aria-current="step"' : '' ?><?= $stepNumber < $currentImportStep ? ' class="is-complete"' : '' ?>>
                <span class="import-progress-number" aria-hidden="true"><?= $stepNumber < $currentImportStep ? '✓' : $escape($stepNumber) ?></span>
                <span><?= $escape($stepLabel) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($notice !== null): ?>
        <p class="profile-notice" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="profile-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($report !== null): ?>
        <section class="import-panel import-report" aria-labelledby="import-report-title" data-import-report data-import-added="<?= $escape($report['added']) ?>" data-import-updated="<?= $escape($report['updated']) ?>" data-import-unchanged="<?= $escape($report['unchanged']) ?>" data-import-skipped="<?= $escape($report['skipped']) ?>" data-import-exp-before="<?= $escape($report['expBefore']) ?>" data-import-exp-after="<?= $escape($report['expAfter']) ?>">
            <div class="import-section-heading">
                <div>
                    <p class="import-step">Zapisano na Twoim koncie</p>
                    <h2 id="import-report-title">Import zakończony</h2>
                </div>
                <span class="import-private">Prywatna watchlista</span>
            </div>
            <dl class="import-counts">
                <div><dt>Dodane</dt><dd><?= $escape($report['added']) ?></dd></div>
                <div><dt>Zaktualizowane</dt><dd><?= $escape($report['updated']) ?></dd></div>
                <div><dt>Bez zmian</dt><dd><?= $escape($report['unchanged']) ?></dd></div>
                <div><dt>Pominięte</dt><dd><?= $escape($report['skipped']) ?></dd></div>
            </dl>
            <p class="import-exp">EXP: <strong><?= $escape(number_format($report['expBefore'], 0, ',', ' ')) ?></strong> <span aria-hidden="true">→</span> <strong><?= $escape(number_format($report['expAfter'], 0, ',', ' ')) ?></strong></p>
            <p class="import-help">Zapisane oceny: <?= $escape($report['ratingsSaved']) ?> · Usunięte oceny: <?= $escape($report['ratingsRemoved']) ?>. Szczegóły każdej pozycji znajdziesz poniżej.</p>
            <div class="import-actions">
                <a class="import-primary-link" href="/watchlist">Zobacz watchlistę <span aria-hidden="true">→</span></a>
                <a href="/ranking">Sprawdź swój poziom i ranking</a>
            </div>
        </section>
    <?php elseif ($preview !== null): ?>
        <section class="import-panel import-preview" aria-labelledby="import-preview-title" data-import-preview>
            <div class="import-section-heading">
                <div>
                    <p class="import-step">Sprawdź przed zapisaniem</p>
                    <h2 id="import-preview-title">Podgląd importu</h2>
                </div>
                <span class="import-private">Pozycje w pliku: <?= $escape($preview['total']) ?></span>
            </div>
            <dl class="import-counts">
                <div><dt>Do dodania</dt><dd data-import-count-add="<?= $escape($preview['counts']['add']) ?>"><?= $escape($preview['counts']['add']) ?></dd></div>
                <div><dt>Do aktualizacji</dt><dd data-import-count-update="<?= $escape($preview['counts']['update']) ?>"><?= $escape($preview['counts']['update']) ?></dd></div>
                <div><dt>Bez zmian</dt><dd data-import-count-unchanged="<?= $escape($preview['counts']['unchanged']) ?>"><?= $escape($preview['counts']['unchanged']) ?></dd></div>
                <div><dt>Pominięte</dt><dd data-import-count-skipped="<?= $escape($preview['counts']['skipped']) ?>"><?= $escape($preview['counts']['skipped']) ?></dd></div>
            </dl>
            <p class="import-policy"><?= $preview['updateExisting'] ? 'Wybrany tryb: aktualizacja także istniejących wpisów. Status, postęp i Twoja ocena zostaną zastąpione wartościami pokazanymi w podglądzie. Brak oceny w pliku usuwa poprzednią ocenę.' : 'Wybrany tryb: dodawanie brakujących tytułów. Istniejące postępy i oceny zostają zachowane.' ?></p>
            <p class="import-help">Dane zostaną zapisane po zatwierdzeniu. Podgląd jest ważny jeszcze przez około <?= $escape($expiresInMinutes) ?> min. Kolejny import tych samych postępów nie powiela EXP.</p>
            <?php if ($preview['counts']['add'] + $preview['counts']['update'] === 0): ?>
                <p class="import-help">Ten plik nie wprowadzi zmian. Możesz zakończyć import i zobaczyć raport albo wczytać inny plik.</p>
            <?php endif; ?>
            <div class="import-actions">
                <form action="/profile/import/confirm" method="post" class="import-confirm-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="importToken" value="<?= $escape($importToken) ?>">
                    <button type="submit">Zatwierdź import</button>
                </form>
                <form action="/profile/import/cancel" method="post" class="import-cancel-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="importToken" value="<?= $escape($importToken) ?>">
                    <button type="submit">Anuluj podgląd</button>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($hasResult): ?>
        <section class="import-entries" aria-labelledby="import-entries-title">
            <div class="import-section-heading">
                <h2 id="import-entries-title">Szczegóły pozycji</h2>
                <p class="import-help">Strona <?= $escape($pagination['page']) ?> z <?= $escape($pagination['totalPages']) ?></p>
            </div>
            <?php if ($visibleRows === []): ?>
                <p class="import-panel">Brak pozycji do wyświetlenia.</p>
            <?php else: ?>
                <ul class="import-entry-list">
                    <?php foreach ($visibleRows as $row): ?>
                        <li class="import-entry" data-import-mal-id="<?= $escape($row['malId'] ?? '') ?>" data-import-action="<?= $escape($row['action']) ?>">
                            <div class="import-entry-heading">
                                <div>
                                    <h3>
                                        <?php if ($row['animeId'] !== null): ?>
                                            <a href="/anime/<?= $escape($row['animeId']) ?>"><?= $escape($row['title'] === '' ? 'Anime bez tytułu' : $row['title']) ?></a>
                                        <?php else: ?>
                                            <?= $escape($row['title'] === '' ? 'Pozycja bez tytułu' : $row['title']) ?>
                                        <?php endif; ?>
                                    </h3>
                                    <p class="import-help">ID MAL: <?= $escape($row['malId'] ?? 'Brak') ?></p>
                                </div>
                                <span class="import-action-label" data-kind="<?= $escape($row['action']) ?>"><?= $escape($actionLabels[$row['action']] ?? 'Pominięte') ?></span>
                            </div>
                            <?php if ($row['status'] !== null): ?>
                                <dl class="import-entry-values">
                                    <div>
                                        <dt>Status</dt>
                                        <dd>
                                            <?php if ($row['action'] === 'update' && $row['currentStatus'] !== null && $row['currentStatus'] !== $row['status']): ?>
                                                <span class="import-before">Poprzednio: <?= $escape($statusLabels[$row['currentStatus']] ?? $row['currentStatus']) ?></span>
                                            <?php endif; ?>
                                            <strong><?= $escape($statusLabels[$row['status']] ?? $row['status']) ?></strong>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Obejrzane odcinki</dt>
                                        <dd>
                                            <?php if ($row['action'] === 'update' && $row['currentWatched'] !== null && $row['currentWatched'] !== $row['watched']): ?>
                                                <span class="import-before">Poprzednio: <?= $escape($row['currentWatched']) ?></span>
                                            <?php endif; ?>
                                            <strong><?= $escape($row['watched'] ?? 0) ?></strong>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Twoja ocena</dt>
                                        <dd>
                                            <?php if ($row['action'] === 'update' && $row['currentRating'] !== $row['rating']): ?>
                                                <span class="import-before">Poprzednio: <?= $row['currentRating'] !== null ? $escape($row['currentRating']) . ' / 10' : 'Brak oceny' ?></span>
                                            <?php endif; ?>
                                            <strong><?= $row['rating'] !== null ? $escape($row['rating']) . ' / 10' : 'Brak oceny' ?></strong>
                                        </dd>
                                    </div>
                                </dl>
                            <?php endif; ?>
                            <?php
                            $reason = $report === null ? $row['reason'] : strtr($row['reason'], [
                                'Tytuł zostanie dodany' => 'Tytuł został dodany',
                                'zostaną zastąpione' => 'zostały zastąpione',
                            ]);
                            ?>
                            <?php if ($reason !== ''): ?><p class="import-entry-reason"><?= $escape($reason) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($pagination['totalPages'] > 1): ?>
                <nav class="import-pagination" aria-label="Strony importu">
                    <?php if ($pagination['page'] > 1): ?>
                        <a href="/profile/import?page=<?= $escape($pagination['page'] - 1) ?>">← Poprzednia</a>
                    <?php endif; ?>
                    <span><?= $escape($pagination['page']) ?> / <?= $escape($pagination['totalPages']) ?></span>
                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                        <a href="/profile/import?page=<?= $escape($pagination['page'] + 1) ?>">Następna →</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($hasResult): ?>
        <details class="import-panel import-upload-panel">
            <summary><?= $report !== null ? 'Importuj kolejny plik' : 'Wczytaj inny plik' ?></summary>
    <?php else: ?>
        <section class="import-panel import-upload-panel" aria-labelledby="import-upload-title">
    <?php endif; ?>
        <div class="import-upload-content">
            <div class="import-upload-guide">
                <span class="import-upload-icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M12 12v6m-3-3 3-3 3 3"/></svg>
                </span>
                <h2 id="import-upload-title">Przygotuj swoją listę</h2>
                <ol class="import-instructions">
                    <li>Zaloguj się na MyAnimeList i otwórz <a href="https://myanimelist.net/panel.php?go=export" rel="external noreferrer">eksport listy</a>.</li>
                    <li>Wybierz <span lang="en">Anime List</span>, następnie <span lang="en">Export My List</span> i pobierz plik.</li>
                    <li>Wybierz go poniżej. Plik XML.GZ możesz wgrać bez rozpakowywania.</li>
                </ol>
                <p class="import-help">Przenosimy statusy, postęp i własne oceny. Lista pozostaje prywatna. EXP jest przeliczany na podstawie zapisanych postępów.</p>
            </div>
            <form action="/profile/import/preview" method="post" enctype="multipart/form-data" class="import-upload-form">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <div class="profile-field">
                    <label for="mal-file">Plik eksportu z MyAnimeList</label>
                    <input id="mal-file" name="malFile" type="file" accept=".xml,.gz,application/xml,text/xml,application/gzip" aria-describedby="mal-file-help" required>
                    <p id="mal-file-help">XML lub XML.GZ, do 10 MB. Maksymalnie 40 000 pozycji i 50 MB po rozpakowaniu.</p>
                </div>
                <div class="import-policy-choice">
                    <label for="mal-update-existing">
                        <input id="mal-update-existing" name="updateExisting" type="checkbox" value="1" aria-describedby="mal-update-help"<?= $updateExisting ? ' checked' : '' ?>>
                        <span>Zaktualizuj również istniejące wpisy</span>
                    </label>
                    <p id="mal-update-help">Ta opcja zastąpi status, postęp i Twoją ocenę wartościami z pliku. Ocena 0 usunie zapisaną ocenę. Domyślnie zachowujemy istniejące dane.</p>
                </div>
                <button type="submit">Pokaż podgląd importu</button>
            </form>
        </div>
    <?php if ($hasResult): ?></details><?php else: ?></section><?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
