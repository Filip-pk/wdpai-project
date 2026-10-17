<?php

declare(strict_types=1);

use App\Controller\AnimeController;
use App\Controller\DefaultController;
use App\Controller\ProjectController;
use App\Routing;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$routing = new Routing();

$routing->get('/', DefaultController::class, 'index');

$routing->get('/anime', AnimeController::class, 'index');
$routing->get('/anime/{id}', AnimeController::class, 'show');

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