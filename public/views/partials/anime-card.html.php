<article class="anime-card">
    <h3>
        <a href="/anime/<?= $escape($animeId) ?>">
            <?= $escape($anime['title']) ?>
        </a>
    </h3>

    <p><?= $escape(implode(' · ', $anime['genres'])) ?></p>

    <p><?= $escape($anime['description']) ?></p>

    <dl>
        <dt>Ocena demonstracyjna</dt>
        <dd><?= $escape($anime['score']) ?> / 10</dd>

        <dt>Liczba odcinków</dt>
        <dd><?= $escape($anime['episodes']) ?></dd>
    </dl>

    <button type="button" disabled>
        Dodaj do watchlisty
    </button>
</article>