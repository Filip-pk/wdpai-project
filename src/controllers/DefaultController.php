<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

final class DefaultController extends AppController
{
    public function index(): void
    {
        $this->render('home', [
            'pageTitle' => Translator::current()->translate('message.page.home'),
            'appName' => 'AniVibe',
            'tagline' => Translator::current()->translate('message.home.tagline'),
        ]);
    }

    public function notFound(): void
    {
        $this->render('not-found', [
            'pageTitle' => Translator::current()->translate('message.page.not_found'),
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
            'pageTitle' => Translator::current()->translate('message.page.server_error'),
            'retryUrl' => $retryUrl,
        ]);
    }
}
