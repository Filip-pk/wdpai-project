<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page community-report-page" aria-labelledby="report-heading">
    <header class="community-heading social-hero">
        <div class="community-heading-copy">
            <p class="social-kicker">Bezpieczna społeczność</p>
            <h1 id="report-heading" class="social-title">Zgłoś <?= $target['targetType'] === 'comment' ? 'komentarz' : 'wiadomość' ?></h1>
            <p class="social-description">Moderator otrzyma zgłoszoną treść i podany przez Ciebie powód.</p>
        </div>
        <span class="social-pill"><?= $target['targetType'] === 'comment' ? 'Komentarz' : 'Wiadomość prywatna' ?></span>
    </header>

    <a class="community-back" href="<?= $escape($backUrl) ?>">← Wróć do <?= $target['targetType'] === 'comment' ? 'dyskusji' : 'rozmowy' ?></a>

    <?php if ($errors !== []): ?>
        <div id="report-errors" class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="report-layout">
        <section class="community-panel report-preview" aria-labelledby="reported-content-heading">
            <p class="report-step">01 · Zgłaszana treść</p>
            <h2 id="reported-content-heading">Zgłaszana treść</h2>
            <p class="community-meta">Autor: <?= $escape($target['authorName']) ?></p>
            <blockquote class="community-content"><?= $escape($target['body']) ?></blockquote>
            <?php if ($target['targetType'] === 'message'): ?>
                <p class="report-privacy"><span aria-hidden="true">◇</span> Pozostałe wiadomości z rozmowy pozostaną prywatne.</p>
            <?php endif; ?>
        </section>

        <form class="community-panel community-form report-form" method="post" action="/reports/<?= $escape($target['targetType']) ?>/<?= $escape($target['targetId']) ?>" aria-labelledby="report-form-heading">
            <p class="report-step">02 · Twoje zgłoszenie</p>
            <h2 id="report-form-heading">Szczegóły zgłoszenia</h2>
            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="returnPage" value="<?= $escape($returnPage) ?>">
            <?php if ($returnThread !== null): ?>
                <input type="hidden" name="returnThread" value="<?= $escape($returnThread) ?>">
                <input type="hidden" name="commentsPage" value="<?= $escape($mainCommentsPage) ?>">
                <input type="hidden" name="repliesPage" value="<?= $escape($repliesPage) ?>">
            <?php endif; ?>
            <div class="community-field">
                <label for="report-reason">Powód zgłoszenia</label>
                <p id="report-reason-help">Opisz naruszenie zasad. Maksymalnie 1000 znaków.</p>
                <textarea id="report-reason" name="reason" rows="5" maxlength="1000" required aria-describedby="report-reason-help<?= $errors !== [] ? ' report-errors' : '' ?>"<?= $errors !== [] ? ' aria-invalid="true"' : '' ?>><?= $escape($reason) ?></textarea>
            </div>
            <div class="community-actions">
                <button type="submit">Wyślij zgłoszenie</button>
                <a href="<?= $escape($backUrl) ?>">Anuluj i wróć</a>
            </div>
        </form>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
