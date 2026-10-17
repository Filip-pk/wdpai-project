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
        <h1>Katalog anime</h1>
        <p>Na tym etapie prezentujemy przykładowe dane.</p>

        <ul>
            <?php foreach ($animes as $id => $anime): ?>
                <li>
                    <a href="/anime/<?= (int) $id ?>">
                        <?= htmlspecialchars($anime['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </main>
</body>
</html>