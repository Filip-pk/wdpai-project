<?php require __DIR__ . '/partials/header.html.php'; ?>

<header class="projects-heading">
    <h1>Projekty</h1>
    <p>Sprawdź status, opis i szczegóły projektów.</p>
</header>

<?php if ($projects === []): ?>
    <section class="project-empty" aria-labelledby="projects-empty-title">
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
                        <span class="project-status">
                            Status: <?= $escape($projectStatuses[$project->getStatus()] ?? $project->getStatus()) ?>
                        </span>
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

<?php require __DIR__ . '/partials/footer.html.php'; ?>
