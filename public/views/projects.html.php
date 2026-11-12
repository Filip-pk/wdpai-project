<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="projects-page">
    <header class="projects-heading social-hero">
        <div>
            <p class="social-kicker">Twoja przestrzeń projektów</p>
            <h1 class="social-title">Projekty</h1>
            <?php if (($currentUserName ?? '') !== ''): ?>
                <p class="projects-greeting">Witaj, <?= $escape($currentUserName) ?>!</p>
            <?php endif; ?>
            <p class="social-description">Sprawdź status, opis i szczegóły projektów.</p>
        </div>
        <span class="social-pill">Projektów: <?= $escape(count($projects)) ?></span>
    </header>

    <?php if ($projects === []): ?>
        <section class="project-empty social-empty" aria-labelledby="projects-empty-title">
            <span class="social-empty-icon" aria-hidden="true">◇</span>
            <h2 id="projects-empty-title">Brak projektów</h2>
            <p>Obecnie lista projektów jest pusta.</p>
        </section>
    <?php else: ?>
        <ul class="projects-grid" aria-label="Lista projektów">
            <?php foreach ($projects as $project): ?>
                <li>
                    <article
                        class="project-card"
                        data-project-id="<?= $escape($project->getId()) ?>"
                        data-project-status="<?= $escape($project->getStatus()) ?>"
                    >
                        <header class="project-card-heading">
                            <div class="project-card-topline">
                                <span class="project-symbol" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/><path d="M3 11h18"/></svg>
                                </span>
                                <span class="project-number">#<?= $escape($project->getId()) ?></span>
                            </div>
                            <span class="project-status">Status: <?= $escape($projectStatuses[$project->getStatus()] ?? $project->getStatus()) ?></span>
                            <h2>
                                <a href="/projects/<?= $escape($project->getId()) ?>">
                                    <?= $escape($project->getTitle()) ?>
                                </a>
                            </h2>
                        </header>

                        <p class="project-description"><?= $escape($project->getDescription() === null || $project->getDescription() === '' ? 'Brak opisu.' : $project->getDescription()) ?></p>

                        <dl class="project-meta">
                            <div>
                                <dt>Utworzono</dt>
                                <dd>
                                    <?php if ($project->getCreatedAt() !== null): ?>
                                        <time datetime="<?= $escape($project->getCreatedAt()->format(DATE_ATOM)) ?>">
                                            <?= $escape($project->getCreatedAt()->format('d.m.Y H:i')) ?>
                                        </time>
                                    <?php else: ?>
                                        Brak daty
                                    <?php endif; ?>
                                </dd>
                            </div>
                        </dl>

                        <a
                            class="project-open"
                            href="/projects/<?= $escape($project->getId()) ?>"
                            aria-label="<?= $escape('Zobacz szczegóły projektu: ' . $project->getTitle()) ?>"
                        >
                            Zobacz szczegóły
                            <span aria-hidden="true">→</span>
                        </a>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
