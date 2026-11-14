<?php
require __DIR__ . '/partials/header.html.php';

$quiz = $quiz ?? null;
$quizResult = $quizResult ?? null;
$quizErrors = $quizErrors ?? [];
$quizAnswers = $quizAnswers ?? [];
$quizStats = $quizStats ?? null;
?>

<div class="quiz-page" data-quiz-page data-quiz-state="<?= !$isLoggedIn ? 'guest' : ($quizResult !== null ? 'completed' : 'available') ?>">
    <header class="quiz-heading">
        <div>
            <p class="quiz-eyebrow"><?= $escape($t('quiz.eyebrow')) ?></p>
            <h1><?= $escape($t('quiz.title')) ?></h1>
            <p><?= $escape($t('quiz.intro')) ?></p>
        </div>
        <a class="quiz-back-link" href="/dashboard"><?= $escape($t('quiz.back')) ?> <span aria-hidden="true">↗</span></a>
    </header>

    <?php if ($quizErrors !== []): ?>
        <div class="quiz-errors" id="quiz-errors" role="alert">
            <ul>
                <?php foreach ($quizErrors as $error): ?>
                    <li><?= $escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$isLoggedIn): ?>
        <section class="quiz-guest" aria-labelledby="quiz-guest-title">
            <span class="quiz-mark" aria-hidden="true">✦</span>
            <h2 id="quiz-guest-title"><?= $escape($t('quiz.guest_title')) ?></h2>
            <p><?= $escape($t('quiz.guest_intro')) ?></p>
            <p class="quiz-help"><?= $escape($t('quiz.streak_rule')) ?></p>
            <div class="quiz-actions">
                <a class="button button-primary" href="/login"><?= $escape($t('quiz.login')) ?></a>
                <a href="/register"><?= $escape($t('quiz.register')) ?></a>
            </div>
        </section>
    <?php else: ?>
        <?php if ($quizStats !== null): ?>
        <dl class="quiz-statistics" aria-label="<?= $escape($t('quiz.statistics')) ?>" data-quiz-streak="<?= $escape($quizStats['currentStreak']) ?>" data-quiz-best-streak="<?= $escape($quizStats['bestStreak']) ?>">
            <div>
                <dt><?= $escape($t('quiz.current_streak')) ?></dt>
                <dd><strong><?= $escape($quizStats['currentStreak']) ?></strong> <?= $escape($t($quizStats['currentStreak'] === 1 ? 'quiz.day' : 'quiz.days')) ?></dd>
            </div>
            <div>
                <dt><?= $escape($t('quiz.best_streak')) ?></dt>
                <dd><strong><?= $escape($quizStats['bestStreak']) ?></strong> <?= $escape($t($quizStats['bestStreak'] === 1 ? 'quiz.day' : 'quiz.days')) ?></dd>
            </div>
            <div>
                <dt><?= $escape($t('quiz.today')) ?></dt>
                <dd class="quiz-participation"><?= $escape($t($quizStats['completedToday'] ? 'quiz.completed' : 'quiz.pending')) ?></dd>
            </div>
        </dl>
        <?php endif; ?>

        <?php if ($quiz !== null): ?>
            <div class="quiz-day-heading">
                <p><?= $escape($t('quiz.set_date')) ?> <time datetime="<?= $escape($quiz['date']) ?>"><?= $escape($quiz['date']) ?></time></p>
                <p><?= $escape($t('quiz.rollover')) ?></p>
            </div>

            <?php if ($locale !== 'pl'): ?>
                <p class="quiz-language-note" id="quiz-language-note"><?= $escape($t('quiz.language_note')) ?></p>
            <?php endif; ?>

            <?php if ($quizResult !== null): ?>
                <section class="quiz-result" aria-labelledby="quiz-result-title" data-quiz-score="<?= $escape($quizResult['score']) ?>" data-quiz-total="<?= $escape($quizResult['total']) ?>">
                    <div class="quiz-result-score" aria-label="<?= $escape($t('quiz.score_label', ['score' => $quizResult['score'], 'total' => $quizResult['total']])) ?>">
                        <strong><?= $escape($quizResult['score']) ?></strong><span>/ <?= $escape($quizResult['total']) ?></span>
                    </div>
                    <div class="quiz-result-copy">
                        <p class="quiz-eyebrow"><?= $escape($t('quiz.result_eyebrow')) ?></p>
                        <h2 id="quiz-result-title"><?= $escape($t('quiz.result_title')) ?></h2>
                        <p><?= $escape($t('quiz.result_intro')) ?></p>
                        <p class="quiz-help"><?= $escape($t('quiz.next_quiz')) ?></p>
                    </div>
                </section>

                <section class="quiz-review" aria-labelledby="quiz-review-title"<?= $locale !== 'pl' ? ' lang="pl"' : '' ?>>
                    <h2 id="quiz-review-title" lang="<?= $escape($locale) ?>"><?= $escape($t('quiz.review')) ?></h2>
                    <ol class="quiz-review-list">
                        <?php foreach ($quiz['questions'] as $question): ?>
                            <?php
                            $chosenOption = $quizResult['answers'][$question['id']] ?? null;
                            $isCorrect = $chosenOption === $question['correctOption'];
                            ?>
                            <li class="quiz-review-item">
                                <article>
                                    <div class="quiz-review-heading">
                                        <h3><?= $escape($question['question']) ?></h3>
                                        <span class="quiz-answer-status<?= $isCorrect ? ' quiz-answer-correct' : ' quiz-answer-incorrect' ?>" lang="<?= $escape($locale) ?>"><?= $escape($t($isCorrect ? 'quiz.correct' : 'quiz.incorrect')) ?></span>
                                    </div>
                                    <dl class="quiz-review-answers">
                                        <div>
                                            <dt lang="<?= $escape($locale) ?>"><?= $escape($t('quiz.your_answer')) ?></dt>
                                            <dd><?= $escape($question['options'][$chosenOption] ?? $t('quiz.no_answer')) ?></dd>
                                        </div>
                                        <?php if (!$isCorrect): ?>
                                            <div>
                                                <dt lang="<?= $escape($locale) ?>"><?= $escape($t('quiz.correct_answer')) ?></dt>
                                                <dd><?= $escape($question['options'][$question['correctOption']]) ?></dd>
                                            </div>
                                        <?php endif; ?>
                                    </dl>
                                    <p class="quiz-explanation"><?= $escape($question['explanation']) ?></p>
                                    <?php if (!empty($question['sourceUrl'])): ?>
                                        <a class="quiz-source" href="<?= $escape($question['sourceUrl']) ?>" rel="external noreferrer" lang="<?= $escape($locale) ?>"><?= $escape($t('quiz.source')) ?> <span aria-hidden="true">↗</span></a>
                                    <?php endif; ?>
                                </article>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php else: ?>
                <div class="quiz-rules" id="quiz-rules">
                    <p><?= $escape($t('quiz.form_intro')) ?></p>
                    <p><?= $escape($t('quiz.streak_rule')) ?></p>
                </div>

                <form class="quiz-form" action="/quiz" method="post" data-quiz-form aria-describedby="quiz-rules<?= $quizErrors !== [] ? ' quiz-errors' : '' ?><?= $locale !== 'pl' ? ' quiz-language-note' : '' ?>">
                    <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="quizId" value="<?= $escape($quiz['id']) ?>">

                    <?php foreach ($quiz['questions'] as $questionIndex => $question): ?>
                        <?php
                        $selectedAnswer = $quizAnswers[$question['id']] ?? null;
                        $selectedOption = is_int($selectedAnswer) || is_string($selectedAnswer) ? (string) $selectedAnswer : '';
                        ?>
                        <fieldset class="quiz-question" data-quiz-question-id="<?= $escape($question['id']) ?>" lang="pl">
                            <legend>
                                <span class="quiz-question-number" lang="<?= $escape($locale) ?>"><?= $escape($t('quiz.question_number', ['number' => $questionIndex + 1, 'total' => count($quiz['questions'])])) ?></span>
                                <span><?= $escape($question['question']) ?></span>
                            </legend>
                            <div class="quiz-options">
                                <?php foreach ($question['options'] as $optionIndex => $option): ?>
                                    <label class="quiz-option" for="quiz-<?= $escape($question['id']) ?>-<?= $escape($optionIndex) ?>">
                                        <input type="radio" id="quiz-<?= $escape($question['id']) ?>-<?= $escape($optionIndex) ?>" name="answers[<?= $escape($question['id']) ?>]" value="<?= $escape($optionIndex) ?>"<?= $selectedOption === (string) $optionIndex ? ' checked' : '' ?> required>
                                        <span class="quiz-option-letter" aria-hidden="true"><?= $escape(chr(65 + $optionIndex)) ?></span>
                                        <span><?= $escape($option) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endforeach; ?>

                    <div class="quiz-submit">
                        <button class="button button-primary" type="submit"><?= $escape($t('quiz.submit')) ?></button>
                        <p class="quiz-help"><?= $escape($t('quiz.submit_help')) ?></p>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a class="button button-primary quiz-reload" href="/quiz"><?= $escape($t('quiz.reload')) ?></a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
