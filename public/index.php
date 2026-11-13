<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\AnimeController;
use App\Controller\AnimeRankingController;
use App\Controller\AppController;
use App\Controller\AuthController;
use App\Controller\ChatController;
use App\Controller\CollectionController;
use App\Controller\CommentReactionController;
use App\Controller\CommunityController;
use App\Controller\DashboardController;
use App\Controller\DefaultController;
use App\Controller\MalImportController;
use App\Controller\LanguageController;
use App\Controller\MessageController;
use App\Controller\ModerationController;
use App\Controller\ProfileController;
use App\Controller\ProjectController;
use App\Controller\RankingController;
use App\Controller\ReplyNotificationController;
use App\Controller\SettingsController;
use App\Controller\SecuritySettingsController;
use App\Controller\PrivacySettingsController;
use App\Database;
use App\Repository\AnimeRepository;
use App\Repository\AnimeRankingRepository;
use App\Repository\CollectionRepository;
use App\Repository\CommentRepository;
use App\Repository\CommentReactionRepository;
use App\Repository\CommunityRepository;
use App\Repository\ExperienceRepository;
use App\Repository\MalImportRepository;
use App\Repository\MessageRepository;
use App\Repository\ModerationRepository;
use App\Repository\ProjectRepository;
use App\Repository\RatingRepository;
use App\Repository\RecommendationRepository;
use App\Repository\ReplyNotificationRepository;
use App\Repository\UserRepository;
use App\Repository\PreferencesRepository;
use App\Repository\SecuritySettingsRepository;
use App\Repository\PrivacySettingsRepository;
use App\Repository\WatchlistRepository;
use App\Routing;
use App\Service\AccountAccessGuard;
use App\Service\MailService;
use App\Service\MalImportService;
use App\Service\RecommendationService;
use App\Service\Translator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Cache-Control: no-store');
Translator::setCurrent(new Translator(
    is_string($_COOKIE['anivibe_language'] ?? null) ? $_COOKIE['anivibe_language'] : 'pl',
));

$connection = null;
$connect = static function () use (&$connection): PDO {
    return $connection ??= (new Database())->connect();
};

$createController = static function (string $controllerClass) use ($connect): AppController {
    if ($controllerClass === ModerationController::class) {
        return new ModerationController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): ModerationRepository => new ModerationRepository($connect()),
        );
    }
    if ($controllerClass === LanguageController::class) {
        return new LanguageController(
            static fn (): PreferencesRepository => new PreferencesRepository($connect()),
        );
    }
    if ($controllerClass === PrivacySettingsController::class) {
        return new PrivacySettingsController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): PrivacySettingsRepository => new PrivacySettingsRepository($connect()),
        );
    }
    if ($controllerClass === SecuritySettingsController::class) {
        $mailConfiguration = (require dirname(__DIR__) . '/config.php')['mail'];
        return new SecuritySettingsController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): SecuritySettingsRepository => new SecuritySettingsRepository($connect()),
            new MailService(...$mailConfiguration),
        );
    }
    if ($controllerClass === SettingsController::class) {
        return new SettingsController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): PreferencesRepository => new PreferencesRepository($connect()),
            static fn (): PrivacySettingsRepository => new PrivacySettingsRepository($connect()),
        );
    }
    if ($controllerClass === AnimeRankingController::class) {
        return new AnimeRankingController(
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): AnimeRankingRepository => new AnimeRankingRepository($connect()),
        );
    }

    if ($controllerClass === AnimeController::class) {

        return new AnimeController(
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): CollectionRepository => new CollectionRepository($connect()),
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): RatingRepository => new RatingRepository($connect()),
            static fn (): CommentRepository => new CommentRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
            static fn (): ReplyNotificationRepository => new ReplyNotificationRepository($connect()),
            static fn (): CommentReactionRepository => new CommentReactionRepository($connect()),
        );
    }

    if ($controllerClass === CollectionController::class) {

        return new CollectionController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): CollectionRepository => new CollectionRepository($connect()),
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): WatchlistRepository => new WatchlistRepository($connect()),
            static fn (): RatingRepository => new RatingRepository($connect()),
        );
    }

    if ($controllerClass === MessageController::class) {

        return new MessageController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): MessageRepository => new MessageRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
        );
    }

    if ($controllerClass === CommunityController::class) {
        return new CommunityController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
            static fn (): MessageRepository => new MessageRepository($connect()),
        );
    }

    if ($controllerClass === CommentReactionController::class) {
        return new CommentReactionController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): CommentRepository => new CommentRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
            static fn (): CommentReactionRepository => new CommentReactionRepository($connect()),
        );
    }

    if ($controllerClass === ReplyNotificationController::class) {
        return new ReplyNotificationController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): ReplyNotificationRepository => new ReplyNotificationRepository($connect()),
        );
    }

    if ($controllerClass === ProfileController::class) {

        return new ProfileController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): WatchlistRepository => new WatchlistRepository($connect()),
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): RatingRepository => new RatingRepository($connect()),
            static fn (): ExperienceRepository => new ExperienceRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
            static fn (): PrivacySettingsRepository => new PrivacySettingsRepository($connect()),
        );
    }

    if ($controllerClass === MalImportController::class) {

        return new MalImportController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): MalImportService => new MalImportService(new MalImportRepository($connect())),
        );
    }

    if ($controllerClass === RankingController::class) {

        return new RankingController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): ExperienceRepository => new ExperienceRepository($connect()),
        );
    }

    if ($controllerClass === DashboardController::class) {
        return new DashboardController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): RatingRepository => new RatingRepository($connect()),
            static fn (): RecommendationService => new RecommendationService(new RecommendationRepository($connect())),
        );
    }

    if ($controllerClass === AuthController::class) {
        $mailConfiguration = (require dirname(__DIR__) . '/config.php')['mail'];

        return new AuthController(
            static fn (): UserRepository => new UserRepository($connect()),
            new MailService(...$mailConfiguration),
        );
    }

    if ($controllerClass === ProjectController::class) {
        return new ProjectController(
            static fn (): ProjectRepository => new ProjectRepository($connect()),
        );
    }

    return new $controllerClass();
};

$routing = new Routing(static function (string $controllerClass) use ($createController, $connect): AppController {
    $controller = $createController($controllerClass);
    $controller->setModerationRepository(
        static fn (): ModerationRepository => new ModerationRepository($connect()),
    );
    $controller->setCommunityRepository(
        static fn (): CommunityRepository => new CommunityRepository($connect()),
    );
    $controller->setPreferencesRepository(
        static fn (): PreferencesRepository => new PreferencesRepository($connect()),
    );
    try {
        $controller->initializeLanguage($controllerClass !== AuthController::class);
    } catch (RuntimeException $exception) {
        if (!$controller instanceof DefaultController
            || (!$exception instanceof PDOException && !$exception->getPrevious() instanceof PDOException)) {
            throw $exception;
        }
        // The public home page can use the session language while the database is unavailable.
        $controller = new DefaultController();
        $controller->initializeLanguage(false);
    }
    return $controller;
});

$routing->get('/', DefaultController::class, 'index');
$routing->post('/language', LanguageController::class, 'save');
$routing->get('/login', AuthController::class, 'login');
$routing->post('/login', AuthController::class, 'login');
$routing->get('/register', AuthController::class, 'register');
$routing->post('/register', AuthController::class, 'register');
$routing->post('/logout', AuthController::class, 'logout');
$routing->get('/dashboard', DashboardController::class, 'index');
$routing->get('/ranking', RankingController::class, 'index');
$routing->get('/users/{id}', ProfileController::class, 'show');

$routing->get('/anime', AnimeController::class, 'index');
$routing->get('/anime/ranking', AnimeRankingController::class, 'index');
$routing->get('/anime/{id}', AnimeController::class, 'show');
$routing->post('/anime/{id}/comments', AnimeController::class, 'createComment');
$routing->get('/anime/{id}/comments/{commentId}', AnimeController::class, 'showCommentThread');
$routing->post('/anime/{id}/comments/{commentId}/replies', AnimeController::class, 'replyComment');
$routing->post('/anime/{id}/comments/{commentId}/reaction', CommentReactionController::class, 'react');
$routing->post('/anime/{id}/comments/{commentId}/edit', AnimeController::class, 'updateComment');
$routing->post('/anime/{id}/comments/{commentId}/delete', AnimeController::class, 'deleteComment');
$routing->post('/anime/{id}/rating', AnimeController::class, 'saveRating');
$routing->post('/anime/{id}/rating/remove', AnimeController::class, 'removeRating');
$routing->post('/anime/{id}/collections', AnimeController::class, 'addToCollections');
$routing->post('/anime/{id}/collections/create', AnimeController::class, 'createCollection');

$routing->get('/chat', ChatController::class, 'index');
$routing->get('/chat/{id}', ChatController::class, 'show');

$routing->get('/messages', MessageController::class, 'index');
$routing->get('/messages/blocked', CommunityController::class, 'blocked');
$routing->get('/messages/new/{id}', MessageController::class, 'compose');
$routing->post('/messages/new/{id}', MessageController::class, 'compose');
$routing->get('/messages/{id}', MessageController::class, 'show');
$routing->post('/messages/{id}', MessageController::class, 'show');
$routing->post('/messages/{id}/read', CommunityController::class, 'read');
$routing->get('/notifications', CommunityController::class, 'notifications');
$routing->get('/notifications/comments', ReplyNotificationController::class, 'index');
$routing->post('/notifications/comments/{id}/read', ReplyNotificationController::class, 'read');
$routing->post('/notifications/comments/read-all', ReplyNotificationController::class, 'readAll');
$routing->post('/users/{id}/block', CommunityController::class, 'block');
$routing->post('/users/{id}/unblock', CommunityController::class, 'unblock');
$routing->get('/reports/comment/{id}', CommunityController::class, 'reportComment');
$routing->post('/reports/comment/{id}', CommunityController::class, 'reportComment');
$routing->get('/reports/message/{id}', CommunityController::class, 'reportMessage');
$routing->post('/reports/message/{id}', CommunityController::class, 'reportMessage');
$routing->get('/moderation', ModerationController::class, 'index');
$routing->post('/moderation/reports/{id}/resolve', ModerationController::class, 'resolve');
$routing->post('/moderation/reports/{id}/dismiss', ModerationController::class, 'dismissReport');
$routing->post('/moderation/comments/{id}/remove', ModerationController::class, 'removeComment');
$routing->post('/moderation/comments/{id}/warn', ModerationController::class, 'warnComment');
$routing->post('/moderation/users/{id}/ban', ModerationController::class, 'banUser');
$routing->post('/moderation/bans/{id}/revoke', ModerationController::class, 'revokeBan');
$routing->get('/account/banned', ModerationController::class, 'banned');
$routing->get('/account/notices', ModerationController::class, 'notices');
$routing->post('/account/notices/{id}/read', ModerationController::class, 'readNotice');

$routing->get('/profile', ProfileController::class, 'index');
$routing->get('/settings', SettingsController::class, 'index');
$routing->post('/settings', SettingsController::class, 'index');
$routing->post('/settings/accessibility', SettingsController::class, 'accessibility');
$routing->post('/settings/password', SecuritySettingsController::class, 'changePassword');
$routing->post('/settings/email', SecuritySettingsController::class, 'requestEmailChange');
$routing->get('/settings/email/confirm', SecuritySettingsController::class, 'confirmEmail');
$routing->post('/settings/email/confirm', SecuritySettingsController::class, 'confirmEmail');
$routing->post('/settings/privacy', PrivacySettingsController::class, 'save');
$routing->post('/settings/appearance/reset', SettingsController::class, 'resetAppearance');
$routing->post('/profile', ProfileController::class, 'index');
$routing->post('/profile/avatar', ProfileController::class, 'uploadAvatar');
$routing->get('/profile/import', MalImportController::class, 'index');
$routing->post('/profile/import/preview', MalImportController::class, 'preview');
$routing->post('/profile/import/confirm', MalImportController::class, 'confirm');
$routing->post('/profile/import/cancel', MalImportController::class, 'cancel');
$routing->get('/profile/collections', CollectionController::class, 'index');
$routing->post('/profile/collections', CollectionController::class, 'create');
$routing->get('/profile/collections/{id}', CollectionController::class, 'show');
$routing->post('/profile/collections/{id}', CollectionController::class, 'update');
$routing->post('/profile/collections/{id}/remove', CollectionController::class, 'removeAnime');
$routing->get('/avatars/{id}', ProfileController::class, 'avatar');
$routing->post('/profile/theme', ProfileController::class, 'theme');
$routing->get('/watchlist', ProfileController::class, 'watchlist');
$routing->post('/watchlist', ProfileController::class, 'saveWatchlist');
$routing->post('/watchlist/remove', ProfileController::class, 'removeWatchlist');

$routing->get('/admin', AdminController::class, 'index');

// Projekty zapisane w PostgreSQL.
$routing->get('/projects', ProjectController::class, 'index');
$routing->get('/projects/{id}', ProjectController::class, 'show');

try {
    if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
        $ban = AccountAccessGuard::begin(
            $connect(),
            $_SESSION['user_id'],
            ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/profile/import/confirm',
        );
        if ($ban !== null) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $allowed = ($method === 'GET' && in_array($path, ['/account/banned', '/account/notices'], true))
                || ($method === 'POST' && (in_array($path, ['/language', '/logout'], true)
                    || preg_match('~^/account/notices/[1-9][0-9]*/read$~', (string) $path) === 1));
            if (!$allowed) {
                $preferences = (new PreferencesRepository($connect()))->forUser($_SESSION['user_id']);
                Translator::setCurrent(new Translator($preferences['language'] ?? 'pl'));
                AccountAccessGuard::finish(false);
                if ($method !== 'GET' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['error' => Translator::current()->translate('moderation.banned'),
                        'redirect' => '/account/banned'], JSON_THROW_ON_ERROR);
                } else {
                    header('Location: /account/banned', true, 303);
                }
                exit;
            }
        }
    }
    $routeFound = $routing->dispatch(
        $_SERVER['REQUEST_METHOD'],
        $_SERVER['REQUEST_URI'],
    );

    if (!$routeFound) {
        http_response_code(404);
        (new DefaultController())->notFound();
    }
    AccountAccessGuard::finish(http_response_code() < 400);
} catch (Throwable $exception) {
    AccountAccessGuard::finish(false);
    error_log('Błąd obsługi żądania: ' . $exception::class);
    http_response_code(500);
    (new DefaultController())->serverError();
}
