<?php require __DIR__ . '/partials/header.html.php'; ?>

<a href="/projects" class="project-back">
    <span aria-hidden="true">←</span>
    Wróć do projektów
</a>

<article
    class="project-details"
    data-project-id="<?= $escape($project->getId()) ?>"
    data-project-status="<?= $escape($project->getStatus()) ?>"
>
    <header class="project-heading">
        <span class="project-status">
            Status: <?= $escape($projectStatuses[$project->getStatus()] ?? $project->getStatus()) ?>
        </span>
        <h1><?= $escape($project->getTitle()) ?></h1>
    </header>

    <section aria-labelledby="project-description-title">
        <h2 id="project-description-title">Opis projektu</h2>
        <p class="project-description"><?= $escape($project->getDescription() === null || $project->getDescription() === '' ? 'Brak opisu.' : $project->getDescription()) ?></p>
    </section>

    <section aria-labelledby="project-meta-title">
        <h2 id="project-meta-title">Informacje o projekcie</h2>
        <dl class="project-meta">
            <div>
                <dt>Identyfikator projektu</dt>
                <dd><?= $escape($project->getId()) ?></dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd><?= $escape($projectStatuses[$project->getStatus()] ?? $project->getStatus()) ?></dd>
            </div>
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
            <div>
                <dt>Ostatnia zmiana</dt>
                <dd>
                    <?php if ($project->getUpdatedAt() !== null): ?>
                        <time datetime="<?= $escape($project->getUpdatedAt()->format(DATE_ATOM)) ?>">
                            <?= $escape($project->getUpdatedAt()->format('d.m.Y H:i')) ?>
                        </time>
                    <?php else: ?>
                        Brak daty
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </section>
</article>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
