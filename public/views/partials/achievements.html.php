<section class="profile-achievements" aria-labelledby="profile-achievements-title" data-profile-achievements>
    <header class="achievements-category-heading">
        <h2 id="profile-achievements-title"><?= $escape($t('achievements.profile_title')) ?></h2>
        <?php if ($isOwnProfile): ?>
            <a href="/achievements"><?= $escape($t('achievements.view_all')) ?> <span aria-hidden="true">→</span></a>
        <?php endif; ?>
    </header>
    <?php if ($profileAchievements === []): ?>
        <p class="achievements-help"><?= $escape($t($isOwnProfile ? 'achievements.empty_own' : 'achievements.empty_profile')) ?></p>
    <?php else: ?>
        <ul class="profile-achievements-grid">
            <?php foreach ($profileAchievements as $achievement): ?>
                <?php
                $unlockedDate = $achievement['unlockedAt'] === null ? null : (new DateTimeImmutable($achievement['unlockedAt']))
                    ->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
                ?>
                <li class="achievement-badge" data-achievement-code="<?= $escape($achievement['code']) ?>" data-achievement-unlocked="1">
                    <span class="achievement-symbol" aria-hidden="true"><?php require __DIR__ . '/achievement-icon.html.php'; ?></span>
                    <div>
                        <h3><?= $escape($t($achievement['titleKey'])) ?></h3>
                        <p><?= $escape($t($achievement['descriptionKey'])) ?></p>
                        <?php if ($unlockedDate !== null): ?>
                            <p class="achievement-date"><?= $escape($t('achievements.unlocked_on')) ?> <time datetime="<?= $escape($unlockedDate) ?>"><?= $escape($unlockedDate) ?></time></p>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
