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
use App\Controller\MessageController;
use App\Controller\ProfileController;
use App\Controller\ProjectController;
use App\Controller\RankingController;
use App\Controller\ReplyNotificationController;
use App\Controller\SettingsController;
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
use App\Repository\ProjectRepository;
use App\Repository\RatingRepository;
use App\Repository\RecommendationRepository;
use App\Repository\ReplyNotificationRepository;
use App\Repository\UserRepository;
use App\Repository\PreferencesRepository;
use App\Repository\WatchlistRepository;
use App\Routing;
use App\Service\MailService;
use App\Service\MalImportService;
use App\Service\RecommendationService;

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

$createController = static function (string $controllerClass): AppController {
    if ($controllerClass === SettingsController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };
        return new SettingsController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): PreferencesRepository => new PreferencesRepository($connect()),
        );
    }
    if ($controllerClass === AnimeRankingController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };
        return new AnimeRankingController(
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): AnimeRankingRepository => new AnimeRankingRepository($connect()),
        );
    }

    if ($controllerClass === AnimeController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };

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
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };

        return new CollectionController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): CollectionRepository => new CollectionRepository($connect()),
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): WatchlistRepository => new WatchlistRepository($connect()),
            static fn (): RatingRepository => new RatingRepository($connect()),
        );
    }

    if ($controllerClass === MessageController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };

        return new MessageController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): MessageRepository => new MessageRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
        );
    }

    if ($controllerClass === CommunityController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };
        return new CommunityController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
            static fn (): MessageRepository => new MessageRepository($connect()),
        );
    }

    if ($controllerClass === CommentReactionController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };
        return new CommentReactionController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): CommentRepository => new CommentRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
            static fn (): CommentReactionRepository => new CommentReactionRepository($connect()),
        );
    }

    if ($controllerClass === ReplyNotificationController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };
        return new ReplyNotificationController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): ReplyNotificationRepository => new ReplyNotificationRepository($connect()),
        );
    }

    if ($controllerClass === ProfileController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };

        return new ProfileController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): WatchlistRepository => new WatchlistRepository($connect()),
            static fn (): AnimeRepository => new AnimeRepository($connect()),
            static fn (): RatingRepository => new RatingRepository($connect()),
            static fn (): ExperienceRepository => new ExperienceRepository($connect()),
            static fn (): CommunityRepository => new CommunityRepository($connect()),
        );
    }

    if ($controllerClass === MalImportController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };

        return new MalImportController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): MalImportService => new MalImportService(new MalImportRepository($connect())),
        );
    }

    if ($controllerClass === RankingController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };

        return new RankingController(
            static fn (): UserRepository => new UserRepository($connect()),
            static fn (): ExperienceRepository => new ExperienceRepository($connect()),
        );
    }

    if ($controllerClass === DashboardController::class) {
        $connection = null;
        $connect = static function () use (&$connection): PDO {
            return $connection ??= (new Database())->connect();
        };
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
            static fn (): UserRepository => new UserRepository((new Database())->connect()),
            new MailService(...$mailConfiguration),
        );
    }

    if ($controllerClass === ProjectController::class) {
        return new ProjectController(
            static fn (): ProjectRepository => new ProjectRepository((new Database())->connect()),
        );
    }

    return new $controllerClass();
};

$routing = new Routing(static function (string $controllerClass) use ($createController): AppController {
    $controller = $createController($controllerClass);
    $controller->setCommunityRepository(
        static fn (): CommunityRepository => new CommunityRepository((new Database())->connect()),
    );
    $controller->setPreferencesRepository(
        static fn (): PreferencesRepository => new PreferencesRepository((new Database())->connect()),
    );
    return $controller;
});

$routing->get('/', DefaultController::class, 'index');
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
$routing->get('/moderation', CommunityController::class, 'moderation');
$routing->post('/moderation/reports/{id}/resolve', CommunityController::class, 'resolve');

$routing->get('/profile', ProfileController::class, 'index');
$routing->get('/settings', SettingsController::class, 'index');
$routing->post('/settings', SettingsController::class, 'index');
$routing->post('/settings/accessibility', SettingsController::class, 'accessibility');
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
    $routeFound = $routing->dispatch(
        $_SERVER['REQUEST_METHOD'],
        $_SERVER['REQUEST_URI'],
    );

    if (!$routeFound) {
        http_response_code(404);
        (new DefaultController())->notFound();
    }
} catch (Throwable $exception) {
    error_log('Błąd obsługi żądania: ' . $exception::class);
    http_response_code(500);
    (new DefaultController())->serverError();
}
