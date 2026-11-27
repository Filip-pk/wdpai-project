<?php

use App\Service\ModerationNoticeFormatter;

require __DIR__ . '/partials/header.html.php';

$adminTabs = ['overview', 'diagnostics', 'errors', 'users', 'reports', 'catalog', 'history'];
$pageUrl = static function (int $page) use ($tab, $filters): string {
    $query = ['tab' => $tab, 'page' => $page];
    foreach (['query', 'missing', 'level', 'module'] as $filter) {
        if (($filters[$filter] ?? '') !== '') {
            $query[$filter] = $filters[$filter];
        }
    }
    return '/admin?' . http_build_query($query);
};
$pagination = match ($tab) {
    'errors' => $logEntries['pagination'],
    'users' => $userList['pagination'],
    'reports' => $messageReports['pagination'],
    'catalog' => $catalog['pagination'],
    'history' => $history['pagination'],
    default => null,
};
$formatDate = static fn (string $date): string => ModerationNoticeFormatter::date($date);
?>

<section class="admin-page" aria-labelledby="admin-heading" data-admin-panel="<?= $escape($tab) ?>">
    <header class="admin-hero">
        <div>
            <p class="admin-eyebrow">AniVibe · <?= $escape($t('admin.eyebrow')) ?></p>
            <h1 id="admin-heading"><?= $escape($t('admin.title')) ?></h1>
            <p class="admin-intro"><?= $escape($t('admin.intro')) ?></p>
        </div>
        <span class="admin-badge"><?= $escape($t('admin.role.admin')) ?></span>
    </header>

    <nav class="admin-tabs" aria-label="<?= $escape($t('admin.title')) ?>">
        <?php foreach ($adminTabs as $section): ?>
            <a href="/admin?tab=<?= $escape($section) ?>"<?= $tab === $section ? ' aria-current="page"' : '' ?>><?= $escape($t('admin.' . $section)) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($errors !== []): ?>
        <div class="admin-notice admin-notice-error" role="alert">
            <?php foreach ($errors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($notice !== null): ?>
        <p class="admin-notice admin-notice-success" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <?php if ($tab === 'overview'): ?>
        <section aria-labelledby="admin-overview-heading">
            <h2 id="admin-overview-heading"><?= $escape($t('admin.overview.title')) ?></h2>
            <dl class="admin-stats">
                <?php foreach (['users', 'anime', 'openCommentReports', 'openMessageReports'] as $metric): ?>
                    <div class="admin-stat">
                        <dt><?= $escape($t('admin.metric.' . $metric)) ?></dt>
                        <dd><?= $escape($overview[$metric]) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
        <div class="admin-grid">
            <article class="admin-card admin-shortcut">
                <span class="admin-card-symbol" aria-hidden="true">↗</span>
                <h2><?= $escape($t('admin.diagnostics')) ?></h2>
                <p><?= $escape($t('admin.overview.diagnostics')) ?></p>
                <a href="/admin?tab=diagnostics"><?= $escape($t('admin.open_diagnostics')) ?> <span aria-hidden="true">→</span></a>
            </article>
            <article class="admin-card admin-shortcut">
                <span class="admin-card-symbol" aria-hidden="true">!</span>
                <h2><?= $escape($t('admin.errors')) ?></h2>
                <p><?= $escape($t('admin.overview.errors')) ?></p>
                <a href="/admin?tab=errors"><?= $escape($t('admin.open_errors')) ?> <span aria-hidden="true">→</span></a>
            </article>
            <article class="admin-card admin-shortcut">
                <span class="admin-card-symbol" aria-hidden="true">✉</span>
                <h2><?= $escape($t('admin.reports')) ?></h2>
                <p><?= $escape($t('admin.overview.reports')) ?></p>
                <a href="/admin?tab=reports"><?= $escape($t('admin.open_reports')) ?> <span aria-hidden="true">→</span></a>
            </article>
            <?php if ($isModerator): ?>
                <article class="admin-card admin-shortcut">
                    <span class="admin-card-symbol" aria-hidden="true">✓</span>
                    <h2><?= $escape($t('navigation.moderation')) ?></h2>
                    <p><?= $escape($t('admin.overview.moderation')) ?></p>
                    <a href="/moderation"><?= $escape($t('admin.open_moderation')) ?> <span aria-hidden="true">→</span></a>
                </article>
            <?php endif; ?>
        </div>
    <?php elseif ($tab === 'diagnostics'): ?>
        <section class="admin-card admin-toolbar" aria-labelledby="admin-diagnostics-heading">
            <div>
                <h2 id="admin-diagnostics-heading"><?= $escape($t('admin.diagnostics.title')) ?></h2>
                <p class="admin-muted"><?= $escape($t('admin.diagnostics.help')) ?></p>
            </div>
            <form method="post" action="/admin/diagnostics">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <button type="submit"><?= $escape($t('admin.refresh')) ?></button>
            </form>
        </section>
        <?php if ($diagnostics === []): ?>
            <p class="admin-card admin-empty"><?= $escape($t('admin.diagnostics.empty')) ?></p>
        <?php else: ?>
            <div class="admin-grid admin-diagnostics">
                <?php foreach ($diagnostics as $check): ?>
                    <article class="admin-card admin-service" data-service="<?= $escape($check['service']) ?>" data-status="<?= $escape($check['status']) ?>" aria-labelledby="service-<?= $escape($check['service']) ?>">
                        <div class="admin-card-top">
                            <h3 id="service-<?= $escape($check['service']) ?>"><?= $escape($t('admin.service.' . $check['service'])) ?></h3>
                            <span class="admin-status" data-status="<?= $escape($check['status']) ?>"><?= $escape($t('admin.status.' . $check['status'])) ?></span>
                        </div>
                        <p><?= $escape($t($check['messageKey'], $check['parameters'])) ?></p>
                        <?php if ($check['details'] !== []): ?>
                            <details class="admin-check-details">
                                <summary><?= $escape($t('admin.details')) ?></summary>
                                <ul><?php foreach ($check['details'] as $detail): ?><li><?= $escape($detail) ?></li><?php endforeach; ?></ul>
                            </details>
                        <?php endif; ?>
                        <dl class="admin-meta admin-service-meta">
                            <div><dt><?= $escape($t('admin.duration')) ?></dt><dd><?= $escape($t('admin.milliseconds', ['count' => $check['durationMs']])) ?></dd></div>
                            <div><dt><?= $escape($t('admin.checked_at')) ?></dt><dd><time datetime="<?= $escape($check['checkedAt']) ?>"><?= $escape($formatDate($check['checkedAt'])) ?></time></dd></div>
                        </dl>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <section class="admin-card admin-toolbar" aria-labelledby="admin-mail-heading">
            <div>
                <h2 id="admin-mail-heading"><?= $escape($t('admin.mail.title')) ?></h2>
                <p class="admin-muted"><?= $escape($t('admin.mail.help')) ?></p>
            </div>
            <form method="post" action="/admin/mail-test">
                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                <button type="submit" class="admin-button-secondary"><?= $escape($t('admin.mail.send')) ?></button>
            </form>
        </section>
    <?php elseif ($tab === 'reports'): ?>
        <p class="admin-muted"><?= $escape($t('admin.reports.help')) ?></p>
        <?php if ($messageReports['items'] === []): ?><p class="admin-card admin-empty"><?= $escape($t('admin.reports.empty')) ?></p><?php endif; ?>
        <ol class="admin-list" aria-label="<?= $escape($t('admin.reports')) ?>">
            <?php foreach ($messageReports['items'] as $report): ?>
                <li><article class="admin-card admin-report" data-report-id="<?= $escape($report['id']) ?>" aria-labelledby="report-<?= $escape($report['id']) ?>">
                    <div class="admin-card-top">
                        <h2 id="report-<?= $escape($report['id']) ?>"><?= $escape($t('admin.reports.item', ['id' => $report['id']])) ?></h2>
                        <time class="admin-muted" datetime="<?= $escape($report['createdAt']) ?>"><?= $escape($formatDate($report['createdAt'])) ?></time>
                    </div>
                    <dl class="admin-meta">
                        <div><dt><?= $escape($t('admin.reports.reporter')) ?></dt><dd><?= $escape($report['reporterName']) ?></dd></div>
                        <div><dt><?= $escape($t('admin.reports.sender')) ?></dt><dd><?= $escape($report['reportedName'] ?? $t('admin.reports.deleted_account')) ?></dd></div>
                    </dl>
                    <div class="admin-report-section">
                        <h3><?= $escape($t('admin.reports.snapshot')) ?></h3>
                        <blockquote class="admin-report-snapshot"><?= $escape($report['snapshot']) ?></blockquote>
                    </div>
                    <div class="admin-report-section">
                        <h3><?= $escape($t('admin.reports.reason')) ?></h3>
                        <p class="admin-history-reason"><?= $escape($report['reason']) ?></p>
                    </div>
                    <?php if ($report['reportedIsAdmin']): ?>
                        <p class="admin-muted"><?= $escape($t('admin.reports.admin_protected')) ?></p>
                    <?php elseif (!$report['canHide']): ?>
                        <p class="admin-muted"><?= $escape($t('admin.reports.unavailable')) ?></p>
                    <?php endif; ?>
                    <form class="admin-role-form" method="post" action="/admin/reports/<?= $escape($report['id']) ?>/dismiss">
                        <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                        <div class="admin-field">
                            <label for="report-reason-<?= $escape($report['id']) ?>"><?= $escape($t('admin.reports.decision_reason')) ?></label>
                            <textarea id="report-reason-<?= $escape($report['id']) ?>" name="reason" rows="2" minlength="1" maxlength="1000" required></textarea>
                        </div>
                        <div class="admin-report-actions">
                            <button class="admin-button-secondary" type="submit"><?= $escape($t('admin.reports.dismiss')) ?></button>
                            <?php if ($report['canHide']): ?>
                                <button type="submit" formaction="/admin/reports/<?= $escape($report['id']) ?>/hide"><?= $escape($t('admin.reports.hide')) ?></button>
                            <?php endif; ?>
                        </div>
                    </form>
                </article></li>
            <?php endforeach; ?>
        </ol>
    <?php elseif (in_array($tab, ['errors', 'users', 'catalog'], true)): ?>
        <form class="admin-card admin-filters" method="get" action="/admin" aria-label="<?= $escape($t('admin.filters')) ?>">
            <input type="hidden" name="tab" value="<?= $escape($tab) ?>">
            <?php if ($tab === 'errors'): ?>
                <div class="admin-field">
                    <label for="admin-level"><?= $escape($t('admin.level')) ?></label>
                    <select id="admin-level" name="level">
                        <?php foreach (['', 'error', 'warning', 'info'] as $level): ?>
                            <option value="<?= $escape($level) ?>"<?= ($filters['level'] ?? '') === $level ? ' selected' : '' ?>><?= $escape($t($level === '' ? 'admin.all' : 'admin.level.' . $level)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-field">
                    <label for="admin-module"><?= $escape($t('admin.module')) ?></label>
                    <select id="admin-module" name="module">
                        <?php foreach (array_merge([''], $logModules) as $module): ?>
                            <option value="<?= $escape($module) ?>"<?= ($filters['module'] ?? '') === $module ? ' selected' : '' ?>><?= $escape($module === '' ? $t('admin.all') : $module) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="admin-field">
                    <label for="admin-query"><?= $escape($t($tab === 'users' ? 'admin.users.search' : 'admin.catalog.search')) ?></label>
                    <input id="admin-query" name="query" type="search" maxlength="200" value="<?= $escape($filters['query'] ?? '') ?>">
                </div>
                <?php if ($tab === 'catalog'): ?>
                    <div class="admin-field">
                        <label for="admin-missing"><?= $escape($t('admin.catalog.missing')) ?></label>
                        <select id="admin-missing" name="missing">
                            <?php foreach (['all', 'description', 'image'] as $missing): ?>
                                <option value="<?= $escape($missing) ?>"<?= ($filters['missing'] ?? 'all') === $missing ? ' selected' : '' ?>><?= $escape($t($missing === 'all' ? 'admin.all' : 'admin.catalog.missing_' . $missing)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="admin-filter-actions">
                <button type="submit"><?= $escape($t('admin.filter')) ?></button>
                <a href="/admin?tab=<?= $escape($tab) ?>"><?= $escape($t('admin.reset')) ?></a>
            </div>
        </form>

        <?php if ($tab === 'errors'): ?>
            <p class="admin-muted"><?= $escape($t('admin.errors.help')) ?></p>
            <?php if ($logEntries['items'] === []): ?><p class="admin-card admin-empty"><?= $escape($t('admin.errors.empty')) ?></p><?php endif; ?>
            <ol class="admin-list" aria-label="<?= $escape($t('admin.errors')) ?>">
                <?php foreach ($logEntries['items'] as $entry): ?>
                    <li><article class="admin-card admin-log" data-log-id="<?= $escape($entry['id']) ?>">
                        <div class="admin-card-top">
                            <h2><?= $escape($t($entry['messageKey'])) ?></h2>
                            <span class="admin-status" data-status="<?= $escape(['error' => 'error', 'warning' => 'warning'][$entry['level']] ?? 'ok') ?>"><?= $escape($t('admin.level.' . $entry['level'])) ?></span>
                        </div>
                        <dl class="admin-meta">
                            <div><dt><?= $escape($t('admin.identifier')) ?></dt><dd><code><?= $escape($entry['id']) ?></code></dd></div>
                            <div><dt><?= $escape($t('admin.created_at')) ?></dt><dd><time datetime="<?= $escape($entry['createdAt']) ?>"><?= $escape($formatDate($entry['createdAt'])) ?></time></dd></div>
                            <div><dt><?= $escape($t('admin.module')) ?></dt><dd><?= $escape($entry['module']) ?></dd></div>
                            <?php foreach (['class', 'path', 'service', 'errorId', 'code', 'account', 'client', ...$cleanupCounts] as $contextKey): ?>
                                <?php if (isset($entry['context'][$contextKey])): ?><div><dt><?= $escape($t('admin.context.' . $contextKey)) ?></dt><dd><code><?= $escape($entry['context'][$contextKey]) ?></code></dd></div><?php endif; ?>
                            <?php endforeach; ?>
                        </dl>
                    </article></li>
                <?php endforeach; ?>
            </ol>
        <?php elseif ($tab === 'users'): ?>
            <p class="admin-muted"><?= $escape($t('admin.users.help')) ?></p>
            <?php if ($userList['items'] === []): ?><p class="admin-card admin-empty"><?= $escape($t('admin.empty')) ?></p><?php endif; ?>
            <ol class="admin-list" aria-label="<?= $escape($t('admin.users')) ?>">
                <?php foreach ($userList['items'] as $user): ?>
                    <li><article class="admin-card admin-user" data-user-id="<?= $escape($user['id']) ?>">
                        <div class="admin-card-top">
                            <h2><a href="/users/<?= $escape($user['id']) ?>"><?= $escape($user['name']) ?></a> <span class="admin-muted">#<?= $escape($user['id']) ?></span></h2>
                            <span class="admin-badge"><?= $escape($t('admin.role.' . ($user['isAdmin'] ? 'admin' : ($user['isModerator'] ? 'moderator' : 'user')))) ?></span>
                        </div>
                        <p class="admin-user-email"><?= $escape($user['email']) ?></p>
                        <?php if ($user['banEndsAt'] !== null): ?>
                            <p class="admin-muted"><?= $escape($t('admin.users.banned_until')) ?> <time datetime="<?= $escape($user['banEndsAt']) ?>"><?= $escape($formatDate($user['banEndsAt'])) ?></time></p>
                        <?php else: ?><p class="admin-muted"><?= $escape($t('admin.users.active')) ?></p><?php endif; ?>
                        <?php if (!$user['isAdmin'] && $user['id'] !== $adminUserId && $user['banEndsAt'] === null): ?>
                            <form class="admin-role-form" method="post" action="/admin/users/<?= $escape($user['id']) ?>/moderator">
                                <input type="hidden" name="csrfToken" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="enabled" value="<?= $user['isModerator'] ? '0' : '1' ?>">
                                <div class="admin-field">
                                    <label for="role-reason-<?= $escape($user['id']) ?>"><?= $escape($t('admin.reason')) ?></label>
                                    <textarea id="role-reason-<?= $escape($user['id']) ?>" name="reason" rows="2" minlength="1" maxlength="1000" required></textarea>
                                </div>
                                <button class="admin-button-secondary" type="submit"><?= $escape($t($user['isModerator'] ? 'admin.users.revoke_moderator' : 'admin.users.grant_moderator')) ?></button>
                            </form>
                        <?php endif; ?>
                    </article></li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <dl class="admin-stats">
                <?php foreach (['total', 'missingDescription', 'missingImage'] as $metric): ?>
                    <div class="admin-stat"><dt><?= $escape($t('admin.catalog.metric.' . $metric)) ?></dt><dd><?= $escape($catalog['summary'][$metric]) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <p class="admin-muted"><?= $escape($t('admin.catalog.help')) ?></p>
            <?php if ($catalog['items'] === []): ?><p class="admin-card admin-empty"><?= $escape($t('admin.empty')) ?></p><?php endif; ?>
            <ol class="admin-list" aria-label="<?= $escape($t('admin.catalog')) ?>">
                <?php foreach ($catalog['items'] as $anime): ?>
                    <li><article class="admin-card admin-catalog-entry" data-anime-id="<?= $escape($anime['id']) ?>">
                        <h2><a href="/anime/<?= $escape($anime['id']) ?>"><?= $escape($anime['title']) ?></a> <span class="admin-muted">#<?= $escape($anime['id']) ?></span></h2>
                        <div class="admin-tags">
                            <span class="admin-status" data-status="<?= $anime['hasDescription'] ? 'ok' : 'warning' ?>"><?= $escape($t($anime['hasDescription'] ? 'admin.catalog.has_description' : 'admin.catalog.missing_description')) ?></span>
                            <span class="admin-status" data-status="<?= $anime['hasImage'] ? 'ok' : 'warning' ?>"><?= $escape($t($anime['hasImage'] ? 'admin.catalog.has_image' : 'admin.catalog.missing_image')) ?></span>
                        </div>
                    </article></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    <?php else: ?>
        <p class="admin-muted"><?= $escape($t('admin.history.help')) ?></p>
        <?php if ($history['items'] === []): ?><p class="admin-card admin-empty"><?= $escape($t('admin.empty')) ?></p><?php endif; ?>
        <ol class="admin-list" aria-label="<?= $escape($t('admin.history')) ?>">
            <?php foreach ($history['items'] as $entry): ?>
                <li><article class="admin-card admin-history-entry" data-admin-action-id="<?= $escape($entry['id']) ?>">
                    <h2><?= $escape($t('admin.action.' . $entry['action'])) ?></h2>
                    <dl class="admin-meta">
                        <div><dt><?= $escape($t('admin.created_at')) ?></dt><dd><time datetime="<?= $escape($entry['createdAt']) ?>"><?= $escape($formatDate($entry['createdAt'])) ?></time></dd></div>
                        <div><dt><?= $escape($t('admin.history.actor')) ?></dt><dd><?= $escape($entry['actorName'] ?? $t('admin.history.cli')) ?></dd></div>
                        <?php if (($entry['targetName'] ?? null) !== null): ?><div><dt><?= $escape($t('admin.history.target')) ?></dt><dd><?= $escape($entry['targetName']) ?></dd></div><?php endif; ?>
                        <?php if (isset($entry['details']['result']) && in_array($entry['details']['result'], ['accepted', 'error'], true)): ?><div><dt><?= $escape($t('admin.history.result')) ?></dt><dd><?= $escape($t('admin.result.' . $entry['details']['result'])) ?></dd></div><?php endif; ?>
                        <?php if (isset($entry['details']['errorId'])): ?><div><dt><?= $escape($t('admin.context.errorId')) ?></dt><dd><code><?= $escape($entry['details']['errorId']) ?></code></dd></div><?php endif; ?>
                    </dl>
                    <?php if (isset($entry['details']['reason'])): ?><p class="admin-history-reason"><strong><?= $escape($t('admin.reason')) ?>:</strong> <?= $escape($entry['details']['reason']) ?></p><?php endif; ?>
                </article></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <?php if ($pagination !== null && $pagination['totalPages'] > 1): ?>
        <nav class="admin-pagination" aria-label="<?= $escape($t('admin.pagination')) ?>">
            <?php if ($pagination['page'] > 1): ?><a href="<?= $escape($pageUrl($pagination['page'] - 1)) ?>" rel="prev"><?= $escape($t('view.interface.previous')) ?></a><?php endif; ?>
            <span><?= $escape($t('view.social.page_of', ['page' => $pagination['page'], 'total' => $pagination['totalPages']])) ?></span>
            <?php if ($pagination['page'] < $pagination['totalPages']): ?><a href="<?= $escape($pageUrl($pagination['page'] + 1)) ?>" rel="next"><?= $escape($t('view.interface.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/partials/footer.html.php'; ?>
