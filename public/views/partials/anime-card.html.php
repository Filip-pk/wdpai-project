<article class="anime-card" data-anime-id="<?= $escape($animeId) ?>" data-title="<?= $escape($anime['title']) ?>" data-score="<?= $escape($anime['score'] ?? '') ?>" data-episodes="<?= $escape($anime['episodes'] ?? '') ?>" data-year="<?= $escape($anime['year'] ?? '') ?>">
    <a class="anime-cover anime-card-cover" href="/anime/<?= $escape($animeId) ?>" aria-label="<?= $escape($t('view.catalog.details_label', ['title' => $anime['title']])) ?>">
        <span class="anime-cover-placeholder" aria-hidden="true"><span>✦</span><span><?= $escape($t('view.catalog.no_cover')) ?></span></span>
        <?php if (!empty($anime['coverUrl'])): ?>
            <img data-anime-cover src="<?= $escape($anime['coverUrl']) ?>" alt="" width="300" height="450" loading="lazy" decoding="async" referrerpolicy="no-referrer">
        <?php endif; ?>
        <?php if (!empty($anime['type']) && $anime['type'] !== 'UNKNOWN'): ?>
            <span class="anime-type"><?= $escape($anime['type']) ?></span>
        <?php endif; ?>
    </a>
    <div class="anime-card-body">
        <h3><a href="/anime/<?= $escape($animeId) ?>"><?= $escape($anime['title']) ?></a></h3>
        <ul class="anime-genres" aria-label="<?= $escape($t('view.catalog.genres')) ?>">
            <?php foreach ($anime['genres'] as $genreName): ?>
                <li><?= $escape($translateText($genreName)) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="anime-card-description"<?= ($anime['descriptionLanguage'] ?? '') === 'en' && $anime['description'] !== '' ? ' lang="en"' : '' ?>><?= $escape($anime['description'] === '' ? $t('view.catalog.no_description') : $anime['description']) ?></p>
        <dl class="anime-card-facts">
            <div><dt><?= !array_key_exists('malId', $anime) ? $escape($t('view.catalog.demo_score')) : $escape($t('view.catalog.source_score')) ?></dt><dd><?= isset($anime['score']) ? $escape(number_format($anime['score'], 2, ',', '')) . ' / 10' : $escape($t('view.catalog.no_score')) ?></dd></div>
            <?php require __DIR__ . '/community-anime-rating.html.php'; ?>
            <div><dt><?= $escape($t('view.catalog.episodes')) ?></dt><dd><?= isset($anime['episodes']) ? $escape($anime['episodes']) : $escape($t('view.catalog.unknown_plural')) ?></dd></div>
            <?php if (isset($anime['year'])): ?>
                <div><dt><?= $escape($t('view.catalog.premiere')) ?></dt><dd><?= $escape($anime['year']) ?></dd></div>
            <?php endif; ?>
        </dl>
        <a class="anime-card-action" href="/anime/<?= $escape($animeId) ?>#anime-actions-title"><?= $escape($t('view.catalog.details_watchlist')) ?> <span aria-hidden="true">→</span></a>
    </div>
</article>
