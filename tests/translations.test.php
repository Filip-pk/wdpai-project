<?php

declare(strict_types=1);

use App\Service\Translator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$root = dirname(__DIR__);
$source = require $root . '/translations/pl.php';
$placeholders = static function (string $text): array {
    preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $text, $matches);
    sort($matches[1]);
    return $matches[1];
};

foreach (Translator::LANGUAGES as $locale => $name) {
    $catalogue = require $root . '/translations/' . $locale . '.php';
    $assert(array_keys($catalogue) === array_keys($source), 'Different keys: ' . $locale);
    foreach ($catalogue as $key => $text) {
        $assert(is_string($text) && trim($text) !== '', 'Empty translation: ' . $locale . ' / ' . $key);
        $assert(preg_match('//u', $text) === 1, 'Invalid UTF-8: ' . $key);
        $assert($placeholders($text) === $placeholders($source[$key]), 'Different placeholders: ' . $locale . ' / ' . $key);
    }
    $translator = new Translator($locale);
    $assert($translator->locale() === $locale, 'Wrong locale');
    $assert($translator->translate('navigation.settings') === $catalogue['navigation.settings'], 'Wrong translation');
    $assert($translator->translateText('Ustawienia') === $catalogue['navigation.settings'], 'Source label lookup');
    $assert($translator->translate('navigation.profile_name', ['user' => '<Ada> {count}'])
        === str_replace('{user}', '<Ada> {count}', $catalogue['navigation.profile_name']), 'Parameters must be substituted once');
    $assert($translator->translateText('User message: Ustawienia') === 'User message: Ustawienia', 'Unknown text must remain intact');
    $assert($translator->translate('missing.translation') === 'missing.translation', 'Missing key fallback');
    echo '[OK] ' . $locale . ': ' . count($catalogue) . " keys and placeholders\n";
}
$assert((new Translator('../../config'))->locale() === 'pl', 'Invalid locale fallback');
$assert(!Translator::isSupported(['en']) && !Translator::isSupported('EN'), 'Strict locale validation');
Translator::setCurrent(new Translator('en'));
$assert(Translator::message('Ustawienia') === 'Settings', 'Current translator');
Translator::setCurrent(new Translator('pl'));

$previousSession = $_SESSION ?? [];
$previousCookies = $_COOKIE ?? [];
$_SESSION = ['user_id' => 1, 'user_language' => 'ru'];
$_COOKIE = ['anivibe_language' => 'en'];
$controller = new class extends \App\Controller\AppController {};
$controller->setPreferencesRepository(static function (): never {
    throw new RuntimeException('A session-only language must not open the database.');
});
$controller->initializeLanguage(false);
$assert(Translator::current()->locale() === 'ru', 'Session language must take precedence when loading the account is disabled.');
$_SESSION = $previousSession;
$_COOKIE = $previousCookies;
Translator::setCurrent(new Translator('pl'));

$files = [$root . '/public/index.php'];
foreach (['src', 'public/views', 'translations'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($files as $file) {
    $contents = file_get_contents($file);
    token_get_all($contents, TOKEN_PARSE);
    // Concatenated expressions are dynamic keys; check their complete families below.
    preg_match_all('/(?:\$t|->translate)\(\s*[\'"]([^\'"]+)[\'"](?!\s*\.)/', $contents, $matches);
    foreach ($matches[1] as $key) {
        $assert(array_key_exists($key, $source), 'Unknown translation key: ' . $key . ' in ' . $file);
    }
}
foreach (glob($root . '/public/js/*.js') as $file) {
    preg_match_all('/[\'"](js\.[a-zA-Z0-9_.]+)[\'"]/', file_get_contents($file), $matches);
    foreach ($matches[1] as $key) {
        $assert(array_key_exists($key, $source), 'Unknown JavaScript translation key: ' . $key);
    }
}
echo '[OK] PHP syntax and literal translation keys: ' . count($files) . " files\n";

foreach (Translator::LANGUAGES as $locale => $name) {
    $translator = new Translator($locale);
    Translator::setCurrent($translator);
    foreach (['comment_removed', 'warning', 'ban', 'ban_revoked', 'report_dismissed'] as $action) {
        $key = 'moderation.decision.' . $action;
        $assert($translator->translate($key) !== $key, 'Missing decision label: ' . $locale . ' / ' . $action);
    }
    foreach (['reports', 'comments', 'users', 'history', 'all', 'open', 'dismissed', 'removed', 'active', 'deleted'] as $label) {
        $key = 'moderation.' . $label;
        $assert($translator->translate($key) !== $key, 'Missing dynamic moderation label: ' . $locale . ' / ' . $label);
    }
    foreach (['comment_removed', 'warning', 'ban', 'ban_revoked'] as $action) {
        $message = \App\Service\ModerationNoticeFormatter::message([
            'actionType' => $action, 'reason' => '<script>reason</script>',
            'animeTitle' => 'Naruto <anime>', 'endsAt' => '2026-11-15 10:00:00+00',
        ]);
        $assert(str_contains($message, '<script>reason</script>'), 'Reason must be retained for escaping by the view.');
        $assert(!str_contains($message, 'moderation.') && preg_match('/\{(?:anime|reason|ends)\}/', $message) !== 1,
            'Untranslated moderation notice: ' . $locale . ' / ' . $action);
    }
}
Translator::setCurrent(new Translator('pl'));
echo "[OK] Moderation decision labels and notice templates in all six languages\n";

// Reports stored before reasonParts was introduced must also follow the selected language.
$previousSession = $_SESSION ?? [];
$previousServer = $_SERVER;
$views = new class extends \App\Controller\AppController {
    public function show(array $variables): void
    {
        $this->render('mal-import', $variables);
    }
};
$legacyRow = [
    'malId' => 1, 'animeId' => null, 'title' => 'Ustawienia <anime>', 'status' => 'planned',
    'watched' => 0, 'rating' => null, 'action' => 'add', 'currentStatus' => null,
    'currentWatched' => null, 'currentRating' => null,
    'reason' => 'Tytuł zostanie dodany do Twojej watchlisty. Zachowano wcześniejszą własną ocenę. Zachowano wcześniej zdobyty EXP.',
];
$viewData = [
    'pageTitle' => 'MAL', 'errors' => [], 'notice' => null, 'preview' => null, 'previewRows' => [],
    'report' => ['added' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0,
        'expBefore' => 10, 'expAfter' => 10, 'ratingsSaved' => 0, 'ratingsRemoved' => 0],
    'reportRows' => [$legacyRow], 'pagination' => ['page' => 1, 'totalPages' => 1],
    'importToken' => '', 'expiresInMinutes' => 0, 'updateExisting' => false,
];
foreach (['en', 'de', 'fr', 'es', 'ru'] as $locale) {
    $_SESSION = ['user_id' => 1, 'user_language' => $locale];
    $_SERVER['REQUEST_URI'] = '/profile/import';
    $translator = new Translator($locale);
    $parts = ['Tytuł został dodany do Twojej watchlisty.', 'Zachowano wcześniejszą własną ocenę.', 'Zachowano wcześniej zdobyty EXP.'];
    $expected = implode(' ', array_map($translator->translateText(...), $parts));
    $assert($expected !== implode(' ', $parts), 'Report sentences must have translations: ' . $locale);
    ob_start();
    try {
        $views->show($viewData);
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $assert(str_contains($html, htmlspecialchars($expected, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'Legacy MAL report must translate each sentence: ' . $locale);
    $assert(str_contains($html, 'Ustawienia &lt;anime&gt;'), 'Anime title must remain original and escaped.');
}
$_SESSION = $previousSession;
$_SERVER = $previousServer;
Translator::setCurrent(new Translator('pl'));
echo "[OK] Legacy MAL reports follow all five translated locales and preserve anime titles\n";
