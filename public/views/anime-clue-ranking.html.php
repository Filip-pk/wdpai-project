<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="guess-page guess-ranking-page" data-guess-ranking data-guess-week-start="<?= $escape($clueRanking['weekStart']) ?>" data-guess-week-last-day="<?= $escape($clueRanking['weekLastDay']) ?>">
    <header class="guess-heading">
        <div>
            <p class="guess-eyebrow"><?= $escape($t('guess.eyebrow')) ?></p>
            <h1><?= $escape($t('guess.ranking.title')) ?></h1>
            <p><?= $escape($t('guess.ranking.intro')) ?></p>
        </div>
    </header>

    <nav class="guess-ranking-nav" aria-label="<?= $escape($t('guess.ranking.navigation')) ?>">
        <a href="/guess"><?= $escape($t('guess.play')) ?></a>
        <a href="/guess/ranking" aria-current="page"><?= $escape($t('guess.ranking.title')) ?></a>
    </nav>

    <p class="guess-help guess-ranking-period">
        <?= $escape($t('guess.ranking.period')) ?>
        <time datetime="<?= $escape($clueRanking['weekStart']) ?>"><?= $escape($clueRanking['weekStart']) ?></time>
        – <time datetime="<?= $escape($clueRanking['weekLastDay']) ?>"><?= $escape($clueRanking['weekLastDay']) ?></time>
        <span>(Europe/Warsaw)</span>
    </p>

    <?php $own = $clueRanking['ownRanking']; ?>
    <section class="guess-panel guess-ranking-own" aria-labelledby="guess-ranking-own-title"<?= $own !== null ? ' data-own-guess-rank="' . $escape($own['rank']) . '" data-own-guess-points="' . $escape($own['points']) . '" data-own-guess-correct="' . $escape($own['correct']) . '"' : '' ?>>
        <h2 id="guess-ranking-own-title"><?= $escape($t('guess.ranking.own')) ?></h2>
        <?php if ($own === null): ?>
            <p><?= $escape($t('guess.ranking.no_participation')) ?></p>
            <a class="guess-link" href="/guess"><?= $escape($t('guess.play')) ?> <span aria-hidden="true">→</span></a>
        <?php else: ?>
            <p class="guess-ranking-own-position"><strong>#<?= $escape($own['rank']) ?></strong> <span><?= $escape($t('guess.points', ['points' => $own['points']])) ?></span></p>
            <dl class="guess-ranking-own-values">
                <div><dt><?= $escape($t('guess.total_correct')) ?></dt><dd><?= $escape($own['correct']) ?> / <?= $escape($own['answered']) ?></dd></div>
                <div><dt><?= $escape($t('guess.ranking.days')) ?></dt><dd><?= $escape($own['completedDays']) ?></dd></div>
            </dl>
        <?php endif; ?>
    </section>

    <section class="guess-ranking-board" aria-labelledby="guess-ranking-board-title">
        <h2 id="guess-ranking-board-title"><?= $escape($t('guess.ranking.top')) ?></h2>
        <p class="guess-help"><?= $escape($t('guess.ranking.rules')) ?></p>
        <?php if ($clueRanking['leaders'] === []): ?>
            <p class="guess-panel guess-help"><?= $escape($t('guess.ranking.empty')) ?></p>
        <?php else: ?>
            <ol class="guess-ranking-list">
                <?php foreach ($clueRanking['leaders'] as $leader): ?>
                    <?php $isOwn = $leader['userId'] === $accountId; ?>
                    <li value="<?= $escape($leader['rank']) ?>" class="guess-ranking-row<?= $isOwn ? ' guess-ranking-row-own' : '' ?>" data-guess-ranking-user="<?= $escape($leader['userId']) ?>" data-guess-ranking-rank="<?= $escape($leader['rank']) ?>" data-guess-ranking-points="<?= $escape($leader['points']) ?>">
                        <span class="guess-ranking-position">#<?= $escape($leader['rank']) ?></span>
                        <a class="guess-ranking-user" href="/users/<?= $escape($leader['userId']) ?>">
                            <span class="guess-ranking-avatar" aria-hidden="true">
                                <?php if ($leader['avatarUrl'] !== null): ?><img src="<?= $escape($leader['avatarUrl']) ?>" alt="" width="36" height="36" loading="lazy" decoding="async"><?php else: ?>✦<?php endif; ?>
                            </span>
                            <span><?= $escape($leader['name']) ?><?php if ($isOwn): ?> <small><?= $escape($t('guess.ranking.you')) ?></small><?php endif; ?></span>
                        </a>
                        <dl class="guess-ranking-values">
                            <div><dt><?= $escape($t('guess.ranking.points')) ?></dt><dd><strong><?= $escape($leader['points']) ?></strong></dd></div>
                            <div><dt><?= $escape($t('guess.total_correct')) ?></dt><dd><?= $escape($leader['correct']) ?> / <?= $escape($leader['answered']) ?></dd></div>
                            <div><dt><?= $escape($t('guess.ranking.days')) ?></dt><dd><?= $escape($leader['completedDays']) ?></dd></div>
                        </dl>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
