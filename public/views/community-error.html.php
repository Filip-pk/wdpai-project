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
            <p class="error-eyebrow"><?= $escape($t('view.interface.operation_interrupted')) ?></p>
            <h1 id="community-error-title"><?= $escape($t('view.interface.unable_to_complete_the_operation')) ?></h1>
            <p class="error-message" role="alert"><?= $escape($translateText($message)) ?></p>
        </header>
        <nav class="error-actions" aria-label="<?= $escape($t('view.interface.error_navigation')) ?>">
            <a href="<?= $escape($backUrl ?? '/messages') ?>" class="error-button error-button-primary"><?= $escape(isset($backUrl) ? $t('view.interface.back_to_discussion') : $t('view.interface.go_to_messages')) ?> <span aria-hidden="true">→</span></a>
            <a href="/" class="error-button error-button-secondary"><?= $escape($t('view.interface.home')) ?></a>
        </nav>
        <p class="error-hint"><span aria-hidden="true">✦</span> <?= $escape($t('view.interface.return_to_the_conversation_or_choose_another_section_from_navigation')) ?></p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
