<?php

declare(strict_types=1);

use App\Controller\AchievementController;
use App\Controller\AdminController;
use App\Controller\AnimeClueController;
use App\Controller\AnimeController;
use App\Controller\AnimeRankingController;
use App\Controller\AuthController;
use App\Controller\ChatController;
use App\Controller\CollectionController;
use App\Controller\CommentReactionController;
use App\Controller\CommunityController;
use App\Controller\DashboardController;
use App\Controller\DefaultController;
use App\Controller\LanguageController;
use App\Controller\MalImportController;
use App\Controller\MessageController;
use App\Controller\ModerationController;
use App\Controller\PrivacySettingsController;
use App\Controller\ProfileController;
use App\Controller\ProfileShowcaseController;
use App\Controller\ProjectController;
use App\Controller\QuizController;
use App\Controller\RankingController;
use App\Controller\ReplyNotificationController;
use App\Controller\SecuritySettingsController;
use App\Controller\SettingsController;
use App\ControllerFactory;
use App\Repository\PreferencesRepository;
use App\Routing;
use App\Service\AccountAccessGuard;
use App\Service\ApplicationErrorHandler;
use App\Service\ApplicationLogger;
use App\Service\Translator;

// Never show raw errors or stack traces to visitors, even without the Docker PHP configuration.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

require_once dirname(__DIR__) . '/vendor/autoload.php';

$applicationLogger = new ApplicationLogger(dirname(__DIR__) . '/storage/logs');
ApplicationErrorHandler::register($applicationLogger);

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

// Builds each routed controller; its repositories share one lazily opened connection.
$controllers = new ControllerFactory($applicationLogger, dirname(__DIR__));
$routing = new Routing($controllers);

$routing->get('/', DefaultController::class, 'index');
$routing->post('/language', LanguageController::class, 'save');
$routing->get('/login', AuthController::class, 'login');
$routing->post('/login', AuthController::class, 'login');
$routing->get('/register', AuthController::class, 'register');
$routing->post('/register', AuthController::class, 'register');
$routing->post('/logout', AuthController::class, 'logout');
$routing->get('/dashboard', DashboardController::class, 'index');
$routing->get('/quiz', QuizController::class, 'index');
$routing->post('/quiz', QuizController::class, 'index');
$routing->get('/quiz/ranking', QuizController::class, 'ranking');
$routing->get('/achievements', AchievementController::class, 'index');
$routing->get('/guess', AnimeClueController::class, 'index');
$routing->get('/guess/suggestions', AnimeClueController::class, 'suggestions');
$routing->get('/guess/ranking', AnimeClueController::class, 'ranking');
$routing->post('/guess/reveal', AnimeClueController::class, 'reveal');
$routing->post('/guess/answer', AnimeClueController::class, 'answer');
$routing->post('/guess/skip', AnimeClueController::class, 'skip');
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
$routing->post('/chat', ChatController::class, 'submit');

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
$routing->get('/profile/showcase', ProfileShowcaseController::class, 'index');
$routing->post('/profile/showcase', ProfileShowcaseController::class, 'index');
$routing->get('/profile/showcase/anime', ProfileShowcaseController::class, 'anime');
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
$routing->post('/admin/diagnostics', AdminController::class, 'checkDiagnostics');
$routing->post('/admin/mail-test', AdminController::class, 'testMail');
$routing->post('/admin/users/{id}/moderator', AdminController::class, 'changeModerator');
$routing->post('/admin/reports/{id}/dismiss', AdminController::class, 'dismissMessageReport');
$routing->post('/admin/reports/{id}/hide', AdminController::class, 'hideReportedMessage');

// Projekty zapisane w PostgreSQL.
$routing->get('/projects', ProjectController::class, 'index');
$routing->get('/projects/{id}', ProjectController::class, 'show');

try {
    if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
        $ban = AccountAccessGuard::begin(
            $controllers->connection(),
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
                $preferences = (new PreferencesRepository($controllers->connection()))->forUser($_SESSION['user_id']);
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
        $allowedMethods = $routing->allowedMethods($_SERVER['REQUEST_URI']);
        if ($allowedMethods !== []) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowedMethods));
            (new DefaultController())->methodNotAllowed();
        } else {
            http_response_code(404);
            (new DefaultController())->notFound();
        }
    }
    AccountAccessGuard::finish(http_response_code() < 400);
} catch (Throwable $exception) {
    AccountAccessGuard::finish(false);
    $errorId = $applicationLogger->recordException($exception, 'application', $_SERVER['REQUEST_URI'] ?? '/');
    http_response_code(500);
    (new DefaultController())->serverError($errorId);
}
