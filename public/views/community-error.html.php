<?php
$isErrorPage = true;
require __DIR__ . '/partials/header.html.php';
$errorStatus = http_response_code();
// Callers outside discussions (e.g. the admin panel) pass their own link label and hint.
$errorBackUrl = $backUrl ?? '/messages';
$errorBackLabel = $backLabel ?? (isset($backUrl) ? $t('view.interface.back_to_discussion') : $t('view.interface.go_to_messages'));
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
            <a href="<?= $escape($errorBackUrl) ?>" class="error-button error-button-primary"><?= $escape($errorBackLabel) ?> <span aria-hidden="true">→</span></a>
            <?php if ($errorBackUrl !== '/'): ?>
                <a href="/" class="error-button error-button-secondary"><?= $escape($t('view.interface.home')) ?></a>
            <?php endif; ?>
        </nav>
        <p class="error-hint"><span aria-hidden="true">✦</span> <?= $escape($errorHint ?? $t('view.interface.return_to_the_conversation_or_choose_another_section_from_navigation')) ?></p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
