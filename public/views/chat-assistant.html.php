<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-assistant">
    <header class="chat-heading social-hero">
        <div>
            <p class="social-kicker">Odkrywaj z AI</p>
            <h1 class="social-title">Asystent AI — Ollama</h1>
            <p class="social-description">Pomoc w wyborze anime dopasowanego do Twojego nastroju.</p>
        </div>
        <span class="social-pill">Podgląd rozmowy</span>
    </header>

    <p id="assistant-demo-notice" class="ai-demo-notice"><span aria-hidden="true">◇</span> Wersja demonstracyjna. Rozmowa i propozycje poniżej są przykładowe. Wysyłanie wiadomości nie jest jeszcze dostępne.</p>

    <div class="ai-layout">
        <section class="ai-panel ai-chat-panel" aria-labelledby="assistant-conversation-title">
            <header class="ai-chat-title">
                <h2 id="assistant-conversation-title">Przykładowa rozmowa</h2>
                <span class="social-pill" aria-label="Przykładowe wykorzystanie limitu">Przykładowe wykorzystanie limitu: <?= $escape($user['aiUsed']) ?> / <?= $escape($user['aiLimit']) ?> zapytań.</span>
            </header>
            <ol class="chat-messages">
                <li>
                    <article class="message message-user">
                        <h3>Ty</h3>
                        <p>Szukam anime w klimacie cyberpunka z ciekawą muzyką. Co polecasz?</p>
                        <time datetime="14:22">14:22</time>
                    </article>
                </li>
                <li>
                    <article class="message message-assistant">
                        <h3>Asystent AI</h3>
                        <p>Sprawdź Cyberpunk: Edgerunners — historię Davida i Lucy w futurystycznym Night City.</p>
                        <time datetime="14:23">14:23</time>
                    </article>
                </li>
            </ol>
            <form action="/chat" method="post" class="chat-form">
                <fieldset disabled aria-describedby="assistant-demo-notice">
                    <legend>Wiadomość do asystenta</legend>
                    <label for="assistant-message">Napisz, jakiego anime szukasz</label>
                    <textarea id="assistant-message" name="message" rows="3" maxlength="2000" required></textarea>
                    <button type="submit">Wyślij <span aria-hidden="true">↗</span></button>
                </fieldset>
            </form>
        </section>

        <aside class="ai-rail" aria-label="Pomysły na kolejne anime">
            <section class="ai-panel ai-recommendation" aria-labelledby="suggested-anime-title">
                <h2 id="suggested-anime-title">Proponowany tytuł</h2>
                <?php require __DIR__ . '/partials/anime-card.html.php'; ?>
            </section>
            <section class="ai-panel" aria-labelledby="quick-questions-title">
                <h2 id="quick-questions-title">Szybkie pytania</h2>
                <div class="ai-quick-actions">
                    <button type="button" disabled>Poleć coś podobnego</button>
                    <button type="button" disabled>Coś z magią</button>
                    <button type="button" disabled>Najlepsze ścieżki dźwiękowe</button>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
