<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="ai-page ai-persona">
    <a href="/dashboard#personas-title" class="ai-back">← Wróć do postaci</a>
    <header class="chat-heading social-hero">
        <div class="ai-persona-intro">
            <span class="ai-persona-avatar" aria-hidden="true">✦</span>
            <div class="ai-persona-copy">
                <p class="social-kicker">Anime: <?= $escape($persona['anime']) ?></p>
                <h1 class="social-title">Czat z postacią: <?= $escape($persona['name']) ?></h1>
                <p class="social-description">Rozmowa z AI odgrywającą fikcyjną postać.</p>
            </div>
        </div>
        <span class="social-pill">Podgląd persony</span>
    </header>

    <p id="persona-demo-notice" class="ai-demo-notice"><span aria-hidden="true">◇</span> Wersja demonstracyjna. Wiadomości i elementy więzi są przykładowe. Wysyłanie wiadomości nie jest jeszcze dostępne.</p>

    <div class="ai-layout<?= !$isNaruto ? ' ai-layout-single' : '' ?>">
        <section class="ai-panel ai-chat-panel" aria-labelledby="persona-conversation-title">
            <header class="ai-chat-title">
                <h2 id="persona-conversation-title"><?= $isNaruto ? 'Przykładowa rozmowa' : 'Twoja rozmowa' ?></h2>
                <span class="social-pill">Fikcyjna postać</span>
            </header>
            <?php if ($isNaruto): ?>
                <ol class="chat-messages">
                    <li>
                        <article class="message message-user">
                            <h3>Ty</h3>
                            <p>Siemanko Naruto! Jak tam trening z Jiraiyą? Trudno jest?</p>
                            <time datetime="14:36">14:36</time>
                        </article>
                    </li>
                    <li>
                        <article class="message message-persona">
                            <h3>Naruto Uzumaki</h3>
                            <p>Siemano! Dattebayo! Jiraiya-sama daje mi niezły wycisk, ale nie poddam się, bo zostanę Hokage!</p>
                            <p>Choć teraz chętnie wciągnąłbym wielką miskę ramenu w Ichiraku...</p>
                            <p>A u Ciebie co słychać?! Masz dzisiaj wolną chwilę na mały sparing?</p>
                            <time datetime="14:37">14:37</time>
                        </article>
                    </li>
                </ol>
            <?php else: ?>
                <div class="social-empty">
                    <span class="social-empty-icon" aria-hidden="true">✦</span>
                    <p>Nie rozpoczęto rozmowy. To podgląd szablonu kolejnej persony.</p>
                    <a href="/dashboard#personas-title">Poznaj pozostałe postaci <span aria-hidden="true">→</span></a>
                </div>
            <?php endif; ?>

            <form action="/chat/message" method="post" class="chat-form">
                <fieldset disabled aria-describedby="persona-demo-notice">
                    <legend>Wiadomość do postaci</legend>
                    <label for="persona-message">Napisz wiadomość</label>
                    <textarea id="persona-message" name="message" rows="3" maxlength="2000" required></textarea>
                    <button type="submit">Wyślij <span aria-hidden="true">↗</span></button>
                </fieldset>
            </form>
        </section>

        <?php if ($isNaruto): ?>
            <aside class="ai-rail" aria-label="Więź i dodatkowe elementy persony">
                <section class="ai-panel ninja-bond" aria-labelledby="bond-title">
                    <h2 id="bond-title">Więź Ninja: Przyjaciel — poziom 4</h2>
                    <label for="ninja-progress">Przykładowy postęp: 720 / 1000 XP</label>
                    <progress id="ninja-progress" value="720" max="1000">720 / 1000 XP</progress>
                    <p class="ai-bond-rank">Ranga: Genin</p>
                </section>
                <aside class="ai-panel ramen-card" aria-labelledby="ramen-title">
                    <span class="ai-card-symbol" aria-hidden="true">🍜</span>
                    <h2 id="ramen-title">Miska ramenu Ichiraku</h2>
                    <p>Wirtualny prezent i punkty więzi — element demonstracyjny z makiety.</p>
                    <button type="button" disabled>Podaruj ramen</button>
                </aside>
                <section class="ai-panel" aria-labelledby="quick-replies-title">
                    <h2 id="quick-replies-title">Szybkie odpowiedzi</h2>
                    <div class="ai-quick-actions">
                        <button type="button" disabled>Powodzenia na treningu!</button>
                        <button type="button" disabled>Chodźmy razem na ramen!</button>
                        <button type="button" disabled>Naucz mnie Rasengana!</button>
                    </div>
                </section>
                <article class="ai-panel adventure-card" aria-labelledby="adventure-title">
                    <span class="ai-card-symbol" aria-hidden="true">◇</span>
                    <h2 id="adventure-title">Karta przygody: Misja Konoha</h2>
                    <p>Wspólna misja z drużyną Kakashiego.</p>
                    <p>Podgląd elementu z makiety.</p>
                    <button type="button" disabled>Zobacz przygodę</button>
                </article>
            </aside>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
