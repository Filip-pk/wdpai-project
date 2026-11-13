<section class="experience-panel" aria-labelledby="experience-title" data-user-exp="<?= $escape($experience['totalExp']) ?>" data-user-level="<?= $escape($experience['level']) ?>">
    <div class="experience-heading">
        <div class="experience-identity">
            <span class="experience-symbol" aria-hidden="true">✦</span>
            <div>
                <h2 id="experience-title"><?= $escape($translateText($experienceTitle ?? $t('view.catalog.your_level'))) ?></h2>
                <p class="experience-level"><?= $escape($t('view.catalog.level')) ?> <?= $escape($experience['level']) ?></p>
            </div>
        </div>
        <p class="experience-total"><strong><?= $escape(number_format($experience['totalExp'], 0, ',', ' ')) ?></strong> <?= $escape($t('view.catalog.total_exp')) ?></p>
    </div>
    <div class="experience-progress">
        <label for="experience-progress"><?= $escape($t('view.catalog.next_level_progress', ['level' => $experience['level'] + 1])) ?></label>
        <progress id="experience-progress" data-exp-progress value="<?= $escape($experience['levelExp']) ?>" max="<?= $escape($experience['nextLevelExp']) ?>" aria-describedby="experience-progress-info">
            <?= $escape($experience['levelExp']) ?> / <?= $escape($experience['nextLevelExp']) ?> EXP
        </progress>
        <p id="experience-progress-info"><?= $escape(number_format($experience['levelExp'], 0, ',', ' ')) ?> / <?= $escape(number_format($experience['nextLevelExp'], 0, ',', ' ')) ?> EXP · <?= $escape($t('view.catalog.exp_remaining', ['exp' => number_format($experience['remainingExp'], 0, ',', ' ')])) ?></p>
    </div>
    <?php if ($currentPath !== '/ranking'): ?>
        <a class="experience-ranking-link" href="/ranking"><?= $escape($t('view.catalog.see_top100')) ?> <span aria-hidden="true">→</span></a>
    <?php endif; ?>
</section>
