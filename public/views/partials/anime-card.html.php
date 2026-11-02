<article class="anime-card" data-anime-id="<?= $escape($animeId) ?>" data-title="<?= $escape($anime['title']) ?>" data-score="<?= $escape($anime['score'] ?? '') ?>" data-episodes="<?= $escape($anime['episodes'] ?? '') ?>" data-year="<?= $escape($anime['year'] ?? '') ?>">
    <a class="anime-cover anime-card-cover" href="/anime/<?= $escape($animeId) ?>" aria-label="<?= $escape('Szczegóły: ' . $anime['title']) ?>">
        <span class="anime-cover-placeholder" aria-hidden="true"><span>✦</span><span>Brak okładki</span></span>
        <?php if (!empty($anime['coverUrl'])): ?>
            <img data-anime-cover src="<?= $escape($anime['coverUrl']) ?>" alt="" width="300" height="450" loading="lazy" decoding="async" referrerpolicy="no-referrer">
        <?php endif; ?>
        <?php if (!empty($anime['type']) && $anime['type'] !== 'UNKNOWN'): ?>
            <span class="anime-type"><?= $escape($anime['type']) ?></span>
        <?php endif; ?>
    </a>
    <div class="anime-card-body">
        <h3><a href="/anime/<?= $escape($animeId) ?>"><?= $escape($anime['title']) ?></a></h3>
        <ul class="anime-genres" aria-label="Gatunki">
            <?php foreach ($anime['genres'] as $genreName): ?>
                <li><?= $escape($genreName) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="anime-card-description"<?= ($anime['descriptionLanguage'] ?? '') === 'en' && $anime['description'] !== '' ? ' lang="en"' : '' ?>><?= $escape($anime['description'] === '' ? 'Opis tego anime nie jest jeszcze dostępny.' : $anime['description']) ?></p>
        <dl class="anime-card-facts">
            <div><dt><?= !array_key_exists('malId', $anime) ? 'Ocena demonstracyjna' : ($anime['malId'] !== null ? 'Ocena MAL' : 'Ocena') ?></dt><dd><?= isset($anime['score']) ? $escape(number_format($anime['score'], 2, ',', '')) . ' / 10' : 'Brak oceny' ?></dd></div>
            <div><dt>Odcinki</dt><dd><?= isset($anime['episodes']) ? $escape($anime['episodes']) : 'Nieznane' ?></dd></div>
            <?php if (isset($anime['year'])): ?>
                <div><dt>Premiera</dt><dd><?= $escape($anime['year']) ?></dd></div>
            <?php endif; ?>
        </dl>
        <a class="anime-card-action" href="/anime/<?= $escape($animeId) ?>#anime-actions-title">Szczegóły i watchlista <span aria-hidden="true">→</span></a>
    </div>
</article>
