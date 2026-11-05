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

<section class="community-page" aria-labelledby="moderation-heading">
    <header class="community-heading">
        <h1 id="moderation-heading">Zgłoszenia</h1>
        <p>Oczekujące zgłoszenia: <?= $escape($reports['total']) ?>.</p>
        <p>Ukrycie treści pozostawi informację o interwencji moderatora.</p>
    </header>

    <?php if (($notice ?? '') !== ''): ?>
        <p class="community-notice" data-kind="success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($reports['entries'] === []): ?>
        <p class="community-panel community-empty">Brak zgłoszeń do rozpatrzenia.</p>
    <?php else: ?>
        <ol class="community-list" aria-label="Zgłoszenia do rozpatrzenia" data-reports-total="<?= $escape($reports['total']) ?>">
            <?php foreach ($reports['entries'] as $report): ?>
                <li>
                    <article class="community-panel" data-report-id="<?= $escape($report['id']) ?>" aria-labelledby="report-title-<?= $escape($report['id']) ?>">
                        <header class="community-heading">
                            <h2 id="report-title-<?= $escape($report['id']) ?>"><?= $report['targetType'] === 'comment' ? 'Komentarz' : 'Prywatna wiadomość' ?> — zgłoszenie #<?= $escape($report['id']) ?></h2>
                            <p class="community-meta">Autor treści: <?= $escape($report['authorName']) ?></p>
                            <p class="community-meta">Zgłaszający: <?= $escape($report['reporterName']) ?> · <time datetime="<?= $escape($report['createdAt']) ?>"><?= $escape($reportDate($report['createdAt'])) ?></time></p>
                        </header>
                        <section class="community-report-content" aria-labelledby="report-content-<?= $escape($report['id']) ?>">
                            <h3 id="report-content-<?= $escape($report['id']) ?>">Zgłoszona treść</h3>
                            <blockquote class="community-content"><?= $escape($report['snapshot']) ?></blockquote>
                        </section>
                        <section class="community-report-content" aria-labelledby="report-reason-<?= $escape($report['id']) ?>">
                            <h3 id="report-reason-<?= $escape($report['id']) ?>">Powód zgłoszenia</h3>
                            <p class="community-content"><?= $escape($report['reason']) ?></p>
                        </section>
                        <form class="community-form community-resolve-form" method="post" action="/moderation/reports/<?= $escape($report['id']) ?>/resolve">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="page" value="<?= $escape($reports['page']) ?>">
                            <div class="community-field">
                                <label for="report-action-<?= $escape($report['id']) ?>">Decyzja moderatora</label>
                                <select id="report-action-<?= $escape($report['id']) ?>" name="action" required>
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
