<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
    <header>
        <a href="/">AniVibe — strona główna</a>
    </header>

    <main>
        <article>
            <h1><?= htmlspecialchars($anime['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p><?= htmlspecialchars($anime['description'], ENT_QUOTES, 'UTF-8') ?></p>
        </article>

        <a href="/anime">Wróć do katalogu</a>
    </main>
</body>
</html>