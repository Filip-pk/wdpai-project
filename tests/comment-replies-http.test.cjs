const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const test = require('node:test');

const base = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
function client() {
    let cookie = '';
    return { async request(route, fields, json = false) {
        const response = await fetch(new URL(route, base), {
            method: fields === undefined ? 'GET' : 'POST', headers: { ...(cookie ? { Cookie: cookie } : {}), ...(json ? { Accept: 'application/json' } : {}) },
            body: fields === undefined ? undefined : new URLSearchParams(fields), redirect: 'manual', signal: AbortSignal.timeout(10000),
        });
        for (const value of response.headers.getSetCookie()) if (value.startsWith('PHPSESSID=')) cookie = value.split(';')[0];
        const html = await response.text();
        assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
        return { html, status: response.status, location: response.headers.get('location'), data: json && response.headers.get('content-type')?.includes('application/json') ? JSON.parse(html) : null };
    } };
}
function csrf(page) { const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/); assert.ok(match); return match[1]; }
function items(page) { return [...page.html.matchAll(/<article\b[^>]*data-comment-id="(\d+)"[^>]*>[\s\S]*?<\/article>/g)].map(item => ({ id: Number(item[1]), html: item[0] })); }
function redirected(page) { assert.equal(page.status, 303); }
async function account(label) {
    const http = client(), email = 'replies-' + randomUUID() + '@example.test', password = 'Odpowiedzi-Testowe-2026!';
    const token = csrf(await http.request('/register'));
    redirected(await http.request('/register', { name: label, email, password, passwordConfirmation: password, csrfToken: token }));
    redirected(await http.request('/login', { email, password, csrfToken: token }));
    const profile = await http.request('/profile');
    return { http, token: csrf(profile), id: Number(profile.html.match(/data-account-id="(\d+)"/)[1]) };
}
function moderatorRole(user, enabled) {
    execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', 'scripts/set-moderator.php', String(user.id), ...(enabled ? [] : ['--revoke'])], {
        cwd: path.join(__dirname, '..'), windowsHide: true, stdio: 'pipe', timeout: 15000,
    });
}

test('comment threads preserve author controls, pagination, moderation and private reply notifications', async t => {
    const guest = client(), first = await account('Autor wątku'), second = await account('Odpowiadający <b>Łucja</b>'), third = await account('Osoba spoza powiadomień');
    const marker = randomUUID(), owned = [];
    const catalog = await guest.request('/anime');
    const animeIds = [...catalog.html.matchAll(/data-anime-id="(\d+)"/g)].map(item => item[1]);
    const route = '/anime/' + animeIds[0], otherRoute = '/anime/' + animeIds[1];
    const baseline = Number((await guest.request(route)).html.match(/data-comments-total="(\d+)"/)[1]);
    let rootId, thread, firstReply, lastReply, promoted = false;
    const unread = async user => (await user.http.request('/notifications', undefined, true)).data.unreadReplyCount;
    const reply = async (user, body) => {
        const saved = await user.http.request(thread + '/replies', { body, csrfToken: user.token, userId: first.id });
        redirected(saved);
        const id = Number(saved.location.match(/#comment-(\d+)$/)[1]);
        owned.push({ id, user });
        return id;
    };
    try {
        redirected(await first.http.request(route + '/comments', { body: marker + ' komentarz główny', csrfToken: first.token }));
        rootId = items(await guest.request(route)).find(item => item.html.includes(marker)).id;
        owned.push({ id: rootId, user: first }); thread = route + '/comments/' + rootId;
        await t.test('public threads allow reading while writes and notifications require login', async () => {
            assert.equal((await guest.request(thread)).status, 200);
            redirected(await guest.request(thread + '/replies', { body: 'Gość' }));
            assert.equal((await guest.request('/notifications/comments')).location, '/login');
            assert.equal((await guest.request(otherRoute + '/comments/' + rootId)).status, 404);
            assert.equal((await guest.request(route + '/comments/9007199254740991')).status, 404);
            for (const token of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': second.token }]) {
                assert.equal((await second.http.request(thread + '/replies', { body: 'Treść', ...token })).status, 403);
            }
            for (const fields of [{ body: '' }, { body: ' \n\t' }, { body: 'ą'.repeat(2001) }, { 'body[]': 'array' }, { body: 'Bad\0' }]) {
                assert.equal((await second.http.request(thread + '/replies', { ...fields, csrfToken: second.token })).status, 422);
            }
            assert.equal(await unread(first), 0);
        });
        await t.test('replies escape HTML, belong to the session account and notify only the root author', async () => {
            const body = marker + ' odpowiedź <script>alert(1)</script>';
            firstReply = await reply(second, body);
            const page = await guest.request(thread);
            const saved = items(page).find(item => item.id === firstReply);
            assert.ok(saved.html.includes(escape(body)));
            assert.doesNotMatch(saved.html, /<script>alert\(1\)<\/script>|<b>Łucja<\/b>/);
            assert.ok(saved.html.includes('href="/users/' + second.id + '"'));
            assert.equal(await unread(first), 1); assert.equal(await unread(second), 0);
            await reply(first, marker + ' własna odpowiedź');
            assert.equal(await unread(first), 1, 'Self replies do not create notifications');
            const main = await guest.request(route);
            assert.equal(Number(main.html.match(/data-comments-total="(\d+)"/)[1]), baseline + 1);
            assert.ok(!items(main).some(item => item.id === firstReply), 'Only root comments appear in main pagination');
            assert.equal((await second.http.request(route + '/comments/' + firstReply + '/replies', { body: 'Zagnieżdżenie', csrfToken: second.token })).status, 404);
            assert.equal((await second.http.request(otherRoute + '/comments/' + rootId + '/replies', { body: 'Inne anime', csrfToken: second.token })).status, 404);
        });
        await t.test('only the author edits a reply and errors retain the thread and typed body', async () => {
            assert.equal((await first.http.request(route + '/comments/' + firstReply + '/edit', { body: 'Cudza zmiana', csrfToken: first.token })).status, 404);
            const editPage = await second.http.request(thread + '?editComment=' + firstReply);
            assert.equal(editPage.status, 200);
            assert.ok(editPage.html.includes('/comments/' + firstReply + '/edit'));
            const invalid = await second.http.request(route + '/comments/' + firstReply + '/edit', { body: 'x'.repeat(2001), csrfToken: second.token });
            assert.equal(invalid.status, 422); assert.ok(invalid.html.includes('x'.repeat(2001)));
            const edited = await second.http.request(route + '/comments/' + firstReply + '/edit', { body: marker + ' poprawiona odpowiedź', csrfToken: second.token, returnThread: '9007199254740991' });
            redirected(edited); assert.ok(edited.location.startsWith(thread + '?'));
            assert.equal(await unread(first), 1, 'Editing never creates a duplicate notification');
        });
        await t.test('reply pagination is chronological and independent of the root list', async () => {
            for (let i = 0; i < 11; i++) await reply(second, marker + ' kolejna odpowiedź ' + i);
            const firstPage = await guest.request(thread), secondPage = await guest.request(thread + '?repliesPage=2');
            assert.equal(items(firstPage).length, 11, 'Root plus ten replies');
            assert.equal(items(secondPage).length, 4, 'Root plus three replies');
            const ids = [...items(firstPage).slice(1), ...items(secondPage).slice(1)].map(item => item.id);
            assert.deepEqual(ids, [...ids].sort((a, b) => a - b));
            assert.equal(new Set(ids).size, 13);
            assert.equal(await unread(first), 12);
        });
        await t.test('notification reads are private, CSRF protected and bounded by the shown snapshot', async () => {
            const page = await first.http.request('/notifications/comments');
            assert.equal(page.status, 200); assert.equal(await unread(first), 12, 'GET leaves notifications unread');
            const snapshot = page.html.match(/name="lastNotificationId"\s+value="(\d+)"/)[1];
            lastReply = await reply(second, marker + ' późniejsza odpowiedź');
            const laterPage = await first.http.request('/notifications/comments');
            const notification = [...laterPage.html.matchAll(/action="\/notifications\/comments\/(\d+)\/read"/g)].map(item => Number(item[1])).sort((a, b) => b - a)[0];
            assert.ok(notification);
            assert.equal((await third.http.request('/notifications/comments/' + notification + '/read', { csrfToken: third.token })).status, 404);
            assert.equal((await first.http.request('/notifications/comments/read-all', { lastNotificationId: snapshot, csrfToken: 'wrong' })).status, 403);
            redirected(await first.http.request('/notifications/comments/read-all', { lastNotificationId: snapshot, csrfToken: first.token }));
            assert.equal(await unread(first), 1, 'A new reply arriving after GET remains unread');
            const opened = await first.http.request('/notifications/comments/' + notification + '/read', { csrfToken: first.token });
            redirected(opened); assert.ok(opened.location.includes('/comments/' + rootId) && opened.location.includes('repliesPage=2') && opened.location.endsWith('#comment-' + lastReply));
            assert.equal(await unread(first), 0);
        });
        await t.test('reply reports return to the correct thread and moderation hides only the selected reply', async () => {
            const rootReport = await second.http.request('/reports/comment/' + rootId + '?page=2&thread=' + rootId + '&commentsPage=2&repliesPage=2');
            assert.equal(rootReport.status, 200);
            assert.ok(rootReport.html.includes('name="returnThread" value="' + rootId + '"'));
            assert.ok(rootReport.html.includes(thread + '?repliesPage=2&amp;commentsPage=2#comment-' + rootId));
            const report = await first.http.request('/reports/comment/' + lastReply, { reason: marker + ' spam', returnPage: '2', csrfToken: first.token });
            redirected(report); assert.ok(report.location.startsWith(thread + '?repliesPage=2'));
            moderatorRole(third, true); promoted = true;
            const queue = await third.http.request('/moderation');
            const entry = [...queue.html.matchAll(/<article\b[^>]*data-report-id="(\d+)"[^>]*>[\s\S]*?<\/article>/g)].find(item => item[0].includes(marker));
            assert.ok(entry);
            redirected(await third.http.request('/moderation/reports/' + entry[1] + '/resolve', { action: 'remove', csrfToken: third.token }));
            const hidden = items(await guest.request(thread + '?repliesPage=2')).find(item => item.id === lastReply);
            assert.ok(hidden.html.includes('ukryty przez moderację')); assert.ok(!hidden.html.includes(marker + ' późniejsza'));
        });
        await t.test('deleting a root preserves other authors replies and closes the reply form', async () => {
            redirected(await first.http.request(route + '/comments/' + rootId + '/delete', { csrfToken: first.token }));
            const page = await guest.request(thread);
            assert.equal(page.status, 200);
            assert.ok(!page.html.includes(marker + ' komentarz główny'));
            assert.ok(items(page).some(item => item.id === firstReply));
            assert.equal((await second.http.request(thread + '/replies', { body: marker + ' po usunięciu', csrfToken: second.token })).status, 404);
            assert.equal((await first.http.request(thread + '?editComment=' + rootId)).status, 404);
            assert.equal((await second.http.request('/reports/comment/' + rootId)).status, 404);
        });
    } finally {
        try {
            for (const entry of owned.slice().reverse()) assert.ok([303, 404].includes((await entry.user.http.request(route + '/comments/' + entry.id + '/delete', { csrfToken: entry.user.token })).status));
            assert.equal(Number((await guest.request(route)).html.match(/data-comments-total="(\d+)"/)[1]), baseline);
        } finally {
            if (promoted) moderatorRole(third, false);
        }
    }
});
