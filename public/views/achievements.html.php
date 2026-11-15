<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="achievements-page" data-achievements-page>
    <header class="achievements-heading">
        <div>
            <p class="achievements-eyebrow"><?= $escape($t('achievements.eyebrow')) ?></p>
            <h1><?= $escape($t('achievements.title')) ?></h1>
            <p><?= $escape($t('achievements.intro')) ?></p>
        </div>
        <a class="achievements-back" href="/users/<?= $escape($accountId) ?>"><?= $escape($t('achievements.back_profile')) ?> <span aria-hidden="true">↗</span></a>
    </header>

    <section class="achievements-overview" aria-labelledby="achievements-overview-title">
        <div>
            <h2 id="achievements-overview-title"><?= $escape($t('achievements.collection')) ?></h2>
            <p class="achievements-count" data-achievements-unlocked="<?= $escape($achievementOverview['unlockedCount']) ?>" data-achievements-total="<?= $escape($achievementOverview['totalCount']) ?>"><strong><?= $escape($achievementOverview['unlockedCount']) ?></strong> / <?= $escape($achievementOverview['totalCount']) ?></p>
        </div>
        <div class="achievements-overview-progress">
            <label for="achievements-overview-progress"><?= $escape($t('achievements.unlocked_count')) ?></label>
            <progress id="achievements-overview-progress" value="<?= $escape($achievementOverview['unlockedCount']) ?>" max="<?= $escape($achievementOverview['totalCount']) ?>"><?= $escape($achievementOverview['unlockedCount']) ?> / <?= $escape($achievementOverview['totalCount']) ?></progress>
            <p><?= $escape($t('achievements.progress_help')) ?></p>
        </div>
    </section>

    <p class="achievements-help"><?= $escape($t('achievements.permanent_help')) ?></p>

    <?php foreach (['quiz', 'anime'] as $achievementCategory): ?>
        <section class="achievements-category" aria-labelledby="achievements-category-<?= $escape($achievementCategory) ?>">
            <header class="achievements-category-heading">
                <h2 id="achievements-category-<?= $escape($achievementCategory) ?>"><?= $escape($t('achievements.category.' . $achievementCategory)) ?></h2>
                <a href="<?= $achievementCategory === 'quiz' ? '/quiz' : '/watchlist' ?>"><?= $escape($t('achievements.action.' . $achievementCategory)) ?> <span aria-hidden="true">→</span></a>
            </header>
            <ul class="achievements-grid">
                <?php foreach ($achievementOverview['items'] as $achievement): ?>
                    <?php if ($achievement['category'] !== $achievementCategory) { continue; } ?>
                    <?php
                    $progress = min($achievement['target'], max(0, $achievement['progress']));
                    $unlockedDate = $achievement['unlockedAt'] === null ? null : (new DateTimeImmutable($achievement['unlockedAt']))
                        ->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
                    ?>
                    <li>
                        <article class="achievement-card<?= $achievement['unlocked'] ? ' achievement-card-unlocked' : '' ?>" aria-labelledby="achievement-title-<?= $escape($achievement['code']) ?>" data-achievement-code="<?= $escape($achievement['code']) ?>" data-achievement-unlocked="<?= $achievement['unlocked'] ? '1' : '0' ?>" data-achievement-progress="<?= $escape($progress) ?>" data-achievement-target="<?= $escape($achievement['target']) ?>">
                            <div class="achievement-card-top">
                                <span class="achievement-symbol" aria-hidden="true"><?php require __DIR__ . '/partials/achievement-icon.html.php'; ?></span>
                                <span class="achievement-status"><span aria-hidden="true"><?= $achievement['unlocked'] ? '✓' : '◇' ?></span> <?= $escape($t($achievement['unlocked'] ? 'achievements.unlocked' : 'achievements.locked')) ?></span>
                            </div>
                            <h3 id="achievement-title-<?= $escape($achievement['code']) ?>"><?= $escape($t($achievement['titleKey'])) ?></h3>
                            <p class="achievement-description"><?= $escape($t($achievement['descriptionKey'])) ?></p>
                            <div class="achievement-progress">
                                <label for="achievement-progress-<?= $escape($achievement['code']) ?>"><?= $escape($t('achievements.progress')) ?> <span><?= $escape($progress) ?> / <?= $escape($achievement['target']) ?></span></label>
                                <progress id="achievement-progress-<?= $escape($achievement['code']) ?>" value="<?= $escape($progress) ?>" max="<?= $escape($achievement['target']) ?>"><?= $escape($progress) ?> / <?= $escape($achievement['target']) ?></progress>
                            </div>
                            <?php if ($unlockedDate !== null): ?>
                                <p class="achievement-date"><?= $escape($t('achievements.unlocked_on')) ?> <time datetime="<?= $escape($unlockedDate) ?>"><?= $escape($unlockedDate) ?></time></p>
                            <?php else: ?>
                                <p class="achievement-date"><?= $escape($t('achievements.keep_going')) ?></p>
                            <?php endif; ?>
                        </article>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
