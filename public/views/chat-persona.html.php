<?php require __DIR__ . '/partials/header.html.php'; ?>

<a href="/dashboard#personas-title">Wróć do postaci</a>

<header class="chat-heading">
    <h1>Czat z postacią: <?= $escape($persona['name']) ?></h1>

    <p>Anime: <?= $escape($persona['anime']) ?></p>
    <p>Rozmowa z AI odgrywającą fikcyjną postać.</p>
</header>

<?php if ($isNaruto): ?>
    <section class="ninja-bond" aria-labelledby="bond-title">
        <h2 id="bond-title">
            Więź Ninja: Przyjaciel — poziom 4
        </h2>

        <label for="ninja-progress">
            Przykładowy postęp: 720 / 1000 XP
        </label>

        <progress id="ninja-progress" value="720" max="1000">
            720 / 1000 XP
        </progress>

        <p>Ranga: Genin</p>
    </section>

    <section aria-labelledby="persona-conversation-title">
        <h2 id="persona-conversation-title">
            Przykładowa rozmowa
        </h2>

        <ol class="chat-messages">
            <li>
                <article class="message message-user">
                    <h3>Ty</h3>

                    <p>
                        Siemanko Naruto! Jak tam trening
                        z Jiraiyą? Trudno jest?
                    </p>

                    <time datetime="14:36">14:36</time>
                </article>
            </li>

            <li>
                <article class="message message-persona">
                    <h3>Naruto Uzumaki</h3>

                    <p>
                        Siemano! Dattebayo! Jiraiya-sama daje mi
                        niezły wycisk, ale nie poddam się,
                        bo zostanę Hokage!
                    </p>

                    <p>
                        Choć teraz chętnie wciągnąłbym wielką
                        miskę ramenu w Ichiraku...
                    </p>

                    <p>
                        A u Ciebie co słychać?!
                        Masz dzisiaj wolną chwilę na mały sparing?
                    </p>

                    <time datetime="14:37">14:37</time>
                </article>
            </li>
        </ol>
    </section>

    <aside class="ramen-card" aria-labelledby="ramen-title">
        <h2 id="ramen-title">Miska ramenu Ichiraku</h2>

        <p>
            Wirtualny prezent i punkty więzi —
            element demonstracyjny z makiety.
        </p>

        <button type="button" disabled>Podaruj ramen</button>
    </aside>

    <section aria-labelledby="quick-replies-title">
        <h2 id="quick-replies-title">Szybkie odpowiedzi</h2>

        <button type="button" disabled>
            Powodzenia na treningu!
        </button>

        <button type="button" disabled>
            Chodźmy razem na ramen!
        </button>

        <button type="button" disabled>
            Naucz mnie Rasengana!
        </button>
    </section>

    <article class="adventure-card" aria-labelledby="adventure-title">
        <h2 id="adventure-title">
            Karta przygody: Misja Konoha
        </h2>

        <p>Wspólna misja z drużyną Kakashiego.</p>
        <p>Podgląd elementu z makiety.</p>

        <button type="button" disabled>Zobacz przygodę</button>
    </article>
<?php else: ?>
    <p>
        Nie rozpoczęto rozmowy.
        To podgląd szablonu kolejnej persony.
    </p>
<?php endif; ?>

<form action="/chat/message" method="post" class="chat-form">
    <fieldset disabled>
        <legend>Wiadomość do postaci</legend>

        <label for="persona-message">Napisz wiadomość</label>

        <textarea
            id="persona-message"
            name="message"
            rows="3"
            maxlength="2000"
            required
        ></textarea>

        <button type="submit">Wyślij</button>
    </fieldset>
</form>

<?php require __DIR__ . '/partials/footer.html.php'; ?>