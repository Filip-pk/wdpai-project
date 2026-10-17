<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\AnimeController;
use App\Controller\AuthController;
use App\Controller\ChatController;
use App\Controller\DashboardController;
use App\Controller\DefaultController;
use App\Controller\ProfileController;
use App\Controller\ProjectController;
use App\Routing;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$routing = new Routing();

$routing->get('/', DefaultController::class, 'index');
$routing->get('/login', AuthController::class, 'login');
$routing->get('/dashboard', DashboardController::class, 'index');

$routing->get('/anime', AnimeController::class, 'index');
$routing->get('/anime/{id}', AnimeController::class, 'show');

$routing->get('/chat', ChatController::class, 'index');
$routing->get('/chat/{id}', ChatController::class, 'show');

$routing->get('/profile', ProfileController::class, 'index');
$routing->get('/watchlist', ProfileController::class, 'watchlist');

$routing->get('/admin', AdminController::class, 'index');

// Dotychczasowe trasy demonstracyjne z zadania 2.
$routing->get('/projects', ProjectController::class, 'index');
$routing->get('/projects/{id}', ProjectController::class, 'show');

$routeFound = $routing->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
);

if (!$routeFound) {
    http_response_code(404);
    (new DefaultController())->notFound();
}