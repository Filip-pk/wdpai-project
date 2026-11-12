<?php

declare(strict_types=1);

use App\Controller\AppController;
use App\Controller\DefaultController;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Bufor pozwala zachować możliwość ustawiania statusów HTTP w kolejnych próbach CLI.
ob_start();

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$views = new class extends AppController {
    public function show(string $template, array $variables = []): void
    {
        $this->render($template, $variables);
    }
};

$render = static function (
    string $uri,
    string $method,
    int $status,
    callable $action,
    array $session = [],
    array $post = [],
): array {
    $previousServer = $_SERVER;
    $previousGet = $_GET;
    $previousPost = $_POST;
    $hadSession = array_key_exists('_SESSION', $GLOBALS);
    $previousSession = $_SESSION ?? null;
    $previousStatus = http_response_code();
    $previousBufferLevel = ob_get_level();

    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_GET = [];
    $_POST = $post;
    $_SESSION = $session + ['csrf_token' => str_repeat('a', 64)];
    http_response_code($status);
    ob_start();
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $action();

        return [
            'html' => (string) ob_get_contents(),
            'status' => http_response_code(),
        ];
    } finally {
        restore_error_handler();
        while (ob_get_level() > $previousBufferLevel) {
            ob_end_clean();
        }
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;
        if ($hadSession) {
            $_SESSION = $previousSession;
        } else {
            unset($_SESSION);
        }
        http_response_code(is_int($previousStatus) ? $previousStatus : 200);
    }
};

$mainContent = static function (array $response) use ($assert): string {
    $matched = preg_match('/<main\b[^>]*id="main-content"[^>]*>([\s\S]*?)<\/main>/', $response['html'], $matches);
    $assert($matched === 1, 'Strona powinna mieć główny obszar treści.');

    return $matches[1];
};

$assertErrorPage = static function (array $response, int $status) use ($assert, $mainContent): string {
    $assert($response['status'] === $status, 'Widok nie może zmieniać kodu HTTP.');
    $assert(preg_match('/<body\b[^>]*class="[^"]*\bpage-error\b[^"]*"/', $response['html']) === 1,
        'Ekran błędu powinien otrzymać wspólną oprawę.');
    $assert(preg_match('/href="\/css\/error\.css\?v=[a-f0-9]{12}"/', $response['html']) === 1,
        'Ekran błędu powinien ładować wersjonowany arkusz error.css.');
    $main = $mainContent($response);
    $assert(preg_match_all('/<h1\b/', $main) === 1, 'Ekran błędu powinien mieć jeden główny nagłówek.');
    $assert(preg_match('/SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i', $main) === 0,
        'Ekran nie może ujawniać szczegółów błędu serwera.');

    return $main;
};

$retryHref = static function (string $html) use ($assert): ?string {
    $count = preg_match_all('/<a\b(?=[^>]*\bdata-error-retry\b)[^>]*>/', $html, $links);
    $assert($count <= 1, 'Ekran powinien mieć co najwyżej jeden link ponowienia.');
    if ($count === 0) {
        return null;
    }
    $assert(preg_match('/\bhref="([^"]*)"/', $links[0][0], $href) === 1,
        'Link ponowienia musi mieć adres.');

    return html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        $failed++;
        echo '[BŁĄD] ' . $name . ': ' . $exception->getMessage() . PHP_EOL;
    }
};

$run('404 zachowuje status i wspólny ekran również pod adresem anime', static function () use (
    $render, $assertErrorPage, $assert,
): void {
    foreach (['/nieistniejaca-strona', '/anime/999999999'] as $uri) {
        $response = $render($uri, 'GET', 404, static fn () => (new DefaultController())->notFound());
        $main = $assertErrorPage($response, 404);
        $assert(str_contains($main, '404'), 'Widok powinien wyjaśniać, że strony nie znaleziono.');
        $assert(str_contains($main, 'href="/"'), '404 powinien umożliwiać powrót na stronę główną.');
    }
});

$run('500 dla GET ponawia lokalną ścieżkę bez parametrów i fragmentu', static function () use (
    $render, $assertErrorPage, $assert, $retryHref,
): void {
    foreach ([
        '/anime/1?secret=abc' => '/anime/1',
        '/projects/7?source=test#section' => '/projects/7',
        '/?secret=abc' => '/',
        '/profile/collections/9' => '/profile/collections/9',
    ] as $uri => $expected) {
        $response = $render($uri, 'GET', 500, static fn () => (new DefaultController())->serverError());
        $main = $assertErrorPage($response, 500);
        $assert($retryHref($main) === $expected, 'Ponowienie GET powinno używać tylko lokalnej ścieżki.');
        $assert(!str_contains($main, 'secret=abc'), 'Parametry żądania nie powinny pojawiać się w treści błędu.');
        $assert(!str_contains($main, '<form'), 'Ponowienie nie powinno odtwarzać formularza POST.');
    }
});

$run('500 odrzuca adresy zewnętrzne, network-path, backslash i białe znaki', static function () use (
    $render, $assertErrorPage, $assert, $retryHref,
): void {
    foreach ([
        '//outside.example/path',
        'https://outside.example/path',
        'javascript:alert(1)',
        '/\\outside.example/path',
        '/anime\\1',
        "/anime/1\r\nInjected: value",
        "/anime/\t1",
        '/anime/one two',
        '/anime/1?value=has space',
    ] as $uri) {
        $response = $render($uri, 'GET', 500, static fn () => (new DefaultController())->serverError());
        $main = $assertErrorPage($response, 500);
        $assert($retryHref($main) === '/projects', 'Niebezpieczny URI powinien użyć bezpiecznego adresu /projects.');
        $assert(!str_contains($main, 'href="//outside.example'), 'Błąd nie może kierować użytkownika poza aplikację.');
    }
});

$run('500 po POST nie proponuje ponowienia ani nie ujawnia przesłanych pól', static function () use (
    $render, $assertErrorPage, $assert, $retryHref,
): void {
    $response = $render('/anime/1/rating?secret=abc', 'POST', 500,
        static fn () => (new DefaultController())->serverError(),
        post: ['password' => 'error-page-post-secret', 'csrfToken' => 'not-a-real-token']);
    $main = $assertErrorPage($response, 500);
    $assert($retryHref($main) === null, 'Po POST nie powinno być linku ponowienia żądania.');
    $assert(!str_contains($main, 'data-error-retry'), 'Po POST nie powinno być kontrolki ponowienia.');
    $assert(!str_contains($main, '<form'), 'Ekran 500 nie może odtwarzać formularza POST.');
    $assert(!str_contains($response['html'], 'error-page-post-secret'), 'Przesłane dane muszą pozostać prywatne.');
    $assert(str_contains($main, 'href="/projects"'), 'Po POST pozostaje bezpieczny powrót do projektów.');
});

$run('500 działa również dla zalogowanej sesji bez zależności od bazy', static function () use (
    $render, $assertErrorPage, $assert, $retryHref,
): void {
    $response = $render('/projects/7', 'GET', 500,
        static fn () => (new DefaultController())->serverError(),
        ['user_id' => 321, 'user_name' => 'Tester błędów', 'user_theme' => 'dark']);
    $main = $assertErrorPage($response, 500);
    $assert($retryHref($main) === '/projects/7', 'Zalogowana sesja nie powinna blokować bezpiecznego ponowienia.');
});

foreach ([403, 404, 422, 429] as $status) {
    $run('Błąd operacji ' . $status . ' zachowuje komunikat i prawidłowy powrót', static function () use (
        $render, $views, $assertErrorPage, $assert, $status,
    ): void {
        $message = 'Odmowa <script>alert("test")</script> & <img src=x onerror=alert(1)>';
        $backUrl = '/anime/12/comments/34?repliesPage=2&commentsPage=3#comment-56';
        foreach ([null, $backUrl] as $target) {
            $variables = ['pageTitle' => 'Błąd operacji — test', 'message' => $message];
            if ($target !== null) {
                $variables['backUrl'] = $target;
            }
            $response = $render('/notifications/comments/read-all', 'POST', $status,
                static fn () => $views->show('community-error', $variables));
            $main = $assertErrorPage($response, $status);
            $assert(str_contains($main, htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
                'Komunikat błędu powinien zostać bezpiecznie wyświetlony.');
            $assert(!str_contains($main, '<script>alert(') && !str_contains($main, '<img src=x'),
                'Komunikat nie może wykonywać dostarczonego HTML.');
            $assert(str_contains($main, 'role="alert"'), 'Komunikat operacji powinien być oznaczony jako alert.');
            $expectedHref = htmlspecialchars($target ?? '/messages', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $assert(str_contains($main, 'href="' . $expectedHref . '"'),
                'Powrót powinien zachować query i fragment albo wskazywać wiadomości.');
        }
    });
}

$run('Status walidacji formularza nie zamienia logowania w ekran błędu', static function () use (
    $render, $views, $assert, $mainContent,
): void {
    $response = $render('/login', 'POST', 422, static fn () => $views->show('login', [
        'pageTitle' => 'Logowanie — test',
        'errors' => ['Nieprawidłowe dane logowania.'],
        'email' => 'test@example.test',
    ]));
    $assert($response['status'] === 422, 'Formularz powinien zachować status walidacji.');
    $main = $mainContent($response);
    $assert(str_contains($main, 'action="/login"') && str_contains($main, 'id="login-email"'),
        'Formularz z błędami powinien pozostać dostępny.');
    $assert(!str_contains($response['html'], '/css/error.css'), 'Zwykły formularz nie powinien ładować oprawy ekranu błędu.');
    $assert(preg_match('/<body\b[^>]*class="[^"]*\bpage-error\b/', $response['html']) === 0,
        'Flaga błędu nie może wyciekać między renderowaniami.');
});

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
ob_end_flush();
exit($failed === 0 ? 0 : 1);
