    </main>

    <footer class="site-footer">
        <aside class="prototype-notice" aria-label="<?= $escape($t('footer.prototype')) ?>">
            <p><strong><?= $escape($t('footer.demo')) ?></strong> <?= $escape($t('footer.demo_details')) ?></p>
        </aside>
        <div class="footer-main">
            <div class="footer-brand">
                <a href="/" class="footer-brand-link" aria-label="<?= $escape($t('header.home')) ?>">
                    <span class="footer-brand-mark" aria-hidden="true">🌸</span>
                    <span>AniVibe</span>
                </a>
                <p class="footer-brand-description"><?= $escape($t('footer.description')) ?></p>
                <p class="footer-brand-description"><?= $escape($t('footer.student_description')) ?></p>
            </div>

            <nav class="footer-navigation" aria-label="<?= $escape($t('footer.navigation')) ?>">
                <div class="footer-nav-group">
                    <h2><?= $escape($t('navigation.discover')) ?></h2>
                    <ul>
                        <li><a href="/anime"><?= $escape($t('navigation.catalog')) ?></a></li>
                        <li><a href="/dashboard"><?= $escape($t('footer.dashboard')) ?></a></li>
                        <li><a href="/watchlist"><?= $escape($t('footer.watchlist')) ?></a></li>
                    </ul>
                </div>
                <div class="footer-nav-group">
                    <h2><?= $escape($t('footer.project')) ?></h2>
                    <ul>
                        <li><a href="/projects"><?= $escape($t('navigation.projects')) ?></a></li>
                        <li><a href="/login"><?= $escape($t('footer.login')) ?></a></li>
                        <li><a href="/admin"><?= $escape($t('footer.persona')) ?></a></li>
                    </ul>
                </div>
            </nav>
        </div>

        <details class="footer-credits-toggle">
            <summary><?= $escape($t('footer.sources')) ?></summary>
            <section class="footer-credits" aria-labelledby="footer-credits-title">
                <h2 id="footer-credits-title"><?= $escape($t('footer.sources')) ?></h2>
                <ul class="footer-source-list">
                    <li class="footer-source-card">
                        <h3 class="footer-source-heading"><a href="https://github.com/cedya77/anime-offline-database" rel="external noreferrer">Anime Offline Database</a></h3>
                        <p class="footer-source-description"><?= $escape($t('footer.cedya')) ?> <a href="https://github.com/manami-project/anime-offline-database" rel="external noreferrer">manami-project</a>.</p>
                        <p><?= $escape($t('footer.licenses')) ?> <a href="https://opendatacommons.org/licenses/odbl/1-0/" rel="external noreferrer">ODbL 1.0</a> <?= $escape($t('footer.and')) ?> <a href="https://opendatacommons.org/licenses/dbcl/1-0/" rel="external noreferrer">DbCL 1.0</a>.</p>
                    </li>
                    <li class="footer-source-card">
                        <h3 class="footer-source-heading"><a href="https://huggingface.co/datasets/moviebrain01/anime-dataset-2025" rel="external noreferrer">Anime Dataset 2025</a></h3>
                        <p class="footer-source-description"><?= $escape($t('footer.snapshot')) ?></p>
                        <p><?= $escape($t('footer.declared_license')) ?> <a href="https://creativecommons.org/licenses/by/4.0/" rel="external noreferrer">CC BY 4.0</a>.</p>
                    </li>
                    <li class="footer-source-card">
                        <h3 class="footer-source-heading"><?= $escape($t('footer.earlier_sample')) ?></h3>
                        <p class="footer-source-description"><a href="https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime" rel="external noreferrer">Processed Top 15k Anime — Youssef ElNahas (TheRealVigilante)</a><?= $escape($t('footer.based_on')) ?> <a href="https://www.kaggle.com/datasets/quanthan/top-15000-ranked-anime-dataset-update-to-32025" rel="external noreferrer"><?= $escape($t('footer.quan')) ?></a>.</p>
                        <p><?= $escape($t('footer.source_license')) ?> <a href="https://www.apache.org/licenses/LICENSE-2.0" rel="external noreferrer">Apache 2.0</a> <?= $escape($t('footer.original_dataset')) ?></p>
                    </li>
                </ul>
                <p><?= $escape($t('footer.merged')) ?></p>
            </section>
        </details>

        <div class="footer-bottom">
            <p class="footer-copyright">AniVibe · <?= $escape(date('Y')) ?> · <?= $escape($t('footer.student')) ?></p>
            <div class="footer-legal-notes">
                <p><?= $escape($t('footer.unaffiliated')) ?></p>
                <p><?= $escape($t('footer.rights')) ?></p>
            </div>
        </div>
    </footer>
</body>
</html>
