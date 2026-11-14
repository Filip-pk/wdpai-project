<section class="daily-quiz-card" aria-labelledby="daily-quiz-title"<?= $quizStats !== null ? ' data-quiz-streak="' . $escape($quizStats['currentStreak']) . '" data-quiz-best-streak="' . $escape($quizStats['bestStreak']) . '"' : '' ?>>
    <div class="daily-quiz-copy">
        <p class="quiz-eyebrow"><?= $escape($t('quiz.card_eyebrow')) ?></p>
        <h2 id="daily-quiz-title"><span aria-hidden="true">✦</span> <?= $escape($t('quiz.title')) ?></h2>
        <p><?= $escape($t('quiz.card_intro')) ?></p>
        <?php if ($isLoggedIn && $quizStats !== null): ?>
            <dl class="daily-quiz-statistics">
                <div>
                    <dt><?= $escape($t('quiz.current_streak')) ?></dt>
                    <dd><strong><?= $escape($quizStats['currentStreak']) ?></strong> <?= $escape($t($quizStats['currentStreak'] === 1 ? 'quiz.day' : 'quiz.days')) ?></dd>
                </div>
                <div>
                    <dt><?= $escape($t('quiz.best_streak')) ?></dt>
                    <dd><strong><?= $escape($quizStats['bestStreak']) ?></strong> <?= $escape($t($quizStats['bestStreak'] === 1 ? 'quiz.day' : 'quiz.days')) ?></dd>
                </div>
                <div data-quiz-correct="<?= $escape($quizStats['correct']) ?>" data-quiz-answered="<?= $escape($quizStats['total']) ?>">
                    <dt><?= $escape($t('quiz.total_score')) ?></dt>
                    <dd><strong><?= $escape($quizStats['correct']) ?></strong> / <?= $escape($quizStats['total']) ?></dd>
                </div>
            </dl>
        <?php endif; ?>
    </div>
    <div class="daily-quiz-action">
        <?php if ($isLoggedIn && $quizStats !== null): ?>
            <p class="daily-quiz-status"><?= $escape($t($quizStats['completedToday'] ? 'quiz.card_completed' : 'quiz.card_pending')) ?></p>
            <a class="button button-primary" href="/quiz"><?= $escape($t($quizStats['completedToday'] ? 'quiz.view_result' : 'quiz.start')) ?> <span aria-hidden="true">→</span></a>
            <a href="/quiz/ranking"><?= $escape($t('quiz.ranking.title')) ?></a>
        <?php elseif ($isLoggedIn): ?>
            <a class="button button-primary" href="/quiz"><?= $escape($t('quiz.start')) ?> <span aria-hidden="true">→</span></a>
        <?php else: ?>
            <p class="daily-quiz-status"><?= $escape($t('quiz.card_guest')) ?></p>
            <a class="button button-primary" href="/login"><?= $escape($t('quiz.login')) ?> <span aria-hidden="true">→</span></a>
        <?php endif; ?>
    </div>
</section>
