<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="community-page community-panel" aria-labelledby="community-error-title">
    <h1 id="community-error-title">Nie można wykonać operacji</h1>
    <p role="alert"><?= $escape($message) ?></p>
    <a href="<?= $escape($backUrl ?? '/messages') ?>"><?= isset($backUrl) ? 'Wróć do dyskusji' : 'Przejdź do wiadomości' ?></a>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
