<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ranking-page">
    <header class="ranking-heading">
        <p class="eyebrow">Społeczność AniVibe</p>
        <h1>Ranking TOP 100</h1>
        <p>Aktualna setka użytkowników z największą liczbą EXP. Oglądaj anime, zapisuj postępy i zdobywaj kolejne poziomy.</p>
    </header>

    <div class="ranking-overview">
        <?php if ($ownRanking !== null): ?>
            <section class="ranking-own" aria-labelledby="ranking-own-title" data-own-ranking-user-id="<?= $escape($ownRanking['userId']) ?>" data-own-ranking-rank="<?= $escape($ownRanking['rank']) ?>" data-own-ranking-exp="<?= $escape($ownRanking['totalExp']) ?>">
                <h2 id="ranking-own-title">Twoja pozycja</h2>
                <p class="ranking-own-position">#<?= $escape($ownRanking['rank']) ?></p>
                <p><?= $isInTop100 ? 'Jesteś w aktualnym TOP 100.' : 'Twoje miejsce w całej społeczności. Zdobywaj EXP i dołącz do TOP 100.' ?></p>
                <a href="/watchlist">Przejdź do watchlisty <span aria-hidden="true">→</span></a>
            </section>
        <?php endif; ?>
        <?php require __DIR__ . '/partials/experience.html.php'; ?>
    </div>

    <details class="ranking-rules">
        <summary>Jak zdobywać EXP i poziomy?</summary>
        <ul>
            <li>Każdy obejrzany odcinek daje <strong>5 EXP</strong>.</li>
            <li>Ukończenie tytułu daje dodatkowe <strong>100 EXP</strong>. Osobny sezon lub film liczy się jako jeden tytuł w katalogu.</li>
            <li>Pierwszy awans wymaga 50 EXP, kolejny 100 EXP, następny 150 EXP. Każdy poziom wymaga o 50 EXP więcej.</li>
            <li>Te same odcinki i ukończenie danego tytułu liczą się raz. Usunięcie anime z watchlisty zachowuje EXP; poprawienie błędnego postępu koryguje punkty.</li>
        </ul>
        <p>Ranking jest przeliczany przy każdym otwarciu strony. Remisy mają wspólne miejsce; lista obejmuje maksymalnie 100 kont. Postęp oglądania jest deklarowany przez użytkownika.</p>
    </details>

    <section class="ranking-board" aria-labelledby="ranking-board-title">
        <div class="ranking-board-heading">
            <div>
                <h2 id="ranking-board-title">Najwięcej zdobytego EXP</h2>
                <span class="ranking-count"><?= count($leaders) ?> / 100 użytkowników</span>
            </div>
            <a class="ranking-refresh" href="/ranking">Odśwież ranking <span aria-hidden="true">↻</span></a>
        </div>
        <?php if ($leaders === []): ?>
            <p class="ranking-empty">Ranking czeka na pierwszych uczestników.</p>
        <?php else: ?>
            <ol class="ranking-list" aria-label="Aktualny ranking użytkowników">
                <?php foreach ($leaders as $leader): ?>
                    <?php $isOwnRow = $ownRanking !== null && $leader['userId'] === $ownRanking['userId']; ?>
                    <li class="ranking-row<?= $isOwnRow ? ' ranking-row-own' : '' ?>" value="<?= $escape($leader['rank']) ?>" data-ranking-user-id="<?= $escape($leader['userId']) ?>" data-ranking-position="<?= $escape($leader['rank']) ?>" data-total-exp="<?= $escape($leader['totalExp']) ?>" data-user-level="<?= $escape($leader['level']) ?>">
                        <span class="ranking-position" aria-label="<?= $escape('Miejsce ' . $leader['rank']) ?>">#<?= $escape($leader['rank']) ?></span>
                        <a class="ranking-user" href="/users/<?= $escape($leader['userId']) ?>" aria-label="<?= $escape('Profil: ' . $leader['name']) ?>">
                            <span class="ranking-avatar" aria-hidden="true">
                                <?php if ($leader['avatarUrl'] !== null): ?>
                                    <img src="<?= $escape($leader['avatarUrl']) ?>" alt="" width="44" height="44" loading="lazy" decoding="async">
                                <?php else: ?>
                                    ✦
                                <?php endif; ?>
                            </span>
                            <span class="ranking-user-name"><?= $escape($leader['name']) ?><?php if ($isOwnRow): ?> <span class="ranking-you">Ty</span><?php endif; ?></span>
                        </a>
                        <dl class="ranking-values">
                            <div><dt>Poziom</dt><dd><?= $escape($leader['level']) ?></dd></div>
                            <div><dt>EXP</dt><dd><?= $escape(number_format($leader['totalExp'], 0, ',', ' ')) ?></dd></div>
                        </dl>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
