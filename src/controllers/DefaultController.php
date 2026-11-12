<?php

declare(strict_types=1);

namespace App\Controller;

final class DefaultController extends AppController
{
    public function index(): void
    {
        $this->render('home', [
            'pageTitle' => 'AniVibe — odkryj swoje następne anime',
            'appName' => 'AniVibe',
            'tagline' => 'Twój Inteligentny Przewodnik po Anime',
        ]);
    }

    public function notFound(): void
    {
        $this->render('not-found', [
            'pageTitle' => 'Nie znaleziono strony — AniVibe',
        ]);
    }

    public function serverError(): void
    {
        $retryUrl = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/projects';
            $path = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : null;
            $isLocalPath = is_string($requestUri)
                && str_starts_with($requestUri, '/')
                && !str_starts_with($requestUri, '//')
                && preg_match('/[\x00-\x20\\\\]/', $requestUri) === 0;
            $retryUrl = $isLocalPath && is_string($path) && $path !== '' ? $path : '/projects';
        }

        $this->render('server-error', [
            'pageTitle' => 'Błąd serwera — AniVibe',
            'retryUrl' => $retryUrl,
        ]);
    }
}
