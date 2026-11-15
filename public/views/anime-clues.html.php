<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="guess-page" data-guess-page>
    <header class="guess-heading">
        <div>
            <p class="guess-eyebrow"><?= $escape($t('guess.eyebrow')) ?></p>
            <h1><?= $escape($t('guess.title')) ?></h1>
            <p><?= $escape($t('guess.intro')) ?></p>
        </div>
        <a class="guess-link" href="/guess/ranking"><?= $escape($t('guess.ranking.title')) ?> <span aria-hidden="true">↗</span></a>
        <a class="guess-link" href="/achievements"><?= $escape($t('achievements.title')) ?> <span aria-hidden="true">↗</span></a>
    </header>

    <?php if ($gameErrors !== []): ?>
        <div class="guess-errors" role="alert"><ul><?php foreach ($gameErrors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (!$isLoggedIn): ?>
        <section class="guess-panel" aria-labelledby="guess-guest-title">
            <h2 id="guess-guest-title"><?= $escape($t('guess.guest_title')) ?></h2>
            <p><?= $escape($t('guess.guest_intro')) ?></p>
            <p class="guess-help"><?= $escape($t('guess.scoring')) ?></p>
            <a class="button button-primary" href="/login"><?= $escape($t('guess.login')) ?></a>
        </section>
    <?php else: ?>
        <?php if ($gameStats !== null): ?>
            <dl class="guess-statistics" data-guess-total-points="<?= $escape($gameStats['points']) ?>" data-guess-total-correct="<?= $escape($gameStats['correct']) ?>">
                <div><dt><?= $escape($t('guess.total_points')) ?></dt><dd><?= $escape($gameStats['points']) ?></dd></div>
                <div><dt><?= $escape($t('guess.total_correct')) ?></dt><dd><?= $escape($gameStats['correct']) ?> / <?= $escape($gameStats['answered']) ?></dd></div>
                <div><dt><?= $escape($t('guess.current_streak')) ?></dt><dd><?= $escape($gameStats['currentStreak']) ?></dd></div>
                <div><dt><?= $escape($t('guess.best_streak')) ?></dt><dd><?= $escape($gameStats['bestStreak']) ?></dd></div>
            </dl>
        <?php endif; ?>

        <?php if ($gameState === null): ?>
            <a class="button button-primary" href="/guess"><?= $escape($t('guess.reload')) ?></a>
        <?php else: ?>
            <div class="guess-day-heading">
                <p><?= $escape($t('guess.set_date')) ?> <time datetime="<?= $escape($gameState['date']) ?>"><?= $escape($gameState['date']) ?></time></p>
                <p><?= $escape($t('guess.rollover')) ?></p>
            </div>

            <section class="guess-panel guess-score" data-guess-score="<?= $escape($gameState['score']) ?>" data-guess-completed="<?= $gameState['completed'] ? '1' : '0' ?>">
                <div>
                    <h2><?= $escape($t($gameState['completed'] ? 'guess.completed' : 'guess.daily_score')) ?></h2>
                    <p class="guess-score-number"><strong><?= $escape($gameState['score']) ?></strong> / <?= $escape($gameState['maxPoints']) ?></p>
                </div>
                <div>
                    <label for="guess-day-progress"><?= $escape($t('guess.round_progress')) ?> <?= $escape($gameState['roundIndex']) ?> / <?= $escape($gameState['roundCount']) ?></label>
                    <progress id="guess-day-progress" value="<?= $escape($gameState['roundIndex']) ?>" max="<?= $escape($gameState['roundCount']) ?>"><?= $escape($gameState['roundIndex']) ?> / <?= $escape($gameState['roundCount']) ?></progress>
                    <p class="guess-help"><?= $escape($t('guess.participation')) ?></p>
                </div>
            </section>

            <?php if ($gameState['completed']): ?>
                <p class="guess-notice" role="status"><?= $escape($t('guess.finished', ['correct' => $gameState['correctCount']])) ?></p>
                <p><?= $escape($t('guess.tomorrow')) ?></p>
            <?php else: ?>
                <section class="guess-panel guess-round" aria-labelledby="guess-round-title" data-guess-round="<?= $escape($gameState['roundIndex']) ?>" data-guess-hints="<?= $escape($gameState['hintsUsed']) ?>">
                    <header class="guess-round-heading">
                        <h2 id="guess-round-title"><?= $escape($t('guess.round_title', ['number' => $gameState['roundIndex'] + 1])) ?></h2>
                        <span class="guess-available-points"><?= $escape($t('guess.available_points', ['points' => 4 - $gameState['hintsUsed']])) ?></span>
                    </header>
                    <ol class="guess-clues">
                        <?php foreach ($gameState['clues'] as $index => $clue): ?>
                            <li data-guess-clue="<?= $escape($index + 1) ?>"><span><?= $escape($t('guess.clue_number', ['number' => $index + 1])) ?></span><p><?= $escape($clue) ?></p></li>
                        <?php endforeach; ?>
                    </ol>
                    <?php if (($gameState['wrongAnswers'] ?? []) !== []): ?>
                        <div class="guess-retry" role="status" data-guess-wrong-answers>
                            <p><?= $escape($t('guess.retry')) ?></p>
                            <ul class="guess-wrong-answers">
                                <?php foreach ($gameState['wrongAnswers'] as $wrongAnswer): ?>
                                    <li data-guess-wrong-answer><span><?= $escape($t('guess.wrong_attempt', ['number' => $wrongAnswer['hintsUsed']])) ?></span> <?= $escape($wrongAnswer['answer']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php if ($gameState['hintsUsed'] < 3): ?>
                        <form action="/guess/reveal" method="post" class="guess-hint-form">
                            <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="gameId" value="<?= $escape($gameState['id']) ?>">
                            <input type="hidden" name="roundIndex" value="<?= $escape($gameState['roundIndex']) ?>">
                            <input type="hidden" name="expectedHints" value="<?= $escape($gameState['hintsUsed']) ?>">
                            <button type="submit" class="guess-secondary"><?= $escape($t('guess.reveal', ['points' => 3 - $gameState['hintsUsed']])) ?></button>
                        </form>
                    <?php endif; ?>
                    <form action="/guess/answer" method="post" class="guess-answer-form" data-guess-answer-form>
                        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="gameId" value="<?= $escape($gameState['id']) ?>">
                        <input type="hidden" name="roundIndex" value="<?= $escape($gameState['roundIndex']) ?>">
                        <input type="hidden" name="expectedHints" value="<?= $escape($gameState['hintsUsed']) ?>">
                        <label for="guess-answer"><?= $escape($t('guess.answer_label')) ?></label>
                        <div class="guess-autocomplete" data-guess-autocomplete
                            data-help="<?= $escape($t('guess.suggestions.help')) ?>"
                            data-loading="<?= $escape($t('guess.suggestions.loading')) ?>"
                            data-results="<?= $escape($t('guess.suggestions.results')) ?>"
                            data-empty="<?= $escape($t('guess.suggestions.empty')) ?>"
                            data-error="<?= $escape($t('guess.suggestions.error')) ?>"
                            data-selected="<?= $escape($t('guess.suggestions.selected')) ?>">
                            <input id="guess-answer" name="answer" type="text" maxlength="200" autocomplete="off" spellcheck="false" required aria-describedby="guess-answer-help">
                            <ul id="guess-suggestions" class="guess-suggestions" role="listbox" aria-label="<?= $escape($t('guess.suggestions.label')) ?>" hidden></ul>
                        </div>
                        <p class="guess-help" id="guess-answer-help"><?= $escape($t('guess.answer_help')) ?></p>
                        <p class="guess-help guess-suggestions-status" id="guess-suggestions-status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
                        <button type="submit" class="button button-primary"><?= $escape($t('guess.submit')) ?></button>
                    </form>
                    <form action="/guess/skip" method="post" class="guess-skip-form">
                        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="gameId" value="<?= $escape($gameState['id']) ?>">
                        <input type="hidden" name="roundIndex" value="<?= $escape($gameState['roundIndex']) ?>">
                        <button type="submit" class="guess-secondary"><?= $escape($t('guess.skip')) ?></button>
                    </form>
                </section>
                <p class="guess-help"><?= $escape($t('guess.scoring')) ?></p>
            <?php endif; ?>

            <?php if ($gameState['results'] !== []): ?>
                <section class="guess-review" aria-labelledby="guess-review-title">
                    <h2 id="guess-review-title"><?= $escape($t('guess.review')) ?></h2>
                    <ol class="guess-results">
                        <?php foreach ($gameState['results'] as $result): ?>
                            <li class="guess-panel" data-guess-result="<?= $escape($result['roundIndex']) ?>" data-guess-points="<?= $escape($result['points']) ?>">
                                <div class="guess-result-heading"><h3><?= $escape($result['title']) ?></h3><strong><?= $escape($t('guess.points', ['points' => $result['points']])) ?></strong></div>
                                <p class="guess-answer-status"><?= $escape($t($result['skipped'] ? 'guess.skipped' : ($result['correct'] ? 'guess.correct' : 'guess.incorrect'))) ?></p>
                                <?php if (!$result['skipped'] && ($result['correct'] || ($result['wrongAnswers'] ?? []) === [])): ?><p><?= $escape($t('guess.your_answer')) ?> <?= $escape($result['answer']) ?></p><?php endif; ?>
                                <?php if (($result['wrongAnswers'] ?? []) !== []): ?>
                                    <ul class="guess-wrong-answers">
                                        <?php foreach ($result['wrongAnswers'] as $wrongAnswer): ?>
                                            <li data-guess-wrong-answer><span><?= $escape($t('guess.wrong_attempt', ['number' => $wrongAnswer['hintsUsed']])) ?></span> <?= $escape($wrongAnswer['answer']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <p class="guess-help"><?= $escape($t('guess.hints_used', ['count' => $result['hintsUsed']])) ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script src="/js/anime-clues.js" defer></script>
<?php require __DIR__ . '/partials/footer.html.php'; ?>
