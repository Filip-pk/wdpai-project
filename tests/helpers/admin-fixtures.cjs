const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

function php(source, input = {}) {
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r',
        "declare(strict_types=1); require 'vendor/autoload.php'; " + source, JSON.stringify(input)], {
        cwd: path.resolve(__dirname, '../..'), windowsHide: true, encoding: 'utf8', timeout: 30000,
    }));
}

function createAdminFixture() {
    const password = 'Admin-Diagnostics-Test-2026!';
    const fixture = php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $result = ['marker' => $input['marker']];
    $users = new App\Repository\UserRepository($db);
    foreach (['admin', 'moderator', 'member', 'target'] as $role) {
        $email = 'admin-fixture-' . $role . '-' . $input['marker'] . '@example.test';
        $id = $users->create('Admin QA ' . $role . ' <svg onload=alert(1)>', $email,
            password_hash($input['password'], PASSWORD_DEFAULT));
        $statement = $db->prepare('UPDATE users SET is_admin = :admin, is_moderator = :moderator WHERE id = :id');
        $statement->bindValue('admin', $role === 'admin', PDO::PARAM_BOOL);
        $statement->bindValue('moderator', $role === 'moderator', PDO::PARAM_BOOL);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $result[$role] = ['id' => $id, 'email' => $email];
    }
    $title = 'Admin QA ' . $input['marker'] . ' <img src=x onerror=alert(1)>';
    $anime = $db->prepare("INSERT INTO anime (title, description, episodes, score, studio, release_year, cover_url)
        VALUES (:title, '', 1, 0, '', 2026, NULL) RETURNING id");
    $anime->execute(['title' => $title]);
    $result['anime'] = ['id' => (int) $anime->fetchColumn(), 'title' => $title];
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: randomUUID(), password });
    return { ...fixture, password };
}

function setFixtureAdmin(fixture, enabled) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$expected = 'admin-fixture-admin-' . $input['marker'] . '@example.test';
if ($input['user']['email'] !== $expected) throw new RuntimeException('Invalid fixture ownership.');
$db = (new App\Database())->connect();
$statement = $db->prepare('UPDATE users SET is_admin = :enabled WHERE id = :id AND email = :email');
$statement->bindValue('enabled', $input['enabled'], PDO::PARAM_BOOL);
$statement->bindValue('id', $input['user']['id'], PDO::PARAM_INT);
$statement->bindValue('email', $expected);
$statement->execute();
echo json_encode(['updated' => $statement->rowCount()], JSON_THROW_ON_ERROR);
`, { marker: fixture.marker, user: fixture.admin, enabled });
}

// Two received messages reported by the target account: one from the member and one from the
// administrator. The second stands for a report made before the role was granted, because the
// report form refuses messages from administrators. Removing the fixture users removes both.
function createMessageReports(fixture) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email');
foreach (['admin', 'member', 'target'] as $role) {
    if ($input[$role]['email'] !== 'admin-fixture-' . $role . '-' . $input['marker'] . '@example.test') {
        throw new RuntimeException('Invalid fixture account.');
    }
    $verify->execute($input[$role]);
    if ($verify->fetchColumn() === false) throw new RuntimeException('Fixture ownership changed.');
}
$db->beginTransaction();
try {
    $conversation = $db->prepare('INSERT INTO private_conversations (first_user_id, second_user_id) VALUES (:first, :second) RETURNING id');
    $message = $db->prepare('INSERT INTO private_messages (conversation_id, sender_id, body) VALUES (:conversation, :sender, :body) RETURNING id');
    $report = $db->prepare("INSERT INTO community_reports (reporter_id, reported_user_id, target_type, target_id, snapshot, reason)
        VALUES (:reporter, :reported, 'message', :target, :snapshot, :reason) RETURNING id");
    $result = [];
    foreach (['member', 'admin'] as $sender) {
        $ids = [$input[$sender]['id'], $input['target']['id']];
        sort($ids);
        $conversation->execute(['first' => $ids[0], 'second' => $ids[1]]);
        $conversationId = (int) $conversation->fetchColumn();
        $body = 'Admin QA message from ' . $sender . ' ' . $input['marker'] . ' <svg onload=alert(1)>';
        $message->execute(['conversation' => $conversationId, 'sender' => $input[$sender]['id'], 'body' => $body]);
        $messageId = (int) $message->fetchColumn();
        $report->execute(['reporter' => $input['target']['id'], 'reported' => $input[$sender]['id'], 'target' => $messageId,
            'snapshot' => $body, 'reason' => 'Admin QA report ' . $sender]);
        $result[$sender] = ['reportId' => (int) $report->fetchColumn(), 'messageId' => $messageId,
            'conversationId' => $conversationId, 'body' => $body];
    }
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, fixture);
}

function messageReportState(reports) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$report = $db->prepare("SELECT status FROM community_reports WHERE id = :id AND target_type = 'message'");
$message = $db->prepare('SELECT moderated_at IS NOT NULL FROM private_messages WHERE id = :id');
$result = [];
foreach ($input as $sender => $ids) {
    $report->execute(['id' => $ids['reportId']]);
    $message->execute(['id' => $ids['messageId']]);
    $result[$sender] = ['status' => $report->fetchColumn(), 'hidden' => in_array($message->fetchColumn(), [true, 1, '1', 't'], true)];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
`, reports);
}

function fixtureState(fixture) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$statement = $db->prepare('SELECT is_moderator FROM users WHERE id = :id AND email = :email');
$statement->execute($input['target']);
$moderator = $statement->fetchColumn();
$audit = $db->prepare('SELECT action, details FROM admin_actions WHERE actor_id = :id ORDER BY id');
$audit->execute(['id' => $input['admin']['id']]);
echo json_encode(['isModerator' => in_array($moderator, [true, 1, '1', 't'], true),
    'actions' => $audit->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
`, fixture);
}

function cleanupAdminFixture(fixture) {
    const result = php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$marker = $input['marker'];
if (preg_match('/\A[a-f0-9-]{36}\z/', $marker) !== 1) throw new RuntimeException('Invalid fixture marker.');
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email FOR UPDATE');
    foreach (['admin', 'moderator', 'member', 'target'] as $role) {
        $user = $input[$role];
        if ($user['email'] !== 'admin-fixture-' . $role . '-' . $marker . '@example.test') {
            throw new RuntimeException('Invalid fixture account.');
        }
        $verify->execute($user);
        if ($verify->fetchColumn() === false) throw new RuntimeException('Fixture account ownership changed.');
    }
    $audit = $db->prepare('DELETE FROM admin_actions WHERE actor_id = :id OR target_id = :target');
    $remove = $db->prepare('DELETE FROM users WHERE id = :id AND email = :email');
    $removed = 0;
    foreach (['admin', 'moderator', 'member', 'target'] as $role) {
        $audit->execute(['id' => $input[$role]['id'], 'target' => $input[$role]['id']]);
        $remove->execute($input[$role]);
        $removed += $remove->rowCount();
    }
    if ($input['anime']['title'] !== 'Admin QA ' . $marker . ' <img src=x onerror=alert(1)>') {
        throw new RuntimeException('Invalid fixture anime.');
    }
    $anime = $db->prepare('DELETE FROM anime WHERE id = :id AND title = :title');
    $anime->execute($input['anime']);
    $removedAnime = $anime->rowCount();
    $db->commit();
    echo json_encode(['users' => $removed, 'anime' => $removedAnime], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, fixture);
    assert.equal(result.users, 4);
    assert.equal(result.anime, 1);
    return result;
}

module.exports = {
    php, createAdminFixture, cleanupAdminFixture, setFixtureAdmin, fixtureState, createMessageReports, messageReportState,
};
