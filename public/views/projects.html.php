<?php require __DIR__ . '/partials/header.html.php'; ?>

<div class="projects-page">
    <header class="projects-heading social-hero">
        <div>
            <p class="social-kicker"><?= $escape($t('view.interface.your_project_space')) ?></p>
            <h1 class="social-title"><?= $escape($t('view.interface.projects')) ?></h1>
            <?php if (($currentUserName ?? '') !== ''): ?>
                <p class="projects-greeting"><?= $escape($t('view.interface.hello')) ?> <?= $escape($currentUserName) ?>!</p>
            <?php endif; ?>
            <p class="social-description"><?= $escape($t('view.interface.check_project_statuses_descriptions_and_details')) ?></p>
        </div>
        <span class="social-pill"><?= $escape($t('view.interface.projects_340ded')) ?> <?= $escape(count($projects)) ?></span>
    </header>

    <?php if ($projects === []): ?>
        <section class="project-empty social-empty" aria-labelledby="projects-empty-title">
            <span class="social-empty-icon" aria-hidden="true">◇</span>
            <h2 id="projects-empty-title"><?= $escape($t('view.interface.no_projects')) ?></h2>
            <p><?= $escape($t('view.interface.the_project_list_is_currently_empty')) ?></p>
        </section>
    <?php else: ?>
        <ul class="projects-grid" aria-label="<?= $escape($t('view.interface.project_list')) ?>">
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
                            <span class="project-status"><?= $escape($t('view.interface.status')) ?> <?= $escape($translateText($projectStatuses[$project->getStatus()] ?? $project->getStatus())) ?></span>
                            <h2>
                                <a href="/projects/<?= $escape($project->getId()) ?>">
                                    <?= $escape($project->getTitle()) ?>
                                </a>
                            </h2>
                        </header>

                        <p class="project-description"><?= $escape($project->getDescription() === null || $project->getDescription() === '' ? $t('view.interface.no_description') : $project->getDescription()) ?></p>

                        <dl class="project-meta">
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
                        </dl>

                        <a
                            class="project-open"
                            href="/projects/<?= $escape($project->getId()) ?>"
                            aria-label="<?= $escape($t('view.interface.view_project_details') . ' ' . $project->getTitle()) ?>"
                        >
                            <?= $escape($t('view.interface.view_details')) ?>
                            <span aria-hidden="true">→</span>
                        </a>
                    </article>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
