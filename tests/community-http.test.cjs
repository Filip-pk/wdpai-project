const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Spolecznosc-Testowa-2026!';
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');

function browser() {
    let cookie = '';
    return {
        async request(route, fields, json = false) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: { ...(cookie ? { Cookie: cookie } : {}), ...(json ? { Accept: 'application/json' } : {}) },
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual', signal: AbortSignal.timeout(10000),
            });
            for (const value of response.headers.getSetCookie()) {
                if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
            }
            const html = await response.text();
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
            return { status: response.status, headers: response.headers, html,
                data: json && response.headers.get('content-type')?.includes('application/json') ? JSON.parse(html) : null };
        },
    };
}

function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match);
    return match[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    if (location) assert.equal(page.headers.get('location'), location);
}

function entries(page, type) {
    return [...page.html.matchAll(new RegExp('<article\\b[^>]*data-' + type + '-id="(\\d+)"[^>]*>[\\s\\S]*?<\\/article>', 'g'))]
        .map(match => ({ id: Number(match[1]), html: match[0] }));
}

async function account(label) {
    const client = browser();
    const name = label + ' ' + randomUUID();
    const email = 'community-' + randomUUID() + '@example.test';
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }));
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    return { client, name, email, id: Number(profile.html.match(/data-account-id="(\d+)"/)[1]), token: csrf(profile) };
}

function moderatorRole(user, enabled) {
    execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', 'scripts/set-moderator.php', String(user.id), ...(enabled ? [] : ['--revoke'])], {
        cwd: path.join(__dirname, '..'), windowsHide: true, stdio: 'pipe', timeout: 15000,
    });
}

test('notifications, blocking, reports and spam limits protect the complete community flow', async (t) => {
    const guest = browser();
    const first = await account('Społeczność A');
    const second = await account('Społeczność B');
    const third = await account('Społeczność C');
    const moderator = await account('Moderator testowy');
    const marker = randomUUID();
    const catalog = await guest.request('/anime');
    const animeRoute = '/anime/' + catalog.html.match(/data-anime-id="(\d+)"/)[1];
    const createdComments = [];
    let route, firstMessage, lastMessage, promoted = false;
    const unread = async user => {
        const result = await user.client.request('/notifications', undefined, true);
        assert.equal(result.status, 200);
        return result.data.unreadCount;
    };
    const send = async (user, destination, body) => {
        const result = await user.client.request(destination, { body, csrfToken: user.token });
        redirected(result);
        const match = result.headers.get('location').match(/^\/messages\/(\d+)/);
        assert.ok(match);
        return '/messages/' + match[1];
    };
    const comment = async (owner, body) => {
        redirected(await owner.client.request(animeRoute + '/comments', { body, csrfToken: owner.token }));
        const saved = entries(await owner.client.request(animeRoute), 'comment').find(entry => entry.html.includes(escape(body)));
        assert.ok(saved);
        createdComments.push({ id: saved.id, owner });
        return saved.id;
    };
    const reportId = async body => {
        const queue = await moderator.client.request('/moderation');
        assert.equal(queue.status, 200);
        const item = entries(queue, 'report').find(entry => entry.html.includes(escape(body)));
        assert.ok(item, 'The queue contains only the reported snapshot');
        return item.id;
    };

    try {
        await t.test('anonymous users and ordinary accounts cannot use privileged operations', async () => {
            for (const url of ['/notifications', '/messages/blocked', '/moderation', '/reports/comment/1']) {
                redirected(await guest.request(url), '/login');
            }
            assert.equal((await first.client.request('/moderation')).status, 403);
            assert.equal((await first.client.request('/moderation/reports/1/resolve', { action: 'remove', csrfToken: first.token })).status, 403);
            assert.equal((await first.client.request('/users/' + second.id + '/block', { csrfToken: 'wrong' })).status, 403);
            assert.equal((await first.client.request('/users/' + first.id + '/block', { csrfToken: first.token })).status, 404);
        });

        await t.test('reading acknowledges only the shown snapshot and never another participant cursor', async () => {
            route = await send(first, '/messages/new/' + second.id, marker + ' pierwsza wiadomość');
            firstMessage = entries(await second.client.request(route), 'message')[0].id;
            assert.equal(await unread(second), 1, 'A GET does not mark the conversation read');
            assert.equal(await unread(first), 0);
            const navigation = await second.client.request('/dashboard');
            assert.match(navigation.html, /data-unread-message-count[^>]*>1<\/span>/);
            assert.equal((await second.client.request(route + '/read', { lastMessageId: firstMessage, csrfToken: 'wrong' }, true)).status, 403);
            assert.equal((await third.client.request(route + '/read', { lastMessageId: firstMessage, csrfToken: third.token }, true)).status, 404);
            assert.equal((await second.client.request(route + '/read', { lastMessageId: firstMessage, csrfToken: second.token }, true)).data.unreadCount, 0);
            await send(first, route, marker + ' druga wiadomość');
            lastMessage = entries(await second.client.request(route), 'message').at(-1).id;
            await second.client.request(route + '/read', { lastMessageId: firstMessage, csrfToken: second.token }, true);
            assert.equal(await unread(second), 1, 'An older cursor does not acknowledge a newer incoming message');
            await second.client.request(route + '/read', { lastMessageId: lastMessage, csrfToken: second.token, userId: third.id }, true);
            assert.equal(await unread(second), 0);
        });

        await t.test('blocking prevents messages in both directions while retaining private history', async () => {
            redirected(await first.client.request('/users/' + second.id + '/block', { csrfToken: first.token }));
            assert.ok((await first.client.request('/messages/blocked')).html.includes(second.name));
            for (const user of [first, second]) {
                assert.equal((await user.client.request(route)).status, 200);
                assert.equal((await user.client.request(route, { body: marker + ' blokada', csrfToken: user.token })).status, 403);
            }
            assert.equal((await second.client.request('/messages/new/' + first.id, { body: marker + ' nowy', csrfToken: second.token })).status, 403);
            redirected(await second.client.request('/users/' + first.id + '/block', { csrfToken: second.token }));
            redirected(await first.client.request('/users/' + second.id + '/unblock', { csrfToken: first.token }));
            assert.equal((await first.client.request(route, { body: marker + ' nadal blokada', csrfToken: first.token })).status, 403);
            redirected(await second.client.request('/users/' + first.id + '/unblock', { csrfToken: second.token }));
            await send(first, route, marker + ' po odblokowaniu <script>alert(1)</script>');
        });

        await t.test('only recipients can report a private message and moderators cannot see or resolve its snapshot', async () => {
            const snapshot = marker + ' po odblokowaniu <script>alert(1)</script>';
            lastMessage = entries(await second.client.request(route), 'message').at(-1).id;
            const endpoint = '/reports/message/' + lastMessage;
            assert.equal((await third.client.request(endpoint)).status, 404);
            assert.equal((await first.client.request(endpoint)).status, 404);
            assert.equal((await second.client.request(endpoint, { reason: 'spam', csrfToken: 'wrong' })).status, 403);
            assert.equal((await second.client.request(endpoint, { reason: '', csrfToken: second.token })).status, 422);
            redirected(await second.client.request(endpoint, { reason: 'Spam <img src=x onerror=alert(1)>', csrfToken: second.token }));
            redirected(await second.client.request(endpoint, { reason: 'Ten sam wpis', csrfToken: second.token }));
            moderatorRole(moderator, true); promoted = true;
            const queue = await moderator.client.request('/moderation');
            assert.ok(!queue.html.includes(escape(snapshot)), 'Reported private fragments are excluded from moderation');
            assert.ok(!queue.html.includes(marker + ' pierwsza wiadomość') && !queue.html.includes(marker + ' druga wiadomość'));
            assert.doesNotMatch(queue.html, /<script>alert\(1\)<\/script>|<img src=x/);
            assert.equal((await moderator.client.request(route)).status, 404, 'Moderator role does not reveal the whole conversation');
            const history = await second.client.request(route);
            assert.ok(history.html.includes(escape(snapshot)), 'Moderation does not hide private messages');
            assert.ok((await second.client.request('/messages')).html.includes(escape(snapshot)));
        });

        await t.test('comment reports support dismissal, hiding and author cleanup without restoring hidden text', async () => {
            const body = marker + ' komentarz publiczny <b>tekst</b>';
            const id = await comment(first, body);
            assert.equal((await first.client.request('/reports/comment/' + id)).status, 404);
            redirected(await second.client.request('/reports/comment/' + id, { reason: 'Do sprawdzenia', csrfToken: second.token }));
            let report = await reportId(body);
            redirected(await moderator.client.request('/moderation/reports/' + report + '/resolve', { action: 'dismiss', reason: 'Zgłoszenie niezasadne', requestKey: randomUUID().replaceAll('-', ''), csrfToken: moderator.token }));
            assert.ok((await guest.request(animeRoute)).html.includes(escape(body)));
            redirected(await third.client.request('/reports/comment/' + id, { reason: 'Spam', csrfToken: third.token }));
            report = await reportId(body);
            redirected(await moderator.client.request('/moderation/reports/' + report + '/resolve', { action: 'remove', reason: 'Spam', requestKey: randomUUID().replaceAll('-', ''), csrfToken: moderator.token }));
            assert.ok(!(await guest.request(animeRoute)).html.includes(escape(body)));
            assert.equal((await first.client.request(animeRoute + '?editComment=' + id)).status, 404);
            assert.equal((await first.client.request(animeRoute + '/comments/' + id + '/edit', { body: 'Przywróć', csrfToken: first.token })).status, 404);
            redirected(await first.client.request(animeRoute + '/comments/' + id + '/delete', { csrfToken: first.token }));
        });

        await t.test('duplicate messages and comment bursts receive 429 with Retry-After', async () => {
            const duplicate = marker + ' identyczna treść';
            await send(first, route, duplicate);
            const repeated = await first.client.request(route, { body: duplicate, csrfToken: first.token });
            assert.equal(repeated.status, 429);
            assert.ok(Number(repeated.headers.get('retry-after')) >= 1);
            assert.ok(repeated.html.includes(escape(duplicate)));
            for (let index = 0; index < 15; index++) await comment(third, marker + ' seria komentarzy ' + index);
            const limited = await third.client.request(animeRoute + '/comments', { body: marker + ' szesnasty', csrfToken: third.token });
            assert.equal(limited.status, 429);
            assert.ok(Number(limited.headers.get('retry-after')) >= 1);
        });
    } finally {
        try {
            for (const entry of createdComments) {
                const response = await entry.owner.client.request(animeRoute + '/comments/' + entry.id + '/delete', { csrfToken: entry.owner.token });
                assert.ok([303, 404].includes(response.status), 'Only comments created by this test are removed');
            }
        } finally {
            if (promoted) {
                try {
                    const queue = await moderator.client.request('/moderation');
                    for (const report of entries(queue, 'report').filter(item => item.html.includes(marker))) {
                        redirected(await moderator.client.request('/moderation/reports/' + report.id + '/resolve', { action: 'dismiss', reason: 'Porządkowanie własnych danych testowych', requestKey: randomUUID().replaceAll('-', ''), csrfToken: moderator.token }));
                    }
                } finally {
                    moderatorRole(moderator, false);
                }
            }
        }
    }
});
