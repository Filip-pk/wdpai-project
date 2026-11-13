<?php
$isErrorPage = true;
require __DIR__ . '/partials/header.html.php';
?>

<section class="error-page error-not-found" aria-labelledby="not-found-title">
    <?php
    $errorVisualCode = '404';
    $errorVisualKind = 'compass';
    require __DIR__ . '/partials/error-visual.html.php';
    ?>

    <div class="error-copy">
        <header class="error-heading">
            <p class="error-eyebrow"><?= $escape($t('view.interface.unknown_address')) ?></p>
            <h1 id="not-found-title"><?= $escape($t('view.interface.404_page_not_found')) ?></h1>
            <p><?= $escape($t('view.interface.the_address_or_identifier_does_not_exist')) ?></p>
        </header>
        <p class="error-description"><?= $escape($t('view.interface.check_the_address_or_return_to_discovering_anime_your_next_story_awaits_in_the_catalogue')) ?></p>
        <nav class="error-actions" aria-label="<?= $escape($t('view.interface.error_navigation')) ?>">
            <a href="/" class="error-button error-button-primary"><?= $escape($t('view.interface.back_to_home')) ?> <span aria-hidden="true">→</span></a>
            <a href="/anime" class="error-button error-button-secondary"><?= $escape($t('view.interface.discover_anime')) ?></a>
        </nav>
        <p class="error-hint"><span aria-hidden="true">✦</span> <?= $escape($t('view.interface.the_catalogue_links_to_current_pages_for_every_available_title')) ?></p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
