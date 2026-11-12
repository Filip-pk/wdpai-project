<?php
$isErrorPage = true;
require __DIR__ . '/partials/header.html.php';
$errorStatus = http_response_code();
?>

<section class="error-page error-operation" aria-labelledby="community-error-title">
    <?php
    $errorVisualCode = is_int($errorStatus) && $errorStatus >= 400 && $errorStatus <= 599 ? (string) $errorStatus : '!';
    $errorVisualKind = 'shield';
    require __DIR__ . '/partials/error-visual.html.php';
    ?>

    <div class="error-copy">
        <header class="error-heading">
            <p class="error-eyebrow">Operacja przerwana</p>
            <h1 id="community-error-title">Nie można wykonać operacji</h1>
            <p class="error-message" role="alert"><?= $escape($message) ?></p>
        </header>
        <nav class="error-actions" aria-label="Nawigacja po błędzie">
            <a href="<?= $escape($backUrl ?? '/messages') ?>" class="error-button error-button-primary"><?= isset($backUrl) ? 'Wróć do dyskusji' : 'Przejdź do wiadomości' ?> <span aria-hidden="true">→</span></a>
            <a href="/" class="error-button error-button-secondary">Strona główna</a>
        </nav>
        <p class="error-hint"><span aria-hidden="true">✦</span> Możesz wrócić do rozmowy lub wybrać inną sekcję z nawigacji.</p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
