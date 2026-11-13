<?php

declare(strict_types=1);

use App\Database;
use App\Repository\PreferencesRepository;
use App\Repository\UserRepository;
use App\Service\Translator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$database = (new Database())->connect();
$database->beginTransaction();
try {
    $database->exec('CREATE TEMP TABLE users (LIKE public.users INCLUDING ALL) ON COMMIT DROP');
    $database->exec('CREATE TEMP SEQUENCE language_test_user_ids');
    $database->exec("ALTER TABLE pg_temp.users ALTER COLUMN id SET DEFAULT nextval('pg_temp.language_test_user_ids')");
    $users = new UserRepository($database);
    $preferences = new PreferencesRepository($database);
    $hash = password_hash('Language-test-2026!', PASSWORD_DEFAULT);
    $first = $users->create('Ustawienia', 'language-first@example.test', $hash, 'de');
    $second = $users->create('Other account', 'language-second@example.test', $hash);
    $assert = static function (bool $condition): void {
        if (!$condition) {
            throw new RuntimeException('Language preference assertion failed.');
        }
    };
    $assert($users->findByEmail('language-first@example.test')['language'] === 'de');
    $assert($users->findById($first)['language'] === 'de');
    $assert(!array_key_exists('language', $users->findPublicById($first)));
    foreach (Translator::LANGUAGES as $locale => $name) {
        $preferences->saveLanguage($first, $locale);
        $assert($preferences->forUser($first)['language'] === $locale);
        $assert($preferences->forUser($second)['language'] === 'pl');
        $assert($preferences->forUser($first)['theme'] === 'system');
    }
    try {
        $preferences->saveLanguage($first, 'xx');
        throw new RuntimeException('Invalid language accepted');
    } catch (InvalidArgumentException) {
        $assert($preferences->forUser($first)['language'] === 'ru');
    }
    echo "[OK] Language persistence, account isolation, validation and public profile privacy\n";
} finally {
    $database->rollBack();
}
