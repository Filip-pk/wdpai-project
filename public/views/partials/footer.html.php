    </main>

    <footer class="site-footer">
        <aside class="prototype-notice" aria-label="Informacja o prototypie">
            <p><strong>Wersja demonstracyjna.</strong> Konta, profile, watchlisty i prywatne kolekcje są zapisywane. Rozmowy AI pozostają demonstracyjne.</p>
        </aside>
        <div class="footer-main">
            <div class="footer-brand">
                <a href="/" class="footer-brand-link" aria-label="AniVibe — strona główna">
                    <span class="footer-brand-mark" aria-hidden="true">🌸</span>
                    <span>AniVibe</span>
                </a>
                <p class="footer-brand-description">Twój przewodnik po anime. Odkrywaj nowe historie i zbieraj ulubione tytuły w jednym miejscu.</p>
                <p class="footer-brand-description">Projekt studencki tworzony z myślą o fanach anime.</p>
            </div>

            <nav class="footer-navigation" aria-label="Dodatkowa nawigacja">
                <div class="footer-nav-group">
                    <h2>Odkrywaj</h2>
                    <ul>
                        <li><a href="/anime">Katalog anime</a></li>
                        <li><a href="/dashboard">Pulpit</a></li>
                        <li><a href="/watchlist">Twoja watchlista</a></li>
                    </ul>
                </div>
                <div class="footer-nav-group">
                    <h2>Projekt</h2>
                    <ul>
                        <li><a href="/projects">Projekty</a></li>
                        <li><a href="/login">Logowanie</a></li>
                        <li><a href="/admin">Podgląd kreatora persony AI</a></li>
                    </ul>
                </div>
            </nav>
        </div>

        <details class="footer-credits-toggle">
            <summary>Źródła danych i licencje</summary>
            <section class="footer-credits" aria-labelledby="footer-credits-title">
                <h2 id="footer-credits-title">Źródła danych i licencje</h2>
                <ul class="footer-source-list">
                    <li class="footer-source-card">
                        <h3 class="footer-source-heading"><a href="https://github.com/cedya77/anime-offline-database" rel="external noreferrer">Anime Offline Database</a></h3>
                        <p class="footer-source-description">Projekt cedya77, kontynuacja prac <a href="https://github.com/manami-project/anime-offline-database" rel="external noreferrer">manami-project</a>.</p>
                        <p>Licencje: <a href="https://opendatacommons.org/licenses/odbl/1-0/" rel="external noreferrer">ODbL 1.0</a> i <a href="https://opendatacommons.org/licenses/dbcl/1-0/" rel="external noreferrer">DbCL 1.0</a>.</p>
                    </li>
                    <li class="footer-source-card">
                        <h3 class="footer-source-heading"><a href="https://huggingface.co/datasets/moviebrain01/anime-dataset-2025" rel="external noreferrer">Anime Dataset 2025</a></h3>
                        <p class="footer-source-description">Snapshot metadanych autorstwa moviebrain01.</p>
                        <p>Deklarowana licencja: <a href="https://creativecommons.org/licenses/by/4.0/" rel="external noreferrer">CC BY 4.0</a>.</p>
                    </li>
                    <li class="footer-source-card">
                        <h3 class="footer-source-heading">Wcześniejsza próbka katalogu</h3>
                        <p class="footer-source-description"><a href="https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime" rel="external noreferrer">Processed Top 15k Anime — Youssef ElNahas (TheRealVigilante)</a>, na podstawie <a href="https://www.kaggle.com/datasets/quanthan/top-15000-ranked-anime-dataset-update-to-32025" rel="external noreferrer">zbioru Quan Than</a>.</p>
                        <p>Karta źródła wskazuje <a href="https://www.apache.org/licenses/LICENSE-2.0" rel="external noreferrer">Apache 2.0</a> dla zbioru oryginalnego.</p>
                    </li>
                </ul>
                <p>Dane połączono i ujednolicono; część nazw gatunków przetłumaczono na polski. Źródło konkretnego anime wskazano w jego szczegółach.</p>
            </section>
        </details>

        <div class="footer-bottom">
            <p class="footer-copyright">AniVibe · <?= $escape(date('Y')) ?> · Projekt studencki</p>
            <div class="footer-legal-notes">
                <p>Projekt nie jest powiązany z wymienionymi serwisami.</p>
                <p>Oznaczenia licencji pochodzą od wydawców zbiorów; opisy i grafiki mogą podlegać odrębnym prawom.</p>
            </div>
        </div>
    </footer>
</body>
</html>
