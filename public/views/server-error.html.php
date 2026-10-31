<?php require __DIR__ . '/partials/header.html.php'; ?>

<section class="project-empty" aria-labelledby="server-error-title">
    <h1 id="server-error-title">Nie udało się wyświetlić strony</h1>
    <p>Wystąpił problem podczas ładowania danych. Spróbuj ponownie za chwilę.</p>

    <nav class="project-error-actions" aria-label="Nawigacja po błędzie">
        <a href="/projects" class="project-back">Spróbuj ponownie</a>
        <a href="/" class="project-back">Strona główna</a>
    </nav>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
