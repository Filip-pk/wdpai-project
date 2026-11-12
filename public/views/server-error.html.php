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
            <p class="error-eyebrow">Błąd serwera · 500</p>
            <h1 id="server-error-title">Nie udało się wyświetlić strony</h1>
            <p>Wystąpił problem podczas ładowania danych. Spróbuj ponownie za chwilę.</p>
        </header>
        <p class="error-description">Możesz wrócić do strony głównej lub przejść do projektów.</p>
        <nav class="error-actions" aria-label="Nawigacja po błędzie">
            <?php if (($retryUrl ?? null) !== null): ?>
                <a href="<?= $escape($retryUrl) ?>" class="error-button error-button-primary" data-error-retry>Spróbuj ponownie <span aria-hidden="true">↻</span></a>
                <a href="/" class="error-button error-button-secondary">Strona główna</a>
            <?php else: ?>
                <a href="/" class="error-button error-button-primary">Strona główna <span aria-hidden="true">→</span></a>
            <?php endif; ?>
            <?php if (($retryUrl ?? null) !== '/projects'): ?>
                <a href="/projects" class="<?= ($retryUrl ?? null) === null ? 'error-button error-button-secondary' : 'error-text-link' ?>">Przejdź do projektów</a>
            <?php endif; ?>
        </nav>
        <p class="error-hint"><span aria-hidden="true">✦</span> Jeśli problem się powtarza, spróbuj otworzyć aplikację za chwilę.</p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
