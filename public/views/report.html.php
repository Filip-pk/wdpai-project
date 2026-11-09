<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page" aria-labelledby="report-heading">
    <header class="community-heading">
        <h1 id="report-heading">Zgłoś <?= $target['targetType'] === 'comment' ? 'komentarz' : 'wiadomość' ?></h1>
        <p>Moderator otrzyma zgłoszoną treść i podany przez Ciebie powód.</p>
        <?php if ($target['targetType'] === 'message'): ?>
            <p>Pozostałe wiadomości z rozmowy pozostaną prywatne.</p>
        <?php endif; ?>
    </header>

    <?php if ($errors !== []): ?>
        <div class="community-notice" data-kind="error" role="alert">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="community-panel" aria-labelledby="reported-content-heading">
        <h2 id="reported-content-heading">Zgłaszana treść</h2>
        <p class="community-meta">Autor: <?= $escape($target['authorName']) ?></p>
        <blockquote class="community-content"><?= $escape($target['body']) ?></blockquote>
    </section>

    <form class="community-panel community-form" method="post" action="/reports/<?= $escape($target['targetType']) ?>/<?= $escape($target['targetId']) ?>">
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
            <textarea id="report-reason" name="reason" rows="5" maxlength="1000" required aria-describedby="report-reason-help"><?= $escape($reason) ?></textarea>
        </div>
        <div class="community-actions">
            <button type="submit">Wyślij zgłoszenie</button>
            <a href="<?= $escape($backUrl) ?>">Anuluj i wróć</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
