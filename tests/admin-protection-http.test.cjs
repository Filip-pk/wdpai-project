const assert = require('node:assert/strict');
const { randomBytes } = require('node:crypto');
const test = require('node:test');
const { login, csrf } = require('./helpers/quiz-fixtures.cjs');
const { php, createAdminFixture, cleanupAdminFixture } = require('./helpers/admin-fixtures.cjs');

const verifiedFixture = String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$marker = $input['marker'] ?? '';
if (!is_string($marker) || preg_match('/\A[a-f0-9-]{36}\z/', $marker) !== 1) {
    throw new RuntimeException('Invalid fixture marker.');
}
$db = (new App\Database())->connect();
$verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email');
foreach (['admin', 'moderator', 'member', 'target'] as $role) {
    $user = $input[$role];
    if ($user['email'] !== 'admin-fixture-' . $role . '-' . $marker . '@example.test') {
        throw new RuntimeException('Invalid fixture account.');
    }
    $verify->execute($user);
    if ($verify->fetchColumn() === false) throw new RuntimeException('Fixture ownership changed.');
}
`;

function seedContent(fixture) {
    return php(verifiedFixture + String.raw`
$db->beginTransaction();
try {
    $comments = new App\Repository\CommentRepository($db);
    $anime = $input['anime']['id'];
    $admin = $input['admin']['id'];
    $member = $input['member']['id'];
    $target = $input['target']['id'];
    $moderator = $input['moderator']['id'];
    $root = $comments->createForUser($admin, $anime, 'Admin protected comment ' . $marker);
    $ordinary = $comments->createForUser($target, $anime, 'Ordinary comment before promotion ' . $marker);
    $adminReply = $comments->createReplyForUser($admin, $anime, $ordinary, 'Admin protected reply ' . $marker);
    $targetReply = $comments->createReplyForUser($target, $anime, $root, 'Ordinary reply before promotion ' . $marker);
    $messages = new App\Repository\MessageRepository($db);
    $adminConversation = $messages->startWithMessageForUser($admin, $member, 'Admin protected message ' . $marker);
    $targetConversation = $messages->startWithMessageForUser($target, $member, 'Target message before promotion ' . $marker);
    $privateConversation = $messages->startWithMessageForUser($moderator, $target, 'Private moderator message ' . $marker);
    $memberMessage = $messages->sendForUser($member, $adminConversation, 'Ordinary member message ' . $marker);
    $lookup = $db->prepare('SELECT id FROM private_messages WHERE conversation_id = :conversation AND sender_id = :sender ORDER BY id LIMIT 1');
    $lookup->execute(['conversation' => $adminConversation, 'sender' => $admin]);
    $adminMessage = (int) $lookup->fetchColumn();
    $lookup->execute(['conversation' => $targetConversation, 'sender' => $target]);
    $targetMessage = (int) $lookup->fetchColumn();
    $db->commit();
    echo json_encode(compact('root', 'ordinary', 'adminReply', 'targetReply', 'adminConversation',
        'targetConversation', 'privateConversation', 'adminMessage', 'memberMessage', 'targetMessage'), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, fixture);
}

function state(fixture) {
    return php(verifiedFixture + String.raw`
$ids = array_map(static fn (string $role): int => $input[$role]['id'], ['admin', 'moderator', 'member', 'target']);
$parameters = array_combine(['admin', 'moderator', 'member', 'target'], $ids);
$scope = '(:admin, :moderator, :member, :target)';
$result = [];
foreach ([
    'comments' => 'SELECT id, user_id, body, moderated_at, deleted_at FROM anime_comments WHERE user_id IN ' . $scope . ' ORDER BY id',
    'reports' => 'SELECT id, target_type, target_id, reported_user_id, status FROM community_reports WHERE reporter_id IN ' . $scope . ' ORDER BY id',
    'blocks' => 'SELECT blocker_id, blocked_id FROM user_blocks WHERE blocker_id IN ' . $scope . ' ORDER BY blocker_id, blocked_id',
    'actions' => 'SELECT id, user_id, action_type FROM moderation_actions WHERE user_id IN ' . $scope . ' ORDER BY id',
    'notices' => 'SELECT id, user_id FROM moderation_notices WHERE user_id IN ' . $scope . ' ORDER BY id',
    'bans' => 'SELECT id, user_id, revoked_at FROM user_bans WHERE user_id IN ' . $scope . ' ORDER BY id',
] as $name => $sql) {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $result[$name] = $statement->fetchAll(PDO::FETCH_ASSOC);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
`, fixture);
}

function setTargetAdmin(fixture, enabled) {
    return php(verifiedFixture + String.raw`
$statement = $db->prepare('UPDATE users SET is_admin = :enabled WHERE id = :id AND email = :email');
$statement->bindValue('enabled', $input['enabled'], PDO::PARAM_BOOL);
$statement->bindValue('id', $input['target']['id'], PDO::PARAM_INT);
$statement->bindValue('email', $input['target']['email']);
$statement->execute();
echo json_encode(['updated' => $statement->rowCount()], JSON_THROW_ON_ERROR);
`, { ...fixture, enabled });
}

function cleanupContent(fixture) {
    return php(verifiedFixture + String.raw`
$parameters = [];
foreach (['admin', 'moderator', 'member', 'target'] as $role) $parameters[$role] = $input[$role]['id'];
$scope = '(:admin, :moderator, :member, :target)';
$db->beginTransaction();
try {
    // Notices reference their actions without cascading, so remove fixture notices first.
    foreach (['DELETE FROM moderation_notices WHERE user_id IN ' . $scope,
        'DELETE FROM moderation_actions WHERE user_id IN ' . $scope] as $sql) {
        $statement = $db->prepare($sql);
        $statement->execute($parameters);
    }
    $db->commit();
    echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    throw $exception;
}
`, fixture);
}

function forbidden(response) {
    assert.ok([403, 404, 409].includes(response.status), 'Protected action rejected with status ' + response.status);
}

function reportLink(id, type = 'comment') {
    return new RegExp('href="/reports/' + type + '/' + id + '(?:\\?|"|&)');
}

function actionLink(route) {
    return 'action="' + route + '"';
}

test('administrator protection applies to UI, forged requests and changing account roles', { timeout: 180000 }, async t => {
    const fixture = createAdminFixture();
    let content;
    try {
        content = seedContent(fixture);
        const admin = await login(fixture.admin, fixture.password);
        const moderator = await login(fixture.moderator, fixture.password);
        const member = await login(fixture.member, fixture.password);
        const target = await login(fixture.target, fixture.password);
        const token = csrf(await member.request('/profile'));
        const adminToken = csrf(await admin.request('/profile'));
        const moderatorToken = csrf(await moderator.request('/moderation'));
        const targetToken = csrf(await target.request('/profile'));
        const animeRoute = '/anime/' + fixture.anime.id;
        const decision = extra => ({ csrfToken: moderatorToken, reason: 'Administrator protection QA',
            requestKey: randomBytes(16).toString('hex'), ...extra });
        let historicalReport;

        await t.test('administrator profiles cannot be blocked, including direct requests from moderators', async () => {
            const before = state(fixture);
            for (const [client, csrfToken] of [[member, token], [moderator, moderatorToken]]) {
                const profile = await client.request('/users/' + fixture.admin.id);
                assert.equal(profile.status, 200);
                assert.ok(!profile.html.includes(actionLink('/users/' + fixture.admin.id + '/block')));
                forbidden(await client.request('/users/' + fixture.admin.id + '/block', { csrfToken }));
            }
            assert.deepEqual(state(fixture), before);
        });

        await t.test('administrator comments and replies cannot be reported through links, GET or forged POST', async () => {
            const before = state(fixture);
            const page = await member.request(animeRoute);
            const thread = await member.request(animeRoute + '/comments/' + content.ordinary);
            assert.equal(page.status, 200);
            assert.equal(thread.status, 200);
            assert.doesNotMatch(page.html, reportLink(content.root));
            assert.doesNotMatch(thread.html, reportLink(content.adminReply));
            for (const id of [content.root, content.adminReply]) {
                for (const [client, csrfToken] of [[member, token], [moderator, moderatorToken]]) {
                    forbidden(await client.request('/reports/comment/' + id));
                    forbidden(await client.request('/reports/comment/' + id, { csrfToken, reason: 'Forged protected report' }));
                }
            }
            assert.deepEqual(state(fixture), before);
        });

        await t.test('administrator private messages are protected without extending private conversation access', async () => {
            const before = state(fixture);
            const page = await member.request('/messages/' + content.adminConversation);
            assert.equal(page.status, 200);
            assert.doesNotMatch(page.html, reportLink(content.adminMessage, 'message'));
            assert.ok(!page.html.includes(actionLink('/users/' + fixture.admin.id + '/block')));
            forbidden(await member.request('/reports/message/' + content.adminMessage));
            forbidden(await member.request('/reports/message/' + content.adminMessage,
                { csrfToken: token, reason: 'Forged protected private report' }));
            assert.equal((await admin.request('/reports/message/' + content.memberMessage)).status, 200,
                'Ordinary received messages remain reportable by an administrator');
            assert.equal((await admin.request('/messages/' + content.privateConversation)).status, 404,
                'Administrator privileges do not grant access to another private conversation');
            assert.equal((await admin.request('/moderation')).status, 403,
                'Administrator role remains independent from moderator role');
            assert.deepEqual(state(fixture), before);
        });

        await t.test('moderator cannot warn, remove or ban administrator even by direct IDs', async () => {
            const before = state(fixture);
            const comments = await moderator.request('/moderation?tab=comments&author=' + fixture.admin.id);
            for (const id of [content.root, content.adminReply]) {
                for (const action of ['remove', 'warn']) {
                    const route = '/moderation/comments/' + id + '/' + action;
                    assert.ok(!comments.html.includes(actionLink(route)));
                    forbidden(await moderator.request(route, decision({ userId: fixture.admin.id })));
                }
            }
            const users = await moderator.request('/moderation?tab=users&author=' + fixture.admin.id);
            const banRoute = '/moderation/users/' + fixture.admin.id + '/ban';
            assert.ok(!users.html.includes(actionLink(banRoute)));
            forbidden(await moderator.request(banRoute, decision({ duration: '3600' })));
            assert.deepEqual(state(fixture), before);
            const ownEditor = await admin.request(animeRoute + '?editComment=' + content.root);
            assert.equal(ownEditor.status, 200, 'Protection does not prevent the administrator editing their own content');
        });

        await t.test('ordinary accounts remain blockable and reportable before promotion', async () => {
            const profile = await member.request('/users/' + fixture.target.id);
            assert.ok(profile.html.includes(actionLink('/users/' + fixture.target.id + '/block')));
            assert.equal((await member.request('/reports/comment/' + content.ordinary)).status, 200);
            assert.equal((await member.request('/reports/comment/' + content.ordinary,
                { csrfToken: token, reason: 'Historical report before promotion' })).status, 303);
            historicalReport = state(fixture).reports.find(entry => Number(entry.target_id) === content.ordinary).id;
            assert.equal((await member.request('/users/' + fixture.target.id + '/block', { csrfToken: token })).status, 303);
            assert.equal((await member.request('/messages/' + content.targetConversation,
                { csrfToken: token, body: 'Blocked before promotion ' + fixture.marker })).status, 403);
        });

        await t.test('promotion protects existing content and disables historical incoming blocks immediately', async () => {
            assert.equal(setTargetAdmin(fixture, true).updated, 1);
            const before = state(fixture);
            const profile = await member.request('/users/' + fixture.target.id);
            assert.ok(!profile.html.includes(actionLink('/users/' + fixture.target.id + '/block')));
            const blocked = await member.request('/messages/blocked');
            assert.ok(!blocked.html.includes('href="/users/' + fixture.target.id + '"'));
            const page = await member.request('/messages/' + content.targetConversation);
            assert.doesNotMatch(page.html, reportLink(content.targetMessage, 'message'));
            forbidden(await member.request('/users/' + fixture.target.id + '/block', { csrfToken: token }));
            for (const id of [content.ordinary, content.targetReply]) {
                forbidden(await member.request('/reports/comment/' + id));
                forbidden(await member.request('/reports/comment/' + id,
                    { csrfToken: token, reason: 'Stale report form after promotion' }));
                for (const action of ['remove', 'warn']) {
                    forbidden(await moderator.request('/moderation/comments/' + id + '/' + action,
                        decision({ userId: fixture.target.id })));
                }
            }
            forbidden(await member.request('/reports/message/' + content.targetMessage,
                { csrfToken: token, reason: 'Stale private report after promotion' }));
            forbidden(await moderator.request('/moderation/users/' + fixture.target.id + '/ban', decision({ duration: '3600' })));
            forbidden(await moderator.request('/moderation/reports/' + historicalReport + '/resolve', decision({ action: 'remove' })));
            assert.deepEqual(state(fixture), before, 'Rejected operations preserve comments, reports, blocks, bans and moderation history');
            assert.equal((await member.request('/messages/' + content.targetConversation,
                { csrfToken: token, body: 'Allowed after promotion ' + fixture.marker })).status, 303);
            assert.equal((await target.request('/messages/' + content.targetConversation,
                { csrfToken: targetToken, body: 'Administrator can reply after promotion ' + fixture.marker })).status, 303);
        });

        await t.test('historical reports can be dismissed without warning or removing administrator content', async () => {
            const before = state(fixture);
            const reports = await moderator.request('/moderation?tab=reports&author=' + fixture.target.id);
            assert.ok(!reports.html.includes(actionLink('/moderation/comments/' + content.ordinary + '/remove')));
            assert.ok(!reports.html.includes(actionLink('/moderation/comments/' + content.ordinary + '/warn')));
            assert.ok(reports.html.includes(actionLink('/moderation/reports/' + historicalReport + '/dismiss')));
            assert.equal((await moderator.request('/moderation/reports/' + historicalReport + '/dismiss', decision({}))).status, 303);
            const after = state(fixture);
            assert.equal(after.reports.find(entry => Number(entry.id) === Number(historicalReport)).status, 'dismissed');
            assert.deepEqual(after.comments, before.comments);
            assert.deepEqual(after.bans, before.bans);
            assert.deepEqual(after.notices, before.notices, 'Dismissal does not penalize or notify the protected account');
            assert.equal(after.actions.at(-1).action_type, 'report_dismissed');
        });

        await t.test('revocation restores ordinary restrictions and reporting with the original session', async () => {
            assert.equal(setTargetAdmin(fixture, false).updated, 1);
            assert.equal((await member.request('/messages/' + content.targetConversation,
                { csrfToken: token, body: 'Legacy block applies after revocation ' + fixture.marker })).status, 403);
            assert.equal((await member.request('/users/' + fixture.target.id + '/unblock', { csrfToken: token })).status, 303);
            const thread = await member.request(animeRoute + '/comments/' + content.root);
            assert.match(thread.html, reportLink(content.targetReply));
            assert.equal((await member.request('/reports/comment/' + content.targetReply)).status, 200);
            assert.equal((await member.request('/reports/comment/' + content.targetReply,
                { csrfToken: token, reason: 'Ordinary reply after role revocation' })).status, 303);
            assert.equal((await member.request('/reports/message/' + content.targetMessage)).status, 200);
        });

        await t.test('administrator can still block an ordinary user', async () => {
            const profile = await admin.request('/users/' + fixture.member.id);
            assert.ok(profile.html.includes(actionLink('/users/' + fixture.member.id + '/block')));
            assert.equal((await admin.request('/users/' + fixture.member.id + '/block', { csrfToken: adminToken })).status, 303);
            assert.equal((await member.request('/messages/' + content.adminConversation,
                { csrfToken: token, body: 'Administrator outgoing block stays active ' + fixture.marker })).status, 403);
        });
    } finally {
        try {
            if (content) cleanupContent(fixture);
        } finally {
            cleanupAdminFixture(fixture);
        }
    }
});
