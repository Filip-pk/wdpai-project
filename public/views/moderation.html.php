<?php
require __DIR__ . '/partials/header.html.php';

$reportDate = static function (string $date): string {
    try {
        return (new DateTimeImmutable($date))
            ->setTimezone(new DateTimeZone('Europe/Warsaw'))
            ->format('d.m.Y, H:i');
    } catch (Throwable) {
        return $date;
    }
};
?>

<section class="community-page moderation-page" aria-labelledby="moderation-heading">
    <header class="community-heading social-hero">
        <div>
            <p class="social-kicker">Panel moderacji</p>
            <h1 id="moderation-heading" class="social-title">Zgłoszenia</h1>
            <p class="social-description">Ukrycie treści pozostawi informację o interwencji moderatora.</p>
        </div>
        <span class="social-pill">Oczekujące zgłoszenia: <?= $escape($reports['total']) ?>.</span>
    </header>

    <aside class="moderation-guide" aria-label="Rozpatrywanie zgłoszeń">
        <span class="moderation-guide-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="m12 3 8 3v6c0 4-8 9-8 9s-8-5-8-9V6Z"/><path d="m8 12 3 3 5-6"/></svg>
        </span>
        <div>
            <h2>Sprawdź treść i powód zgłoszenia</h2>
            <p>Ukryj treść, która narusza zasady, lub odrzuć zgłoszenie. Decyzja dotyczy wskazanego wpisu.</p>
        </div>
    </aside>

    <?php if (($notice ?? '') !== ''): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div id="moderation-errors" class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($reports['entries'] === []): ?>
        <div class="community-panel community-empty social-empty">
            <span class="social-empty-icon" aria-hidden="true">✓</span>
            <h2>Brak zgłoszeń do rozpatrzenia.</h2>
            <p>Nowe zgłoszenia komentarzy i wiadomości pojawią się w tym miejscu.</p>
            <a href="/dashboard">Wróć do pulpitu <span aria-hidden="true">→</span></a>
        </div>
    <?php else: ?>
        <ol class="community-list" aria-label="Zgłoszenia do rozpatrzenia" data-reports-total="<?= $escape($reports['total']) ?>">
            <?php foreach ($reports['entries'] as $report): ?>
                <li>
                    <article class="community-panel moderation-card" data-report-id="<?= $escape($report['id']) ?>" aria-labelledby="report-title-<?= $escape($report['id']) ?>">
                        <header class="community-heading moderation-card-heading">
                            <span class="moderation-kind"><?= $report['targetType'] === 'comment' ? 'Dyskusja publiczna' : 'Zgłoszona wiadomość' ?></span>
                            <h2 id="report-title-<?= $escape($report['id']) ?>"><?= $report['targetType'] === 'comment' ? 'Komentarz' : 'Prywatna wiadomość' ?> — zgłoszenie #<?= $escape($report['id']) ?></h2>
                            <p class="community-meta">Autor treści: <?= $escape($report['authorName']) ?></p>
                            <p class="community-meta">Zgłaszający: <?= $escape($report['reporterName']) ?> · <time datetime="<?= $escape($report['createdAt']) ?>"><?= $escape($reportDate($report['createdAt'])) ?></time></p>
                        </header>
                        <div class="moderation-content-grid">
                            <section class="community-report-content" aria-labelledby="report-content-<?= $escape($report['id']) ?>">
                                <h3 id="report-content-<?= $escape($report['id']) ?>">Zgłoszona treść</h3>
                                <blockquote class="community-content"><?= $escape($report['snapshot']) ?></blockquote>
                            </section>
                            <section class="community-report-content" aria-labelledby="report-reason-<?= $escape($report['id']) ?>">
                                <h3 id="report-reason-<?= $escape($report['id']) ?>">Powód zgłoszenia</h3>
                                <p class="community-content"><?= $escape($report['reason']) ?></p>
                            </section>
                        </div>
                        <form class="community-form community-resolve-form" method="post" action="/moderation/reports/<?= $escape($report['id']) ?>/resolve" aria-label="<?= $escape('Rozpatrz zgłoszenie #' . $report['id']) ?>">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="page" value="<?= $escape($reports['page']) ?>">
                            <div class="community-field">
                                <label for="report-action-<?= $escape($report['id']) ?>">Decyzja moderatora</label>
                                <select id="report-action-<?= $escape($report['id']) ?>" name="action" required<?= $errors !== [] ? ' aria-describedby="moderation-errors" aria-invalid="true"' : '' ?>>
                                    <option value="" selected disabled>Wybierz decyzję</option>
                                    <option value="remove">Ukryj treść</option>
                                    <option value="dismiss">Odrzuć zgłoszenie</option>
                                </select>
                            </div>
                            <button type="submit">Zatwierdź decyzję</button>
                        </form>
                    </article>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($reports['totalPages'] > 1): ?>
        <nav class="community-pagination" aria-label="Strony zgłoszeń">
            <?php if ($reports['previousUrl'] !== null): ?>
                <a href="<?= $escape($reports['previousUrl']) ?>" rel="prev">Poprzednia</a>
            <?php endif; ?>
            <span>Strona <?= $escape($reports['page']) ?> z <?= $escape($reports['totalPages']) ?></span>
            <?php if ($reports['nextUrl'] !== null): ?>
                <a href="<?= $escape($reports['nextUrl']) ?>" rel="next">Następna</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
