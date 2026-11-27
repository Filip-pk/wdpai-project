<?php

declare(strict_types=1);

use App\Database;
use App\Service\AccountSecurityService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The seed runs twice inside one transaction that is always rolled back,
// so the test leaves the database exactly as it was.
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
// Passwords documented in README.md for the demo accounts.
$accounts = [
    'user' => ['password' => 'AniVibe-User-2026', 'moderator' => false, 'admin' => false],
    'moderator' => ['password' => 'AniVibe-Mod-2026', 'moderator' => true, 'admin' => false],
    'admin' => ['password' => 'AniVibe-Admin-2026', 'moderator' => false, 'admin' => true],
    'spammer' => ['password' => 'AniVibe-Spam-2026', 'moderator' => false, 'admin' => false],
];
$passed = 0;
$failed = 0;
$connection = null;

try {
    $seed = file_get_contents(dirname(__DIR__) . '/database/33-demo-data.sql');
    $assert(is_string($seed) && str_starts_with(trim($seed), 'BEGIN;') && str_ends_with(trim($seed), 'COMMIT;'),
        'Seed musi być jedną transakcją.');
    $body = trim(substr(trim($seed), strlen('BEGIN;'), -strlen('COMMIT;')));
    $connection = (new Database())->connect();
    $connection->beginTransaction();
    $state = static function () use ($connection): array {
        $counts = [];
        foreach ([
            'users' => 'SELECT COUNT(*) FROM users u',
            'watchlist' => 'SELECT COUNT(*) FROM user_watchlist w JOIN users u ON u.id = w.user_id',
            'experience' => 'SELECT COUNT(*) FROM user_anime_experience e JOIN users u ON u.id = e.user_id',
            'ratings' => 'SELECT COUNT(*) FROM user_anime_ratings r JOIN users u ON u.id = r.user_id',
            'collections' => 'SELECT COUNT(*) FROM anime_collections c JOIN users u ON u.id = c.user_id',
            'comments' => 'SELECT COUNT(*) FROM anime_comments c JOIN users u ON u.id = c.user_id',
            'reactions' => 'SELECT COUNT(*) FROM comment_reactions r JOIN users u ON u.id = r.user_id',
            'notifications' => 'SELECT COUNT(*) FROM comment_reply_notifications n JOIN users u ON u.id = n.user_id',
            'messages' => 'SELECT COUNT(*) FROM private_messages m JOIN users u ON u.id = m.sender_id',
            'reports' => 'SELECT COUNT(*) FROM community_reports r JOIN users u ON u.id = r.reporter_id',
        ] as $name => $sql) {
            $counts[$name] = (int) $connection->query($sql . " WHERE LOWER(u.email) LIKE '%@demo.anivibe.test'")->fetchColumn();
        }
        return $counts;
    };

    $connection->exec($body);
    $first = $state();
    $connection->exec($body);
    $assert($state() === $first, 'Ponowne uruchomienie seeda nie może tworzyć duplikatów: ' . json_encode([$first, $state()]));
    echo "[OK] Seed można uruchomić ponownie bez duplikatów\n";
    $passed++;

    $statement = $connection->prepare('SELECT password_hash, is_moderator, is_admin FROM users WHERE LOWER(email) = :email');
    foreach ($accounts as $role => $account) {
        $email = $role . '@demo.anivibe.test';
        $statement->execute(['email' => $email]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $assert($row !== false, 'Brak konta ' . $email);
        $assert(AccountSecurityService::isPasswordStrong($account['password'], $email), 'Hasło demo spełnia zasady rejestracji: ' . $role);
        $assert(AccountSecurityService::verifyPassword($account['password'], $row['password_hash']), 'Hasło z README pasuje do konta ' . $role);
        $assert(!AccountSecurityService::verifyPassword('wrong-' . $account['password'], $row['password_hash']), 'Błędne hasło jest odrzucane: ' . $role);
        $assert($row['is_moderator'] === $account['moderator'] && $row['is_admin'] === $account['admin'], 'Rola konta ' . $role);
    }
    echo "[OK] Cztery konta mają role i hasła opisane w README\n";
    $passed++;

    $assert($first['watchlist'] >= 8 && $first['experience'] === $first['watchlist'] && $first['ratings'] >= 6
        && $first['collections'] >= 1 && $first['comments'] >= 4 && $first['reactions'] >= 4
        && $first['notifications'] >= 1 && $first['messages'] >= 3, 'Dane demo obejmują główne funkcje: ' . json_encode($first));
    $reports = $connection->query("SELECT r.target_type, r.status, sender.email AS sender, reporter.email AS reporter,
            r.snapshot = COALESCE(m.body, c.body) AS same_copy
        FROM community_reports r
        JOIN users reporter ON reporter.id = r.reporter_id
        JOIN users sender ON sender.id = r.reported_user_id
        LEFT JOIN private_messages m ON r.target_type = 'message' AND m.id = r.target_id
        LEFT JOIN anime_comments c ON r.target_type = 'comment' AND c.id = r.target_id
        WHERE LOWER(reporter.email) = 'user@demo.anivibe.test'
        ORDER BY r.target_type")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($reports) === 2, 'Zgłoszenie komentarza i wiadomości.');
    foreach ($reports as $report) {
        $assert($report['sender'] === 'spammer@demo.anivibe.test' && $report['same_copy'] === true,
            'Zgłoszenia dotyczą treści spamera i zawierają jej kopię.');
    }
    $reply = $connection->query("SELECT root.parent_id IS NULL AND root.anime_id = reply.anime_id
        FROM anime_comments reply JOIN anime_comments root ON root.id = reply.parent_id
        JOIN users u ON u.id = reply.user_id WHERE LOWER(u.email) = 'moderator@demo.anivibe.test'")->fetchColumn();
    $assert($reply === true, 'Odpowiedź moderatora dotyczy komentarza głównego tego samego anime.');
    echo "[OK] Dane demo pokazują watchlistę, oceny, komentarze, rozmowy i oba rodzaje zgłoszeń\n";
    $passed++;
} catch (Throwable $exception) {
    $failed++;
    fwrite(STDERR, '[BŁĄD] ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
}

echo sprintf('Wynik: %d poprawnych, %d błędnych.', $passed, $failed) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
