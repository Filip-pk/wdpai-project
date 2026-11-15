<section class="daily-quiz-card" aria-labelledby="anime-clue-card-title">
    <div class="daily-quiz-copy">
        <p class="quiz-eyebrow"><?= $escape($t('guess.eyebrow')) ?></p>
        <h2 id="anime-clue-card-title"><?= $escape($t('guess.title')) ?></h2>
        <p><?= $escape($t('guess.intro')) ?></p>
    </div>
    <div class="daily-quiz-action">
        <a class="button button-primary" href="/guess"><?= $escape($t('guess.play')) ?> <span aria-hidden="true">→</span></a>
    </div>
</section>
