<?php
$isErrorPage = true;
require __DIR__ . '/partials/header.html.php';
?>

<section class="error-page error-not-found" aria-labelledby="method-not-allowed-title">
    <?php
    $errorVisualCode = '405';
    $errorVisualKind = 'shield';
    require __DIR__ . '/partials/error-visual.html.php';
    ?>

    <div class="error-copy">
        <header class="error-heading">
            <h1 id="method-not-allowed-title"><?= $escape($t('view.interface.405_method_not_allowed')) ?></h1>
            <p><?= $escape($t('view.interface.this_address_does_not_accept_this_request_method')) ?></p>
        </header>
        <nav class="error-actions" aria-label="<?= $escape($t('view.interface.error_navigation')) ?>">
            <a href="/" class="error-button error-button-primary"><?= $escape($t('view.interface.back_to_home')) ?> <span aria-hidden="true">→</span></a>
            <a href="/anime" class="error-button error-button-secondary"><?= $escape($t('view.interface.discover_anime')) ?></a>
        </nav>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
