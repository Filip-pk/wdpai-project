<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ranking-page">
    <header class="ranking-heading">
        <div class="ranking-heading-copy">
            <p class="eyebrow"><?= $escape($t('view.catalog.community')) ?></p>
            <h1><?= $escape($t('view.catalog.top100')) ?></h1>
            <p><?= $escape($t('view.catalog.top100_intro')) ?></p>
        </div>
        <a class="ranking-related-link" href="/anime/ranking"><?= $escape($t('view.catalog.anime_ranking')) ?> <span aria-hidden="true">↗</span></a>
    </header>

    <div class="ranking-overview">
        <?php if ($ownRanking !== null): ?>
            <section class="ranking-own" aria-labelledby="ranking-own-title" data-own-ranking-user-id="<?= $escape($ownRanking['userId']) ?>" data-own-ranking-rank="<?= $escape($ownRanking['rank']) ?>" data-own-ranking-exp="<?= $escape($ownRanking['totalExp']) ?>">
                <div class="ranking-own-heading">
                    <span class="ranking-own-symbol" aria-hidden="true">✦</span>
                    <h2 id="ranking-own-title"><?= $escape($t('view.catalog.your_position')) ?></h2>
                </div>
                <p class="ranking-own-position">#<?= $escape($ownRanking['rank']) ?></p>
                <p><?= $isInTop100 ? $escape($t('view.catalog.in_top100')) : $escape($t('view.catalog.outside_top100')) ?></p>
                <a href="/watchlist"><?= $escape($t('view.catalog.go_watchlist')) ?> <span aria-hidden="true">→</span></a>
            </section>
        <?php endif; ?>
        <?php require __DIR__ . '/partials/experience.html.php'; ?>
    </div>

    <details class="ranking-rules">
        <summary><?= $escape($t('view.catalog.gain_exp')) ?></summary>
        <ul>
            <li><?= $escape($t('view.catalog.each_episode')) ?> <strong>5 EXP</strong>.</li>
            <li><?= $escape($t('view.catalog.complete_bonus')) ?> <strong>100 EXP</strong><?= $escape($t('view.catalog.complete_bonus_help')) ?></li>
            <li><?= $escape($t('view.catalog.level_exp_rules')) ?></li>
            <li><?= $escape($t('view.catalog.exp_count_rules')) ?></li>
        </ul>
        <p><?= $escape($t('view.catalog.ranking_refresh_rules')) ?></p>
    </details>

    <section class="ranking-board" aria-labelledby="ranking-board-title">
        <div class="ranking-board-heading">
            <div>
                <h2 id="ranking-board-title"><?= $escape($t('view.catalog.most_exp')) ?></h2>
                <span class="ranking-count"><?= count($leaders) ?> / 100 <?= $escape($t('view.catalog.users_suffix')) ?></span>
            </div>
            <a class="ranking-refresh" href="/ranking"><?= $escape($t('view.catalog.refresh_ranking')) ?> <span aria-hidden="true">↻</span></a>
        </div>
        <?php if ($leaders === []): ?>
            <p class="ranking-empty"><?= $escape($t('view.catalog.ranking_waiting')) ?></p>
        <?php else: ?>
            <ol class="ranking-list" aria-label="<?= $escape($t('view.catalog.current_user_ranking')) ?>">
                <?php foreach ($leaders as $leader): ?>
                    <?php $isOwnRow = $ownRanking !== null && $leader['userId'] === $ownRanking['userId']; ?>
                    <li class="ranking-row<?= $leader['rank'] <= 3 ? ' ranking-row-leading' : '' ?><?= $isOwnRow ? ' ranking-row-own' : '' ?>" value="<?= $escape($leader['rank']) ?>" data-ranking-user-id="<?= $escape($leader['userId']) ?>" data-ranking-position="<?= $escape($leader['rank']) ?>" data-total-exp="<?= $escape($leader['totalExp']) ?>" data-user-level="<?= $escape($leader['level']) ?>">
                        <span class="ranking-position" aria-label="<?= $escape($t('view.catalog.rank_label', ['rank' => $leader['rank']])) ?>">#<?= $escape($leader['rank']) ?></span>
                        <a class="ranking-user" href="/users/<?= $escape($leader['userId']) ?>" aria-label="<?= $escape($t('view.catalog.profile_label', ['user' => $leader['name']])) ?>">
                            <span class="ranking-avatar" aria-hidden="true">
                                <?php if ($leader['avatarUrl'] !== null): ?>
                                    <img src="<?= $escape($leader['avatarUrl']) ?>" alt="" width="44" height="44" loading="lazy" decoding="async">
                                <?php else: ?>
                                    ✦
                                <?php endif; ?>
                            </span>
                            <span class="ranking-user-name"><?= $escape($leader['name']) ?><?php if ($isOwnRow): ?> <span class="ranking-you"><?= $escape($t('view.catalog.you')) ?></span><?php endif; ?></span>
                        </a>
                        <dl class="ranking-values">
                            <div><dt><?= $escape($t('view.catalog.level')) ?></dt><dd><?= $escape($leader['level']) ?></dd></div>
                            <div><dt>EXP</dt><dd><?= $escape(number_format($leader['totalExp'], 0, ',', ' ')) ?></dd></div>
                        </dl>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
