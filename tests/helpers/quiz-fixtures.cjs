const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const projectRoot = path.join(__dirname, '..', '..');

function php(source, input) {
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r',
        "declare(strict_types=1); require 'vendor/autoload.php'; " + source, JSON.stringify(input)], {
        cwd: projectRoot, windowsHide: true, encoding: 'utf8', timeout: 30000,
    }));
}

function createQuizFixture() {
    const password = 'Quiz-Fixture-' + randomUUID() + '!';
    const fixture = php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (preg_match('/\A[a-f0-9-]{36}\z/', $input['marker']) !== 1) throw new RuntimeException('Invalid marker.');
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $users = new App\Repository\UserRepository($db);
    $result = ['marker' => $input['marker']];
    foreach (['owner', 'other'] as $role) {
        $email = 'quiz-fixture-' . $role . '-' . $input['marker'] . '@example.test';
        $name = 'Quiz ' . $role . ' <svg onload=alert(1)>';
        $id = $users->create($name, $email, password_hash($input['password'], PASSWORD_DEFAULT));
        $result[$role] = ['id' => $id, 'email' => $email];
    }
    $result['quiz'] = (new App\Service\QuizService(new App\Repository\QuizRepository($db)))->dailyQuiz();
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: randomUUID(), password });
    return { ...fixture, password };
}

function cleanupQuizFixture(fixture) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$marker = $input['marker'] ?? '';
if (!is_string($marker) || preg_match('/\A[a-f0-9-]{36}\z/', $marker) !== 1) throw new RuntimeException('Invalid marker.');
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email FOR UPDATE');
    $remove = $db->prepare('DELETE FROM users WHERE id = :id AND email = :email');
    $removed = 0;
    foreach (['owner', 'other'] as $role) {
        $user = $input[$role];
        if (!is_int($user['id']) || $user['email'] !== 'quiz-fixture-' . $role . '-' . $marker . '@example.test') {
            throw new RuntimeException('Cleanup is limited to the recorded fixture.');
        }
        $verify->execute($user);
        if ($verify->fetchColumn() === false) throw new RuntimeException('Fixture ownership mismatch.');
        $remove->execute($user);
        $removed += $remove->rowCount();
    }
    $db->commit();
    echo json_encode(['removed' => $removed], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, { marker: fixture.marker, owner: fixture.owner, other: fixture.other });
}

function browser() {
    let cookie = '';
    return {
        session() { return cookie.slice('PHPSESSID='.length); },
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: cookie ? { Cookie: cookie } : {},
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual', signal: AbortSignal.timeout(15000),
            });
            for (const value of response.headers.getSetCookie()) {
                if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
            }
            const html = await response.text();
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
            return { status: response.status, headers: response.headers, html };
        },
    };
}

function csrf(page) {
    const token = page.html.match(/name="csrf-token" content="([a-f0-9]{64})"/);
    assert.ok(token, 'A CSRF token is present');
    return token[1];
}

async function login(user, password) {
    const client = browser();
    const token = csrf(await client.request('/login'));
    const response = await client.request('/login', { email: user.email, password, csrfToken: token });
    assert.equal(response.status, 303);
    assert.equal(response.headers.get('location'), '/projects');
    return client;
}

function quizFields(fixture, token, correct = true) {
    const fields = { quizId: String(fixture.quiz.id), csrfToken: token };
    for (const question of fixture.quiz.questions) {
        fields['answers[' + question.id + ']'] = String(correct ? question.correctOption : (question.correctOption + 1) % 4);
    }
    return fields;
}

module.exports = { baseUrl, createQuizFixture, cleanupQuizFixture, browser, csrf, login, quizFields };
