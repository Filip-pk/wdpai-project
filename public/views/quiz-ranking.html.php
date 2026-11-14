<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="quiz-page quiz-ranking-page" data-quiz-ranking-period="<?= $escape($quizRanking['period']) ?>">
    <header class="quiz-heading">
        <div>
            <p class="quiz-eyebrow"><?= $escape($t('quiz.eyebrow')) ?></p>
            <h1><?= $escape($t('quiz.ranking.title')) ?></h1>
            <p><?= $escape($t('quiz.ranking.intro')) ?></p>
        </div>
        <a class="quiz-back-link" href="/quiz"><?= $escape($t('quiz.title')) ?> <span aria-hidden="true">↗</span></a>
    </header>

    <?php if ($quizErrors !== []): ?>
        <div class="quiz-errors" role="alert"><?php foreach ($quizErrors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?></div>
    <?php endif; ?>

    <nav class="quiz-ranking-tabs" aria-label="<?= $escape($t('quiz.ranking.period')) ?>">
        <a href="/quiz/ranking"<?= $quizRanking['period'] === 'overall' ? ' aria-current="page"' : '' ?>><?= $escape($t('quiz.ranking.overall')) ?></a>
        <a href="/quiz/ranking?period=monthly"<?= $quizRanking['period'] === 'monthly' ? ' aria-current="page"' : '' ?>><?= $escape($t('quiz.ranking.monthly')) ?></a>
    </nav>
    <?php if ($quizRanking['period'] === 'monthly'): ?>
        <p class="quiz-help"><?= $escape($t('quiz.ranking.month')) ?> <time datetime="<?= $escape($quizRanking['month']) ?>"><?= $escape($quizRanking['month']) ?></time></p>
    <?php endif; ?>

    <?php $own = $quizRanking['ownRanking']; ?>
    <section class="quiz-ranking-own" aria-labelledby="quiz-own-title"<?= $own !== null ? ' data-own-quiz-rank="' . $escape($own['rank']) . '" data-own-quiz-correct="' . $escape($own['correct']) . '" data-own-quiz-total="' . $escape($own['total']) . '"' : '' ?>>
        <h2 id="quiz-own-title"><?= $escape($t('quiz.ranking.own')) ?></h2>
        <?php if ($own !== null): ?>
            <p class="quiz-ranking-own-score"><strong>#<?= $escape($own['rank']) ?></strong> <span><?= $escape($own['correct']) ?> / <?= $escape($own['total']) ?></span></p>
            <p class="quiz-help"><?= $escape($t('quiz.ranking.correct')) ?></p>
        <?php else: ?>
            <p><?= $escape($t('quiz.ranking.no_participation')) ?></p>
            <a href="/quiz"><?= $escape($t('quiz.start')) ?> <span aria-hidden="true">→</span></a>
        <?php endif; ?>
    </section>

    <section class="quiz-ranking-board" aria-labelledby="quiz-board-title">
        <h2 id="quiz-board-title"><?= $escape($t('quiz.ranking.top')) ?></h2>
        <p class="quiz-help"><?= $escape($t('quiz.ranking.rules')) ?></p>
        <?php if ($quizRanking['leaders'] === []): ?>
            <p class="quiz-ranking-empty"><?= $escape($t('quiz.ranking.empty')) ?></p>
        <?php else: ?>
            <ol class="quiz-ranking-list">
                <?php foreach ($quizRanking['leaders'] as $leader): ?>
                    <?php $isOwn = $leader['userId'] === $accountId; ?>
                    <li value="<?= $escape($leader['rank']) ?>" class="quiz-ranking-row<?= $isOwn ? ' quiz-ranking-row-own' : '' ?>" data-quiz-ranking-user="<?= $escape($leader['userId']) ?>" data-quiz-ranking-rank="<?= $escape($leader['rank']) ?>" data-quiz-ranking-correct="<?= $escape($leader['correct']) ?>">
                        <span class="quiz-ranking-position">#<?= $escape($leader['rank']) ?></span>
                        <a class="quiz-ranking-user" href="/users/<?= $escape($leader['userId']) ?>">
                            <span class="quiz-ranking-avatar" aria-hidden="true">
                                <?php if ($leader['avatarUrl'] !== null): ?>
                                    <img src="<?= $escape($leader['avatarUrl']) ?>" alt="" width="36" height="36" loading="lazy" decoding="async">
                                <?php else: ?>✦<?php endif; ?>
                            </span>
                            <span><?= $escape($leader['name']) ?><?php if ($isOwn): ?> <small><?= $escape($t('quiz.ranking.you')) ?></small><?php endif; ?></span>
                        </a>
                        <dl class="quiz-ranking-score">
                            <dt><?= $escape($t('quiz.ranking.correct')) ?></dt>
                            <dd><strong><?= $escape($leader['correct']) ?></strong> / <?= $escape($leader['total']) ?></dd>
                        </dl>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
