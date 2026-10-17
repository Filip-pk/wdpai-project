<?php require __DIR__ . '/partials/header.html.php'; ?>

<header class="chat-heading">
    <h1>Asystent AI — Ollama</h1>

    <p>Pomoc w wyborze anime dopasowanego do Twojego nastroju.</p>

    <p>
        Przykładowe wykorzystanie limitu:
        <?= $escape($user['aiUsed']) ?>
        /
        <?= $escape($user['aiLimit']) ?>
        zapytań.
    </p>
</header>

<section aria-labelledby="assistant-conversation-title">
    <h2 id="assistant-conversation-title">
        Przykładowa rozmowa
    </h2>

    <ol class="chat-messages">
        <li>
            <article class="message message-user">
                <h3>Ty</h3>

                <p>
                    Szukam anime w klimacie cyberpunka
                    z ciekawą muzyką. Co polecasz?
                </p>

                <time datetime="14:22">14:22</time>
            </article>
        </li>

        <li>
            <article class="message message-assistant">
                <h3>Asystent AI</h3>

                <p>
                    Sprawdź Cyberpunk: Edgerunners —
                    historię Davida i Lucy
                    w futurystycznym Night City.
                </p>

                <time datetime="14:23">14:23</time>
            </article>
        </li>
    </ol>
</section>

<section aria-labelledby="suggested-anime-title">
    <h2 id="suggested-anime-title">Proponowany tytuł</h2>

    <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
</section>

<section aria-labelledby="quick-questions-title">
    <h2 id="quick-questions-title">Szybkie pytania</h2>

    <button type="button" disabled>Poleć coś podobnego</button>
    <button type="button" disabled>Coś z magią</button>
    <button type="button" disabled>Najlepsze ścieżki dźwiękowe</button>
</section>

<form action="/chat" method="post" class="chat-form">
    <fieldset disabled>
        <legend>Wiadomość do asystenta</legend>

        <label for="assistant-message">
            Napisz, jakiego anime szukasz
        </label>

        <textarea
            id="assistant-message"
            name="message"
            rows="3"
            maxlength="2000"
            required
        ></textarea>

        <button type="submit">Wyślij</button>
    </fieldset>
</form>

<?php require __DIR__ . '/partials/footer.html.php'; ?>