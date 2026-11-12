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
            <p class="error-eyebrow">Nieznany adres</p>
            <h1 id="not-found-title">404 — nie znaleziono strony</h1>
            <p>Podany adres lub identyfikator nie istnieje.</p>
        </header>
        <p class="error-description">Sprawdź adres lub wróć do odkrywania anime. Kolejna historia czeka w katalogu.</p>
        <nav class="error-actions" aria-label="Nawigacja po błędzie">
            <a href="/" class="error-button error-button-primary">Wróć do strony głównej <span aria-hidden="true">→</span></a>
            <a href="/anime" class="error-button error-button-secondary">Odkryj anime</a>
        </nav>
        <p class="error-hint"><span aria-hidden="true">✦</span> Z katalogu przejdziesz do aktualnych stron wszystkich dostępnych tytułów.</p>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
