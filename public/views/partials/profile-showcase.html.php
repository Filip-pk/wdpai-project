<?php if ($canEditShowcase || $showcaseAnimes !== [] || $showcaseBadges !== []): ?>
    <section class="profile-showcase" aria-labelledby="profile-showcase-title" data-profile-showcase>
        <header class="showcase-heading">
            <div>
                <p class="showcase-eyebrow"><span aria-hidden="true">✧</span> <?= $escape($t('showcase.eyebrow')) ?></p>
                <h2 id="profile-showcase-title"><?= $escape($t('showcase.title')) ?></h2>
                <p><?= $escape($t('showcase.intro')) ?></p>
            </div>
            <?php if ($canEditShowcase): ?>
                <a class="showcase-edit-link" href="/profile/showcase"><?= $escape($t('showcase.edit')) ?> <span aria-hidden="true">↗</span></a>
            <?php endif; ?>
        </header>

        <?php if ($showcaseAnimes === [] && $showcaseBadges === []): ?>
            <div class="showcase-empty">
                <span class="showcase-empty-symbol" aria-hidden="true">✦</span>
                <div>
                    <h3><?= $escape($t('showcase.empty_title')) ?></h3>
                    <p><?= $escape($t('showcase.empty_help')) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($showcaseAnimes !== []): ?>
            <ol class="showcase-anime-list" aria-label="<?= $escape($t('showcase.favorites')) ?>">
                <?php $showcasePosition = 0; ?>
                <?php foreach ($showcaseAnimes as $showcaseAnimeId => $showcaseAnime): ?>
                    <?php $showcasePosition++; ?>
                    <li class="showcase-neon showcase-anime" data-showcase-anime-id="<?= $escape($showcaseAnimeId) ?>">
                        <a class="showcase-anime-link" href="/anime/<?= $escape($showcaseAnimeId) ?>">
                            <div class="showcase-cover">
                                <span class="showcase-cover-placeholder" aria-hidden="true">✦</span>
                                <?php if (!empty($showcaseAnime['coverUrl'])): ?>
                                    <img src="<?= $escape($showcaseAnime['coverUrl']) ?>" alt="" width="300" height="450" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                                <?php endif; ?>
                            </div>
                            <span class="showcase-number" aria-hidden="true">0<?= $escape($showcasePosition) ?></span>
                            <div class="showcase-anime-info">
                                <p class="showcase-favorite-label"><span aria-hidden="true">♡</span> <?= $escape($t('showcase.favorite')) ?></p>
                                <h3><?= $escape($showcaseAnime['title']) ?></h3>
                                <p class="showcase-anime-meta">
                                    <?php if (!empty($showcaseAnime['type']) && $showcaseAnime['type'] !== 'UNKNOWN'): ?>
                                        <span><?= $escape($showcaseAnime['type']) ?></span>
                                    <?php endif; ?>
                                    <?php if (isset($showcaseAnime['year'])): ?>
                                        <span><?= $escape($showcaseAnime['year']) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <?php if ($showcaseBadges !== []): ?>
            <div class="showcase-badges-section">
                <h3 class="showcase-section-title"><?= $escape($t('showcase.badges')) ?></h3>
                <ul class="showcase-badge-list">
                    <?php foreach ($showcaseBadges as $achievement): ?>
                        <li class="showcase-neon showcase-badge" data-showcase-badge-code="<?= $escape($achievement['code']) ?>">
                            <div class="showcase-badge-body">
                                <span class="showcase-badge-symbol" aria-hidden="true"><?php require __DIR__ . '/achievement-icon.html.php'; ?></span>
                                <div>
                                    <h4><?= $escape($t($achievement['titleKey'])) ?></h4>
                                    <p><?= $escape($t($achievement['descriptionKey'])) ?></p>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
