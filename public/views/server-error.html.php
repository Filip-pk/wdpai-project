<?php
$isErrorPage = true;
require __DIR__ . '/partials/header.html.php';
?>

<section class="error-page error-server" aria-labelledby="server-error-title">
    <?php
    $errorVisualCode = '500';
    $errorVisualKind = 'signal';
    require __DIR__ . '/partials/error-visual.html.php';
    ?>

    <div class="error-copy">
        <header class="error-heading">
            <p class="error-eyebrow"><?= $escape($t('view.interface.server_error_500')) ?></p>
            <h1 id="server-error-title"><?= $escape($t('view.interface.the_page_could_not_be_displayed')) ?></h1>
            <p><?= $escape($t('view.interface.there_was_a_problem_loading_the_data_try_again_shortly')) ?></p>
        </header>
        <p class="error-description"><?= $escape($t('view.interface.you_can_return_home_or_go_to_projects')) ?></p>
        <nav class="error-actions" aria-label="<?= $escape($t('view.interface.error_navigation')) ?>">
            <?php if (($retryUrl ?? null) !== null): ?>
                <a href="<?= $escape($retryUrl) ?>" class="error-button error-button-primary" data-error-retry><?= $escape($t('view.interface.try_again')) ?> <span aria-hidden="true">↻</span></a>
                <a href="/" class="error-button error-button-secondary"><?= $escape($t('view.interface.home')) ?></a>
            <?php else: ?>
                <a href="/" class="error-button error-button-primary"><?= $escape($t('view.interface.home')) ?> <span aria-hidden="true">→</span></a>
            <?php endif; ?>
            <?php if (($retryUrl ?? null) !== '/projects'): ?>
                <a href="/projects" class="<?= ($retryUrl ?? null) === null ? 'error-button error-button-secondary' : 'error-text-link' ?>"><?= $escape($t('view.interface.go_to_projects')) ?></a>
            <?php endif; ?>
        </nav>
        <p class="error-hint"><span aria-hidden="true">✦</span> <?= $escape($t('view.interface.if_the_problem_persists_try_opening_the_app_again_shortly')) ?></p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
