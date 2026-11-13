const assert = require('node:assert/strict');
const { randomUUID, randomBytes } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const test = require('node:test');
const { createAnimeCommunityFixtures, cleanupAnimeCommunityFixtures } = require('./helpers/anime-community-fixtures.cjs');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const projectRoot = path.join(__dirname, '..');
const password = 'Moderation-Fixture-2026!';
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const requestKey = () => randomBytes(32).toString('hex');

function browser() {
    let cookie = '';
    return {
        async request(route, fields, json = false) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: { ...(cookie ? { Cookie: cookie } : {}), ...(json ? { Accept: 'application/json' } : {}) },
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual', signal: AbortSignal.timeout(15000),
            });
            for (const value of response.headers.getSetCookie()) {
                if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
            }
            const html = await response.text();
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
            return { status: response.status, headers: response.headers, html,
                data: response.headers.get('content-type')?.includes('application/json') ? JSON.parse(html) : null };
        },
    };
}

function csrf(page) {
    const match = page.html.match(/name="csrf-token" content="([a-f0-9]{64})"/)
        ?? page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match, 'Response exposes a CSRF token');
    return match[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303, page.html.slice(0, 400));
    if (location) assert.equal(page.headers.get('location'), location);
}

async function account(label, marker) {
    const client = browser();
    const name = `${label} ${marker}`;
    const email = `moderation-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }));
    redirected(await client.request('/login', { email, password, csrfToken: token }));
    const profile = await client.request('/profile');
    assert.equal(profile.status, 200);
    return { client, name, email, id: Number(profile.html.match(/data-account-id="(\d+)"/)[1]), token: csrf(profile) };
}

function role(user, enabled) {
    execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', 'scripts/set-moderator.php', String(user.id), ...(enabled ? [] : ['--revoke'])], {
        cwd: projectRoot, windowsHide: true, stdio: 'pipe', timeout: 20000,
    });
}

// Every DB helper verifies fixture ownership; no production row can be selected by an arbitrary ID.
function fixtureState(operation, user, extra = {}) {
    const source = String.raw`
declare(strict_types=1); require 'vendor/autoload.php';
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email');
$verify->execute(['id' => $input['id'], 'email' => $input['email']]);
if ($verify->fetchColumn() === false || !str_starts_with($input['email'], 'moderation-') || !str_ends_with($input['email'], '@example.test')) {
    throw new RuntimeException('Fixture ownership mismatch.');
}
$userId = (int) $input['id'];
if ($input['operation'] === 'expire') {
    $change = $db->prepare("UPDATE user_bans SET starts_at = clock_timestamp() - INTERVAL '2 hours', ends_at = clock_timestamp() - INTERVAL '1 hour' WHERE user_id = :id AND revoked_at IS NULL");
    $change->execute(['id' => $userId]);
}
if ($input['operation'] === 'cleanup') {
    $db->beginTransaction();
    try {
        $notices = $db->prepare('DELETE FROM moderation_notices WHERE user_id = :id');
        $notices->execute(['id' => $userId]);
        $actions = $db->prepare('DELETE FROM moderation_actions WHERE user_id = :id');
        $actions->execute(['id' => $userId]);
        $delete = $db->prepare('DELETE FROM users WHERE id = :id AND email = :email');
        $delete->execute(['id' => $userId, 'email' => $input['email']]);
        $db->commit();
        echo json_encode(['deleted' => $delete->rowCount()], JSON_THROW_ON_ERROR);
        exit;
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }
}
$bans = $db->prepare('SELECT id, reason, starts_at, ends_at, revoked_at FROM user_bans WHERE user_id = :id ORDER BY id');
$bans->execute(['id' => $userId]);
$notices = $db->prepare('SELECT id, read_at FROM moderation_notices WHERE user_id = :id ORDER BY id');
$notices->execute(['id' => $userId]);
echo json_encode(['bans' => $bans->fetchAll(PDO::FETCH_ASSOC), 'notices' => $notices->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR);
`;
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r', source,
        JSON.stringify({ operation, id: user.id, email: user.email, ...extra })], {
        cwd: projectRoot, windowsHide: true, encoding: 'utf8', timeout: 30000,
    }));
}

test('moderation protects private messages, stale sessions, writes and owner-only decision history', async t => {
    const marker = randomUUID();
    const accounts = [];
    const guest = browser();
    const fixture = createAnimeCommunityFixtures();
    const anime = fixture.animes[0];
    const animeRoute = '/anime/' + anime.id;
    let moderator, otherModerator, owner, recipient, outsider;
    const decision = (reason, more = {}) => ({ reason, requestKey: requestKey(), csrfToken: moderator.token, ...more });
    try {
        for (const label of ['Moderator', 'Other moderator', 'Owner', 'Recipient', 'Outsider']) {
            accounts.push(await account(label, marker));
        }
        [moderator, otherModerator, owner, recipient, outsider] = accounts;
        role(moderator, true);
        role(otherModerator, true);

        let commentId, reportId, warningNotice, conversationRoute, privateMessageId;
        await t.test('all panel views recheck moderator privileges and CSRF', async () => {
            for (const tab of ['reports', 'comments', 'users', 'history']) {
                assert.equal((await moderator.client.request('/moderation?tab=' + tab)).status, 200);
                assert.equal((await outsider.client.request('/moderation?tab=' + tab)).status, 403);
            }
            assert.equal((await moderator.client.request('/moderation/users/' + owner.id + '/ban', decision('Test', { csrfToken: 'wrong', duration: 3600 }))).status, 403);
            assert.equal((await moderator.client.request('/moderation/users/' + owner.id + '/ban', decision('', { duration: 3600 }))).status, 422);
            assert.equal((await moderator.client.request('/moderation/users/' + owner.id + '/ban', decision('Invalid duration', { duration: 60 }))).status, 422);
            for (const id of [moderator.id, otherModerator.id]) {
                assert.equal((await moderator.client.request('/moderation/users/' + id + '/ban', decision('Protected moderator', { duration: 3600 }))).status, 409);
            }
            role(moderator, false);
            assert.equal((await moderator.client.request('/moderation')).status, 403, 'Role revocation takes effect in the already open session');
            assert.equal((await moderator.client.request('/moderation/users/' + owner.id + '/ban', decision('Stale role', { duration: 3600 }))).status, 403);
            assert.equal(fixtureState('state', owner).bans.length, 0);
            role(moderator, true);
        });

        await t.test('private message report IDs never expose fragments or permit a decision', async () => {
            const body = marker + ' PRIVATE <script>alert(1)</script>';
            const sent = await owner.client.request('/messages/new/' + recipient.id, { body, csrfToken: owner.token });
            redirected(sent);
            conversationRoute = sent.headers.get('location').split('?')[0].split('#')[0];
            const conversation = await recipient.client.request(conversationRoute);
            privateMessageId = Number(conversation.html.match(/data-message-id="(\d+)"/)[1]);
            redirected(await recipient.client.request('/reports/message/' + privateMessageId, { reason: marker + ' PRIVATE REPORT', csrfToken: recipient.token }));
            const source = String.raw`declare(strict_types=1); require 'vendor/autoload.php'; $db=(new App\Database())->connect(); $q=$db->prepare("SELECT id FROM community_reports WHERE reporter_id=:reporter AND target_type='message' AND target_id=:target"); $q->execute(['reporter'=>(int)$argv[1],'target'=>(int)$argv[2]]); echo $q->fetchColumn();`;
            const privateReportId = Number(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r', source, String(recipient.id), String(privateMessageId)], {
                cwd: projectRoot, windowsHide: true, encoding: 'utf8', timeout: 20000,
            }));
            assert.ok(privateReportId > 0);
            for (const tab of ['reports', 'comments', 'users', 'history']) {
                const panel = await moderator.client.request('/moderation?tab=' + tab);
                assert.ok(!panel.html.includes(escape(body)) && !panel.html.includes(marker + ' PRIVATE REPORT'));
            }
            assert.equal((await moderator.client.request(conversationRoute)).status, 404);
            assert.equal((await moderator.client.request('/reports/message/' + privateMessageId)).status, 404);
            for (const action of ['remove', 'dismiss']) {
                assert.equal((await moderator.client.request('/moderation/reports/' + privateReportId + '/resolve', decision('Private ID attack', { action }))).status, 409);
            }
            assert.equal((await moderator.client.request('/moderation/reports/' + privateReportId + '/dismiss', decision('Private ID attack'))).status, 409);
            assert.ok((await recipient.client.request(conversationRoute)).html.includes(escape(body)), 'Reported private message stays accessible only to participants');
            const ownMessage = marker + ' moderator normal conversation';
            redirected(await moderator.client.request('/messages/new/' + outsider.id, { body: ownMessage, csrfToken: moderator.token }));
            assert.ok((await moderator.client.request('/messages')).html.includes(ownMessage), 'Moderator retains ordinary access to own conversations');
        });

        await t.test('unsolicited warnings and removals require reasons and create durable private notices once', async () => {
            const body = marker + ' public comment <b>escaped</b>';
            redirected(await owner.client.request(animeRoute + '/comments', { body, csrfToken: owner.token }));
            const publicPage = await guest.request(animeRoute);
            const match = [...publicPage.html.matchAll(/<article\b[^>]*data-comment-id="(\d+)"[^>]*>[\s\S]*?<\/article>/g)].find(match => match[0].includes(escape(body)));
            assert.ok(match);
            commentId = Number(match[1]);
            const warning = decision(marker + ' warning reason <img src=x onerror=alert(1)>', { userId: owner.id, commentId });
            const warnRoute = '/moderation/comments/' + commentId + '/warn';
            redirected(await moderator.client.request(warnRoute, warning));
            assert.equal((await moderator.client.request(warnRoute, warning)).status, 409, 'Repeated decision key is not applied twice');
            const afterWarning = fixtureState('state', owner);
            assert.equal(afterWarning.bans.length, 0, 'Warning does not ban automatically');
            assert.equal(afterWarning.notices.length, 1);
            warningNotice = Number(afterWarning.notices[0].id);
            assert.equal((await owner.client.request('/dashboard')).status, 200);
            assert.ok((await owner.client.request('/account/notices')).html.includes(escape(warning.reason)));
            const freshOwner = browser();
            const loginToken = csrf(await freshOwner.request('/login'));
            const loggedIn = await freshOwner.request('/login', { email: owner.email, password, csrfToken: loginToken });
            redirected(loggedIn);
            assert.ok((await freshOwner.request(loggedIn.headers.get('location'))).html.includes('/account/notices'), 'Pending notices are available immediately after sign in');
            redirected(await recipient.client.request('/reports/comment/' + commentId, { reason: marker + ' public report', csrfToken: recipient.token }));
            const queue = await moderator.client.request('/moderation?author=' + encodeURIComponent(owner.name));
            const reported = [...queue.html.matchAll(/<article\b[^>]*data-report-id="(\d+)"[^>]*>[\s\S]*?<\/article>/g)].find(match => match[0].includes(escape(body)));
            assert.ok(reported);
            reportId = Number(reported[1]);
            const removal = decision(marker + ' removal', { reportId });
            const removeRoute = '/moderation/comments/' + commentId + '/remove';
            assert.equal((await moderator.client.request(removeRoute, { ...removal, reason: '' })).status, 422);
            redirected(await moderator.client.request(removeRoute, removal));
            assert.equal((await moderator.client.request(removeRoute, removal)).status, 409);
            assert.equal(fixtureState('state', owner).notices.length, 2);
            assert.ok(!(await guest.request(animeRoute)).html.includes(escape(body)));
            const history = await moderator.client.request('/moderation?tab=history&author=' + encodeURIComponent(owner.name));
            assert.ok(history.html.includes(escape(removal.reason)) && history.html.includes(escape(warning.reason)));
        });

        await t.test('notice access is owner-only and acknowledging keeps history', async () => {
            assert.ok(!(await outsider.client.request('/account/notices?userId=' + owner.id)).html.includes(marker + ' removal'));
            assert.equal((await outsider.client.request('/account/notices/' + warningNotice + '/read', { csrfToken: outsider.token, userId: owner.id })).status, 404);
            assert.equal((await owner.client.request('/account/notices/' + warningNotice + '/read', { csrfToken: 'wrong' })).status, 403);
            assert.equal(fixtureState('state', owner).notices[0].read_at, null);
            redirected(await owner.client.request('/account/notices/' + warningNotice + '/read', { csrfToken: owner.token }));
            const state = fixtureState('state', owner);
            assert.notEqual(state.notices[0].read_at, null);
            assert.equal(state.notices.length, 2);
            assert.ok((await owner.client.request('/account/notices')).html.includes(marker + ' removal'));
        });

        await t.test('ban applies to sessions opened before the decision and every write category', async () => {
            const fields = decision(marker + ' timed ban', { duration: 3600 });
            redirected(await moderator.client.request('/moderation/users/' + owner.id + '/ban', fields));
            assert.equal((await moderator.client.request('/moderation/users/' + owner.id + '/ban', fields)).status, 409);
            for (const route of ['/dashboard', '/anime', '/profile', '/settings', '/messages', '/moderation']) {
                redirected(await owner.client.request(route), '/account/banned');
            }
            const writes = [
                [animeRoute + '/comments', { body: marker + ' bypass' }],
                [animeRoute + '/comments/' + commentId + '/edit', { body: marker + ' bypass' }],
                [animeRoute + '/comments/' + commentId + '/delete', {}],
                [animeRoute + '/comments/' + commentId + '/replies', { body: marker + ' bypass' }],
                [animeRoute + '/comments/' + commentId + '/reaction', { reaction: 'like' }],
                [animeRoute + '/rating', { rating: '10' }],
                [animeRoute + '/collections', { collectionIds: '1' }],
                ['/watchlist', { animeId: anime.id, status: 'planned' }],
                ['/watchlist/remove', { animeId: anime.id }],
                [conversationRoute, { body: marker + ' blocked message' }],
                ['/messages/new/' + outsider.id, { body: marker + ' blocked new' }],
                ['/profile/import/preview', {}], ['/profile/import/confirm', {}], ['/profile/import/cancel', {}],
                ['/settings', { theme: 'dark' }], ['/settings/password', {}], ['/settings/email', {}],
                ['/settings/privacy', {}], ['/settings/accessibility', {}], ['/settings/appearance/reset', {}],
                ['/profile', { name: 'Bypass' }], ['/profile/avatar', {}], ['/profile/theme', { theme: 'dark' }],
                ['/profile/collections', { name: 'Bypass' }],
                ['/moderation/users/' + outsider.id + '/ban', { reason: 'Bypass', duration: 3600, requestKey: requestKey() }],
            ];
            for (const [route, extra] of writes) {
                const result = await owner.client.request(route, { ...extra, csrfToken: owner.token }, true);
                assert.equal(result.status, 403, route);
                assert.equal(result.data?.redirect, '/account/banned', route + ' returns a JSON redirect target');
            }
            const polling = await owner.client.request('/notifications', undefined, true);
            assert.equal(polling.status, 403);
            assert.equal(polling.data?.redirect, '/account/banned');
            const banPage = await owner.client.request('/account/banned');
            assert.equal(banPage.status, 200);
            assert.ok(banPage.html.includes(marker + ' timed ban'));
            assert.equal((await owner.client.request('/account/notices')).status, 200);
            const noticeId = fixtureState('state', owner).notices.at(-1).id;
            redirected(await owner.client.request('/account/notices/' + noticeId + '/read', { csrfToken: owner.token }));
            const banLogin = browser();
            const loginToken = csrf(await banLogin.request('/login'));
            assert.equal((await banLogin.request('/login', { email: owner.email, password: 'wrong-password', csrfToken: loginToken })).status, 401);
            redirected(await banLogin.request('/login', { email: owner.email, password, csrfToken: loginToken }), '/account/banned');
        });

        await t.test('all six languages work during ban, logout preserves public access and expiration uses DB time', async () => {
            for (const locale of ['pl', 'en', 'de', 'fr', 'es', 'ru']) {
                redirected(await owner.client.request('/language', { language: locale, returnTo: '/account/notices', csrfToken: owner.token }), '/account/notices');
                const notices = await owner.client.request('/account/notices');
                assert.match(notices.html, new RegExp('<html lang="' + locale + '"'));
                assert.doesNotMatch(notices.html, />moderation\.[\w.]+</);
            }
            redirected(await owner.client.request('/language', { language: 'pl', returnTo: '/account/banned', csrfToken: owner.token }));
            const signOut = browser();
            const token = csrf(await signOut.request('/login'));
            redirected(await signOut.request('/login', { email: owner.email, password, csrfToken: token }), '/account/banned');
            const blocked = await signOut.request('/account/banned');
            redirected(await signOut.request('/logout', { csrfToken: csrf(blocked) }));
            assert.equal((await signOut.request('/anime')).status, 200);
            fixtureState('expire', owner);
            assert.equal((await owner.client.request('/dashboard')).status, 200, 'Open session unlocks automatically by database wall clock');
            assert.equal(fixtureState('state', owner).notices.length, 3, 'Expiration keeps all decisions');
        });

        await t.test('revocation is reasoned, audited, idempotent and immediately unlocks an existing session', async () => {
            redirected(await moderator.client.request('/moderation/users/' + owner.id + '/ban', decision(marker + ' second ban', { duration: 86400 })));
            const state = fixtureState('state', owner);
            const banId = state.bans.at(-1).id;
            const route = '/moderation/bans/' + banId + '/revoke';
            const revoke = decision(marker + ' revoke reason');
            assert.equal((await moderator.client.request(route, { ...revoke, csrfToken: 'wrong' })).status, 403);
            assert.equal((await moderator.client.request(route, { ...revoke, reason: '' })).status, 422);
            redirected(await moderator.client.request(route, revoke));
            assert.equal((await moderator.client.request(route, revoke)).status, 409);
            assert.equal((await owner.client.request('/dashboard')).status, 200);
            const after = fixtureState('state', owner);
            assert.notEqual(after.bans.at(-1).revoked_at, null);
            assert.equal(after.notices.length, 5);
            assert.ok((await owner.client.request('/account/notices')).html.includes(marker + ' revoke reason'));
        });
    } finally {
        // Exact account ID + unique email ownership is rechecked by the helper before deletion.
        for (const user of accounts.reverse()) fixtureState('cleanup', user);
        cleanupAnimeCommunityFixtures(fixture);
    }
});
