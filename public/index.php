<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\AnimeController;
use App\Controller\AppController;
use App\Controller\AuthController;
use App\Controller\ChatController;
use App\Controller\DashboardController;
use App\Controller\DefaultController;
use App\Controller\ProfileController;
use App\Controller\ProjectController;
use App\Database;
use App\Repository\AnimeRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WatchlistRepository;
use App\Routing;
use App\Service\MailService;

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

$routing = new Routing(static function (string $controllerClass): AppController {
    if ($controllerClass === AnimeController::class) {
        return new AnimeController(
            static fn (): AnimeRepository => new AnimeRepository((new Database())->connect()),
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
        );
    }

    if ($controllerClass === DashboardController::class) {
        return new DashboardController(
            static fn (): UserRepository => new UserRepository((new Database())->connect()),
            static fn (): AnimeRepository => new AnimeRepository((new Database())->connect()),
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
});

$routing->get('/', DefaultController::class, 'index');
$routing->get('/login', AuthController::class, 'login');
$routing->post('/login', AuthController::class, 'login');
$routing->get('/register', AuthController::class, 'register');
$routing->post('/register', AuthController::class, 'register');
$routing->post('/logout', AuthController::class, 'logout');
$routing->get('/dashboard', DashboardController::class, 'index');

$routing->get('/anime', AnimeController::class, 'index');
$routing->get('/anime/{id}', AnimeController::class, 'show');

$routing->get('/chat', ChatController::class, 'index');
$routing->get('/chat/{id}', ChatController::class, 'show');

$routing->get('/profile', ProfileController::class, 'index');
$routing->post('/profile', ProfileController::class, 'index');
$routing->post('/profile/avatar', ProfileController::class, 'uploadAvatar');
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
