<?php

$escape = static fn (string|int|float $value): string =>
    htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    );

$assetUrl = static function (string $path) use ($escape): string {
    $version = substr(
        hash_file('sha256', dirname(__DIR__, 2) . $path),
        0,
        12,
    );

    return $escape($path . '?v=' . $version);
};

$currentPath = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH,
);

$isErrorPage = $isErrorPage ?? false;
$isWelcome = !$isErrorPage && $currentPath === '/';
$isAuthPage = !$isErrorPage && in_array($currentPath, ['/login', '/register'], true);
$isWorkspace = !$isWelcome && !$isAuthPage && !$isErrorPage;
$isAiPage = !$isErrorPage && ($currentPath === '/chat' || $currentPath === '/admin'
    || str_starts_with((string) $currentPath, '/chat/'));
$isProjectsPage = !$isErrorPage && ($currentPath === '/projects'
    || str_starts_with((string) $currentPath, '/projects/'));
$isImportPage = !$isErrorPage && str_starts_with((string) $currentPath, '/profile/import');
$isModerationPage = !$isErrorPage && ($currentPath === '/moderation'
    || str_starts_with((string) $currentPath, '/moderation/')
    || in_array($currentPath, ['/account/banned', '/account/notices'], true));
$isCommentThreadPage = !$isErrorPage && ($threadMode ?? false) === true;
$isSocialFormsPage = !$isErrorPage && ($currentPath === '/messages/blocked'
    || $currentPath === '/notifications/comments'
    || str_starts_with((string) $currentPath, '/reports/'));
$isCommunityPage = !$isErrorPage && ($isCommentThreadPage || $isSocialFormsPage || $isModerationPage
    || $currentPath === '/messages' || str_starts_with((string) $currentPath, '/messages/'));
$isAnimeDetailsPage = !$isErrorPage && !$isCommentThreadPage
    && preg_match('~^/anime/[1-9][0-9]*(?:/|$)~', (string) $currentPath) === 1;
$isDiscoveryPage = !$isErrorPage && (in_array($currentPath, ['/dashboard', '/anime', '/watchlist', '/watchlist/remove'], true)
    || $isAnimeDetailsPage);
$isLibraryPage = !$isErrorPage && (str_starts_with((string) $currentPath, '/profile/collections')
    || $isImportPage || in_array($currentPath, ['/anime/ranking', '/ranking'], true));
$isRedesignPage = $isWelcome || $isAuthPage
    || $isDiscoveryPage || $isLibraryPage || $isErrorPage || $isCommunityPage || $isAiPage || $isProjectsPage
    || in_array($currentPath, ['/profile', '/profile/avatar'], true)
    || str_starts_with((string) $currentPath, '/users/');
$isCollectionsPage = str_starts_with((string) $currentPath, '/profile/collections');
$isUserProfilePage = str_starts_with((string) $currentPath, '/users/');
$isSettingsPage = $currentPath === '/settings' || str_starts_with((string) $currentPath, '/settings/');
$isRedesignPage = $isRedesignPage || $isSettingsPage;
?>

<!doctype html>
<html lang="<?= $escape($locale) ?>"<?php if ($accountId !== null): ?> data-account-id="<?= $escape($accountId) ?>" data-account-theme="<?= $escape($accountTheme) ?>" data-font-size="<?= $escape($accountPreferences['font_size'] ?? 'standard') ?>" data-line-height="<?= $escape($accountPreferences['line_height'] ?? 'standard') ?>" data-density="<?= $escape($accountPreferences['interface_density'] ?? 'comfortable') ?>" data-high-contrast="<?= $escape($accountPreferences['high_contrast'] ?? '0') ?>" data-reduced-motion="<?= $escape($accountPreferences['reduced_motion'] ?? '0') ?>" data-strong-focus="<?= $escape($accountPreferences['strong_focus'] ?? '0') ?>" data-large-controls="<?= $escape($accountPreferences['large_controls'] ?? '0') ?>" data-message-text-size="<?= $escape($accountPreferences['message_text_size'] ?? 'standard') ?>" data-comment-text-size="<?= $escape($accountPreferences['comment_text_size'] ?? 'standard') ?>"<?php endif; ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $escape($csrfToken) ?>">

    <title><?= $escape($pageTitle) ?></title>

    <script type="application/json" id="anivibe-translations"><?= json_encode(
        ['locale' => $locale, 'messages' => $translator->javascriptMessages()],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    ) ?></script>
    <script src="<?= $assetUrl('/js/i18n.js') ?>" defer></script>

    <script>
        (function () {
            const storageKey = 'theme';
            let savedTheme = null;
            let systemPrefersDark = false;

            try {
                savedTheme = localStorage.getItem(storageKey);
            } catch (error) {
                // Brak dostępu do pamięci nie blokuje wyświetlania strony.
            }

            try {
                systemPrefersDark = window.matchMedia(
                    '(prefers-color-scheme: dark)'
                ).matches;
            } catch (error) {
                // Bez preferencji przeglądarki używamy jasnego motywu.
            }

            const hasSavedTheme = savedTheme === 'light' || savedTheme === 'dark';
            const accountTheme = document.documentElement.dataset.accountTheme;
            const isAccount = Boolean(document.documentElement.dataset.accountId);

            document.documentElement.dataset.theme = isAccount
                ? (accountTheme === 'light' || accountTheme === 'dark'
                    ? accountTheme : (systemPrefersDark ? 'dark' : 'light'))
                : (hasSavedTheme ? savedTheme : (systemPrefersDark ? 'dark' : 'light'));
        })();
    </script>

    <link rel="stylesheet" href="<?= $assetUrl('/css/style.css') ?>">
    <link rel="stylesheet" href="<?= $assetUrl('/css/navigation.css') ?>">
    <script src="<?= $assetUrl('/js/navigation.js') ?>" defer></script>

    <?php if ($isWelcome): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/welcome.css') ?>">
    <?php endif; ?>

    <?php if ($isAuthPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/login.css') ?>">
    <?php endif; ?>

    <?php if (!$isErrorPage && ($isUserProfilePage || $isImportPage || $isCollectionsPage || str_starts_with((string) $currentPath, '/anime/') || in_array($currentPath, ['/anime', '/dashboard', '/profile', '/profile/avatar', '/watchlist', '/watchlist/remove', '/chat'], true))): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/catalog.css') ?>">
        <script src="<?= $assetUrl('/js/catalog.js') ?>" defer></script>
    <?php endif; ?>

    <?php if ($currentPath === '/dashboard'): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/dashboard.css') ?>">
    <?php endif; ?>

    <script src="<?= $assetUrl('/js/theme.js') ?>" defer></script>
    <?php if ($currentPath === '/anime/ranking'): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/anime-ranking.css') ?>">
    <?php endif; ?>
    <?php if ($isLoggedIn && !($restrictedAccount ?? false)): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/community.css') ?>">
        <script src="<?= $assetUrl('/js/notifications.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($isUserProfilePage || $isImportPage || $isCollectionsPage || in_array($currentPath, ['/profile', '/profile/avatar', '/watchlist', '/watchlist/remove'], true)): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/profile.css') ?>">
    <?php endif; ?>
    <?php if ($isCollectionsPage || str_starts_with((string) $currentPath, '/anime/')): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/collections.css') ?>">
    <?php endif; ?>
    <?php if (str_starts_with((string) $currentPath, '/anime/')): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/comments.css') ?>">
    <?php endif; ?>
    <?php if ($isUserProfilePage || in_array($currentPath, ['/ranking', '/profile', '/profile/avatar', '/watchlist', '/watchlist/remove'], true)): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/experience.css') ?>">
    <?php endif; ?>
    <?php if ($isCommunityPage || $isAiPage || $isProjectsPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/social.css') ?>">
    <?php endif; ?>
    <?php if ($isSocialFormsPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/community-pages.css') ?>">
    <?php endif; ?>
    <?php if ($isCommentThreadPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/discussion.css') ?>">
    <?php endif; ?>
    <?php if ($isCommunityPage && !$isSocialFormsPage && !$isCommentThreadPage && !$isModerationPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/messages.css') ?>">
    <?php endif; ?>
    <?php if ($isDiscoveryPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/discovery.css') ?>">
    <?php endif; ?>
    <?php if ($currentPath === '/watchlist' || $currentPath === '/watchlist/remove'): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/watchlist.css') ?>">
    <?php endif; ?>
    <?php if ($isAnimeDetailsPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/anime-details.css') ?>">
    <?php endif; ?>
    <?php if ($isLibraryPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/library.css') ?>">
    <?php endif; ?>
    <?php if ($isImportPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/import.css') ?>">
    <?php endif; ?>
    <?php if ($isAiPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/ai.css') ?>">
    <?php endif; ?>
    <?php if ($isProjectsPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/projects.css') ?>">
    <?php endif; ?>
    <?php if ($isModerationPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/moderation.css') ?>">
    <?php endif; ?>
    <?php if ($isCollectionsPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/collections-library.css') ?>">
    <?php endif; ?>
    <?php if ($currentPath === '/ranking'): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/ranking.css') ?>">
    <?php endif; ?>
    <?php if ($isErrorPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/error.css') ?>">
    <?php endif; ?>
    <?php if ($isSettingsPage): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/settings.css') ?>">
        <script src="<?= $assetUrl('/js/settings.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($isLoggedIn): ?>
        <link rel="stylesheet" href="<?= $assetUrl('/css/preferences.css') ?>">
    <?php endif; ?>
</head>
<body class="<?= $isWelcome ? 'page-welcome' : 'page-standard' ?><?= $isWorkspace ? ' page-workspace' : ' page-public' ?><?= $isRedesignPage ? ' page-redesign' : '' ?><?= $isAuthPage ? ' page-auth' : '' ?><?= $isDiscoveryPage ? ' page-discovery' : '' ?><?= $isLibraryPage ? ' page-library' : '' ?><?= $isErrorPage ? ' page-error' : '' ?><?= $isCommunityPage ? ' page-community' : '' ?><?= $isAiPage ? ' page-ai' : '' ?><?= $isProjectsPage ? ' page-projects' : '' ?><?= $isImportPage ? ' page-import' : '' ?><?= $isModerationPage ? ' page-moderation' : '' ?>">
    <a href="#main-content" class="skip-link">
        <?= $escape($t('header.skip')) ?>
    </a>

    <header class="site-header">
        <a href="/" class="site-brand" aria-label="<?= $escape($t('header.home')) ?>">
            <span class="site-brand-icon" aria-hidden="true">🌸</span>
            <span>AniVibe</span>
        </a>

        <?php require __DIR__ . '/navigation.html.php'; ?>

        <?php if (!($restrictedAccount ?? false)): ?>
        <div class="theme-controls" role="group" aria-label="<?= $escape($t('header.theme')) ?>">
            <button
                class="theme-toggle"
                type="button"
                aria-label="<?= $escape($t('header.dark')) ?>"
                aria-pressed="false"
                title="<?= $escape($t('header.dark')) ?>"
                disabled
            >
                <svg
                    class="theme-icon-moon"
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path d="M21 12.8A9 9 0 0 1 11.2 3 9 9 0 1 0 21 12.8Z" />
                </svg>
                <svg
                    class="theme-icon-sun"
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    aria-hidden="true"
                    focusable="false"
                >
                    <circle cx="12" cy="12" r="4" />
                    <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" />
                </svg>
            </button>
            <button class="theme-system" type="button" aria-label="<?= $escape($t('header.system')) ?>" disabled>
                <?= $escape($t('header.auto')) ?>
            </button>
            <p class="theme-save-status" role="status" hidden></p>
        </div>
        <?php endif; ?>
    </header>

    <main id="main-content" class="page-content" tabindex="-1">
        <?php if (($moderationNotices ?? []) !== [] && $currentPath !== '/account/notices'): ?>
            <aside class="moderation-notice-banner" aria-label="<?= $escape($t('moderation.notices')) ?>" role="status">
                <?php foreach ($moderationNotices as $moderationNotice): ?>
                    <p><?= $escape(\App\Service\ModerationNoticeFormatter::message($moderationNotice)) ?></p>
                <?php endforeach; ?>
                <a href="/account/notices"><?= $escape($t('moderation.view_notices')) ?></a>
            </aside>
        <?php endif; ?>
