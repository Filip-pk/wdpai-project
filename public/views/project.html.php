<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="project-page">
    <a href="/projects" class="project-back">
        <span aria-hidden="true">←</span>
        <?= $escape($t('view.interface.back_to_projects')) ?>
    </a>

    <article
        class="project-details"
        data-project-id="<?= $escape($project->getId()) ?>"
        data-project-status="<?= $escape($project->getStatus()) ?>"
    >
        <header class="project-heading social-hero">
            <div>
                <p class="social-kicker"><?= $escape($t('view.interface.project')) ?><?= $escape($project->getId()) ?></p>
                <h1 class="social-title"><?= $escape($project->getTitle()) ?></h1>
            </div>
            <span class="project-status">
                <?= $escape($t('view.interface.status')) ?> <?= $escape($translateText($projectStatuses[$project->getStatus()] ?? $project->getStatus())) ?>
            </span>
        </header>

        <div class="project-details-layout">
            <section class="project-description-panel" aria-labelledby="project-description-title">
                <span class="project-section-number" aria-hidden="true">01</span>
                <h2 id="project-description-title"><?= $escape($t('view.interface.project_description')) ?></h2>
                <p class="project-description"><?= $escape($project->getDescription() === null || $project->getDescription() === '' ? $t('view.interface.no_description') : $project->getDescription()) ?></p>
            </section>

            <section class="project-meta-panel" aria-labelledby="project-meta-title">
                <span class="project-section-number" aria-hidden="true">02</span>
                <h2 id="project-meta-title"><?= $escape($t('view.interface.project_information')) ?></h2>
                <dl class="project-meta">
                    <div>
                        <dt><?= $escape($t('view.interface.project_id')) ?></dt>
                        <dd><?= $escape($project->getId()) ?></dd>
                    </div>
                    <div>
                        <dt><?= $escape($t('view.interface.status_bae7d5')) ?></dt>
                        <dd><?= $escape($translateText($projectStatuses[$project->getStatus()] ?? $project->getStatus())) ?></dd>
                    </div>
                    <div>
                        <dt><?= $escape($t('view.interface.created')) ?></dt>
                        <dd>
                            <?php if ($project->getCreatedAt() !== null): ?>
                                <time datetime="<?= $escape($project->getCreatedAt()->format(DATE_ATOM)) ?>">
                                    <?= $escape($project->getCreatedAt()->format('d.m.Y H:i')) ?>
                                </time>
                            <?php else: ?>
                                <?= $escape($t('view.interface.no_date')) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt><?= $escape($t('view.interface.last_updated')) ?></dt>
                        <dd>
                            <?php if ($project->getUpdatedAt() !== null): ?>
                                <time datetime="<?= $escape($project->getUpdatedAt()->format(DATE_ATOM)) ?>">
                                    <?= $escape($project->getUpdatedAt()->format('d.m.Y H:i')) ?>
                                </time>
                            <?php else: ?>
                                <?= $escape($t('view.interface.no_date')) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </section>
        </div>
    </article>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
