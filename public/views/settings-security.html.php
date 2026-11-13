<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="settings-page">
    <header class="settings-heading">
        <h1><?= $escape($t('view.interface.account_and_security')) ?></h1>
        <a href="/settings"><?= $escape($t('view.interface.all_settings')) ?></a>
    </header>
    <?php require __DIR__ . '/partials/settings-security.html.php'; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
