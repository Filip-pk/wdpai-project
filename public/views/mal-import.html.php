<?php require __DIR__ . '/partials/header.html.php'; ?>
<?php
$statusLabels = [
    'planned' => $t('view.catalog.status_planned'),
    'watching' => $t('view.catalog.status_watching'),
    'completed' => $t('view.catalog.status_completed'),
    'on_hold' => $t('view.catalog.status_on_hold'),
    'dropped' => $t('view.catalog.status_dropped'),
];
$actionLabels = $report !== null
    ? ['add' => $t('view.catalog.added'), 'update' => $t('view.catalog.import_updated'), 'unchanged' => $t('view.catalog.import_unchanged'), 'skipped' => $t('view.catalog.import_skipped')]
    : ['add' => $t('view.catalog.import_adding'), 'update' => $t('view.catalog.import_updating'), 'unchanged' => $t('view.catalog.import_unchanged'), 'skipped' => $t('view.catalog.import_skipped')];
$hasResult = $preview !== null || $report !== null;
$visibleRows = $preview !== null ? $previewRows : $reportRows;
$currentImportStep = $report !== null ? 3 : ($preview !== null ? 2 : 1);
$importSteps = [1 => $t('view.catalog.import_load'), 2 => $t('view.catalog.import_check'), 3 => $t('view.catalog.import_saved')];
?>

<div class="profile-page import-page">
    <?php require __DIR__ . '/partials/profile-tabs.html.php'; ?>

    <header class="profile-heading">
        <div class="import-heading-copy">
            <p class="eyebrow"><?= $escape($t('view.catalog.watching_history')) ?></p>
            <h1><?= $escape($t('view.catalog.import_mal_title')) ?></h1>
            <p><?= $escape($t('view.catalog.import_intro')) ?></p>
        </div>
        <span class="import-private"><span aria-hidden="true">◇</span> <?= $escape($t('view.catalog.private_import')) ?></span>
    </header>

    <ol class="import-progress" aria-label="<?= $escape($t('view.catalog.import_steps')) ?>">
        <?php foreach ($importSteps as $stepNumber => $stepLabel): ?>
            <li<?= $stepNumber === $currentImportStep ? ' aria-current="step"' : '' ?><?= $stepNumber < $currentImportStep ? ' class="is-complete"' : '' ?>>
                <span class="import-progress-number" aria-hidden="true"><?= $stepNumber < $currentImportStep ? '✓' : $escape($stepNumber) ?></span>
                <span><?= $escape($stepLabel) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>

    <?php if ($notice !== null): ?>
        <p class="profile-notice" role="status"><?= $escape($translateText($notice)) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="profile-notice" data-kind="error" role="alert">
            <ul><?php foreach ($errors as $error): ?><li><?= $escape($translateText($error)) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <?php if ($report !== null): ?>
        <section class="import-panel import-report" aria-labelledby="import-report-title" data-import-report data-import-added="<?= $escape($report['added']) ?>" data-import-updated="<?= $escape($report['updated']) ?>" data-import-unchanged="<?= $escape($report['unchanged']) ?>" data-import-skipped="<?= $escape($report['skipped']) ?>" data-import-exp-before="<?= $escape($report['expBefore']) ?>" data-import-exp-after="<?= $escape($report['expAfter']) ?>">
            <div class="import-section-heading">
                <div>
                    <p class="import-step"><?= $escape($t('view.catalog.saved_account')) ?></p>
                    <h2 id="import-report-title"><?= $escape($t('view.catalog.import_complete')) ?></h2>
                </div>
                <span class="import-private"><?= $escape($t('view.catalog.private_watchlist')) ?></span>
            </div>
            <dl class="import-counts">
                <div><dt><?= $escape($t('view.catalog.import_added_plural')) ?></dt><dd><?= $escape($report['added']) ?></dd></div>
                <div><dt><?= $escape($t('view.catalog.import_updated_plural')) ?></dt><dd><?= $escape($report['updated']) ?></dd></div>
                <div><dt><?= $escape($t('view.catalog.import_unchanged')) ?></dt><dd><?= $escape($report['unchanged']) ?></dd></div>
                <div><dt><?= $escape($t('view.catalog.import_skipped')) ?></dt><dd><?= $escape($report['skipped']) ?></dd></div>
            </dl>
            <p class="import-exp">EXP: <strong><?= $escape(number_format($report['expBefore'], 0, ',', ' ')) ?></strong> <span aria-hidden="true">→</span> <strong><?= $escape(number_format($report['expAfter'], 0, ',', ' ')) ?></strong></p>
            <p class="import-help"><?= $escape($t('view.catalog.import_rating_summary', ['saved' => $report['ratingsSaved'], 'removed' => $report['ratingsRemoved']])) ?></p>
            <div class="import-actions">
                <a class="import-primary-link" href="/watchlist"><?= $escape($t('view.catalog.see_watchlist')) ?> <span aria-hidden="true">→</span></a>
                <a href="/ranking"><?= $escape($t('view.catalog.check_level_ranking')) ?></a>
            </div>
        </section>
    <?php elseif ($preview !== null): ?>
        <section class="import-panel import-preview" aria-labelledby="import-preview-title" data-import-preview>
            <div class="import-section-heading">
                <div>
                    <p class="import-step"><?= $escape($t('view.catalog.check_before_save')) ?></p>
                    <h2 id="import-preview-title"><?= $escape($t('view.catalog.import_preview')) ?></h2>
                </div>
                <span class="import-private"><?= $escape($t('view.catalog.file_entries')) ?> <?= $escape($preview['total']) ?></span>
            </div>
            <dl class="import-counts">
                <div><dt><?= $escape($t('view.catalog.to_add')) ?></dt><dd data-import-count-add="<?= $escape($preview['counts']['add']) ?>"><?= $escape($preview['counts']['add']) ?></dd></div>
                <div><dt><?= $escape($t('view.catalog.to_update')) ?></dt><dd data-import-count-update="<?= $escape($preview['counts']['update']) ?>"><?= $escape($preview['counts']['update']) ?></dd></div>
                <div><dt><?= $escape($t('view.catalog.import_unchanged')) ?></dt><dd data-import-count-unchanged="<?= $escape($preview['counts']['unchanged']) ?>"><?= $escape($preview['counts']['unchanged']) ?></dd></div>
                <div><dt><?= $escape($t('view.catalog.import_skipped')) ?></dt><dd data-import-count-skipped="<?= $escape($preview['counts']['skipped']) ?>"><?= $escape($preview['counts']['skipped']) ?></dd></div>
            </dl>
            <p class="import-policy"><?= $preview['updateExisting'] ? $escape($t('view.catalog.import_update_policy')) : $escape($t('view.catalog.import_add_policy')) ?></p>
            <p class="import-help"><?= $escape($t('view.catalog.import_expiry_help', ['minutes' => $expiresInMinutes])) ?></p>
            <?php if ($preview['counts']['add'] + $preview['counts']['update'] === 0): ?>
                <p class="import-help"><?= $escape($t('view.catalog.import_no_changes')) ?></p>
            <?php endif; ?>
            <div class="import-actions">
                <form action="/profile/import/confirm" method="post" class="import-confirm-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="importToken" value="<?= $escape($importToken) ?>">
                    <button type="submit"><?= $escape($t('view.catalog.confirm_import')) ?></button>
                </form>
                <form action="/profile/import/cancel" method="post" class="import-cancel-form">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="importToken" value="<?= $escape($importToken) ?>">
                    <button type="submit"><?= $escape($t('view.catalog.cancel_preview')) ?></button>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($hasResult): ?>
        <section class="import-entries" aria-labelledby="import-entries-title">
            <div class="import-section-heading">
                <h2 id="import-entries-title"><?= $escape($t('view.catalog.entry_details')) ?></h2>
                <p class="import-help"><?= $escape($t('view.catalog.page_position', ['page' => $pagination['page'], 'pages' => $pagination['totalPages']])) ?></p>
            </div>
            <?php if ($visibleRows === []): ?>
                <p class="import-panel"><?= $escape($t('view.catalog.no_entries')) ?></p>
            <?php else: ?>
                <ul class="import-entry-list">
                    <?php foreach ($visibleRows as $row): ?>
                        <li class="import-entry" data-import-mal-id="<?= $escape($row['malId'] ?? '') ?>" data-import-action="<?= $escape($row['action']) ?>">
                            <div class="import-entry-heading">
                                <div>
                                    <h3>
                                        <?php if ($row['animeId'] !== null): ?>
                                            <a href="/anime/<?= $escape($row['animeId']) ?>"><?= $escape($row['title'] === '' ? $t('view.catalog.untitled_anime') : $row['title']) ?></a>
                                        <?php else: ?>
                                            <?= $escape($row['title'] === '' ? $t('view.catalog.untitled_entry') : $row['title']) ?>
                                        <?php endif; ?>
                                    </h3>
                                    <p class="import-help">ID MAL: <?= $escape($row['malId'] ?? $t('view.catalog.none')) ?></p>
                                </div>
                                <span class="import-action-label" data-kind="<?= $escape($row['action']) ?>"><?= $escape($actionLabels[$row['action']] ?? $t('view.catalog.import_skipped')) ?></span>
                            </div>
                            <?php if ($row['status'] !== null): ?>
                                <dl class="import-entry-values">
                                    <div>
                                        <dt><?= $escape($t('view.catalog.status')) ?></dt>
                                        <dd>
                                            <?php if ($row['action'] === 'update' && $row['currentStatus'] !== null && $row['currentStatus'] !== $row['status']): ?>
                                                <span class="import-before"><?= $escape($t('view.catalog.previously')) ?> <?= $escape($statusLabels[$row['currentStatus']] ?? $row['currentStatus']) ?></span>
                                            <?php endif; ?>
                                            <strong><?= $escape($statusLabels[$row['status']] ?? $row['status']) ?></strong>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt><?= $escape($t('view.catalog.watched_episodes')) ?></dt>
                                        <dd>
                                            <?php if ($row['action'] === 'update' && $row['currentWatched'] !== null && $row['currentWatched'] !== $row['watched']): ?>
                                                <span class="import-before"><?= $escape($t('view.catalog.previously')) ?> <?= $escape($row['currentWatched']) ?></span>
                                            <?php endif; ?>
                                            <strong><?= $escape($row['watched'] ?? 0) ?></strong>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt><?= $escape($t('view.catalog.your_score')) ?></dt>
                                        <dd>
                                            <?php if ($row['action'] === 'update' && $row['currentRating'] !== $row['rating']): ?>
                                                <span class="import-before"><?= $escape($t('view.catalog.previously')) ?> <?= $row['currentRating'] !== null ? $escape($row['currentRating']) . ' / 10' : $escape($t('view.catalog.no_score')) ?></span>
                                            <?php endif; ?>
                                            <strong><?= $row['rating'] !== null ? $escape($row['rating']) . ' / 10' : $escape($t('view.catalog.no_score')) ?></strong>
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
                            <?php if ($reason !== ''): ?><p class="import-entry-reason"><?= $escape($translateText($reason)) ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($pagination['totalPages'] > 1): ?>
                <nav class="import-pagination" aria-label="<?= $escape($t('view.catalog.import_pages')) ?>">
                    <?php if ($pagination['page'] > 1): ?>
                        <a href="/profile/import?page=<?= $escape($pagination['page'] - 1) ?>"><?= $escape($t('view.catalog.previous')) ?></a>
                    <?php endif; ?>
                    <span><?= $escape($pagination['page']) ?> / <?= $escape($pagination['totalPages']) ?></span>
                    <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                        <a href="/profile/import?page=<?= $escape($pagination['page'] + 1) ?>"><?= $escape($t('view.catalog.next')) ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($hasResult): ?>
        <details class="import-panel import-upload-panel">
            <summary><?= $report !== null ? $escape($t('view.catalog.import_another')) : $escape($t('view.catalog.load_another')) ?></summary>
    <?php else: ?>
        <section class="import-panel import-upload-panel" aria-labelledby="import-upload-title">
    <?php endif; ?>
        <div class="import-upload-content">
            <div class="import-upload-guide">
                <span class="import-upload-icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M12 12v6m-3-3 3-3 3 3"/></svg>
                </span>
                <h2 id="import-upload-title"><?= $escape($t('view.catalog.prepare_list')) ?></h2>
                <ol class="import-instructions">
                    <li><?= $escape($t('view.catalog.mal_login_export')) ?> <a href="https://myanimelist.net/panel.php?go=export" rel="external noreferrer"><?= $escape($t('view.catalog.export_list')) ?></a>.</li>
                    <li><?= $escape($t('view.catalog.choose')) ?> <span lang="en">Anime List</span><?= $escape($t('view.catalog.then')) ?> <span lang="en">Export My List</span> <?= $escape($t('view.catalog.download_file')) ?></li>
                    <li><?= $escape($t('view.catalog.choose_file_help')) ?></li>
                </ol>
                <p class="import-help"><?= $escape($t('view.catalog.import_privacy_help')) ?></p>
            </div>
            <form action="/profile/import/preview" method="post" enctype="multipart/form-data" class="import-upload-form">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <div class="profile-field">
                    <label for="mal-file"><?= $escape($t('view.catalog.mal_export_file')) ?></label>
                    <input id="mal-file" name="malFile" type="file" accept=".xml,.gz,application/xml,text/xml,application/gzip" aria-describedby="mal-file-help" required>
                    <p id="mal-file-help"><?= $escape($t('view.catalog.mal_file_limits')) ?></p>
                </div>
                <div class="import-policy-choice">
                    <label for="mal-update-existing">
                        <input id="mal-update-existing" name="updateExisting" type="checkbox" value="1" aria-describedby="mal-update-help"<?= $updateExisting ? ' checked' : '' ?>>
                        <span><?= $escape($t('view.catalog.update_existing')) ?></span>
                    </label>
                    <p id="mal-update-help"><?= $escape($t('view.catalog.update_existing_help')) ?></p>
                </div>
                <button type="submit"><?= $escape($t('view.catalog.show_import_preview')) ?></button>
            </form>
        </div>
    <?php if ($hasResult): ?></details><?php else: ?></section><?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
