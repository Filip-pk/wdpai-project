const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const base = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Reakcje-Komentarzy-2026!';
const missingId = Number.MAX_SAFE_INTEGER;
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');

function client() {
    let cookie = '';
    return {
        async request(route, fields) {
            const response = await fetch(new URL(route, base), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: cookie ? { Cookie: cookie } : {},
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual', signal: AbortSignal.timeout(10000),
            });
            for (const value of response.headers.getSetCookie()) {
                if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
            }
            const html = await response.text();
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
            return { html, status: response.status, location: response.headers.get('location') };
        },
    };
}

function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match, 'The page exposes a scalar CSRF token');
    return match[1];
}

function redirected(page) {
    assert.equal(page.status, 303);
    const target = new URL(page.location, base);
    assert.equal(target.origin, new URL(base).origin, 'Writes redirect only within AniVibe');
    return target;
}

function items(page) {
    assert.equal(page.status, 200);
    return [...page.html.matchAll(/<article\b[^>]*data-comment-id="(\d+)"[^>]*>[\s\S]*?<\/article>/g)]
        .map(match => ({ id: Number(match[1]), html: match[0] }));
}

function counter(html, name) {
    const attribute = html.match(new RegExp('data-comment-' + name + '="(\\d+)"'));
    if (attribute) return Number(attribute[1]);
    const text = html.match(new RegExp('data-comment-' + name + '(?:="")?[^>]*>\\s*(\\d+)\\s*<'));
    assert.ok(text, 'Each public card exposes its ' + name + ' count');
    return Number(text[1]);
}

function reactionButton(html, kind, pressed, value) {
    const button = html.match(new RegExp('<button\\b(?=[^>]*data-comment-reaction="' + kind + '")[^>]*>'));
    assert.ok(button, 'The visitor can choose ' + kind);
    assert.match(button[0], new RegExp('aria-pressed="' + String(pressed) + '"'));
    assert.match(button[0], new RegExp('value="' + String(value) + '"'));
    assert.match(button[0], /name="value"/);
}

async function account(name) {
    const http = client(), email = 'reactions-' + randomUUID() + '@example.test';
    const token = csrf(await http.request('/register'));
    redirected(await http.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }));
    redirected(await http.request('/login', { email, password, csrfToken: token }));
    const page = await http.request('/profile');
    return { http, email, token: csrf(page), id: Number(page.html.match(/data-account-id="(\d+)"/)[1]) };
}

test('comment reactions are public counts with one private choice per signed-in voter', async t => {
    const guest = client();
    const author = await account('Autor ocenianego komentarza');
    const second = await account('Drugi użytkownik reakcji');
    const third = await account('Trzeci użytkownik reakcji');
    const marker = randomUUID(), owned = [];
    const catalog = await guest.request('/anime');
    const animeIds = [...catalog.html.matchAll(/data-anime-id="(\d+)"/g)].map(match => match[1]);
    assert.ok(animeIds.length >= 2);
    const route = '/anime/' + animeIds[0], otherRoute = '/anime/' + animeIds[1];
    const baseline = Number((await guest.request(route)).html.match(/data-comments-total="(\d+)"/)[1]);
    let rootId, replyId, thread;
    const rootPage = route + '?commentsPage=2';
    const endpoint = id => route + '/comments/' + id + '/reaction';
    const vote = (user, id, value, fields = {}) => user.http.request(endpoint(id), { value: String(value), csrfToken: user.token, ...fields });
    const card = async (http, location, id, likes, dislikes) => {
        const entry = items(await http.request(location)).find(item => item.id === id);
        assert.ok(entry, 'The requested comment appears on its expected page');
        assert.equal(counter(entry.html, 'likes'), likes);
        assert.equal(counter(entry.html, 'dislikes'), dislikes);
        return entry.html;
    };

    try {
        for (let index = 0; index < 11; index++) {
            const body = marker + ' komentarz główny ' + index;
            redirected(await author.http.request(route + '/comments', { body, csrfToken: author.token }));
            const saved = items(await author.http.request(route)).find(item => item.html.includes(escape(body)));
            assert.ok(saved);
            owned.push({ id: saved.id, user: author });
            if (index === 0) rootId = saved.id;
        }
        thread = route + '/comments/' + rootId;
        for (let index = 0; index < 11; index++) {
            const saved = await second.http.request(thread + '/replies', { body: marker + ' odpowiedź ' + index, csrfToken: second.token });
            const target = redirected(saved);
            const match = target.hash.match(/^#comment-(\d+)$/);
            assert.ok(match);
            replyId = Number(match[1]); owned.push({ id: replyId, user: second });
        }
        const replyPage = thread + '?commentsPage=2&repliesPage=2';

        await t.test('public counts are readable but voting requires login and a valid scalar CSRF token', async () => {
            const publicCard = await card(guest, rootPage, rootId, 0, 0);
            assert.doesNotMatch(publicCard, /class="[^"]*comment-reaction-form/);
            assert.equal(redirected(await guest.request(endpoint(rootId), { value: '1' })).pathname, '/login');
            for (const fields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': second.token }]) {
                assert.equal((await second.http.request(endpoint(rootId), { value: '1', ...fields })).status, 403);
            }
            await card(guest, rootPage, rootId, 0, 0);
        });

        await t.test('unsupported values and inaccessible targets never create reactions', async () => {
            for (const fields of [{}, { value: '' }, { value: 'like' }, { value: '2' }, { value: '-2' }, { value: '1.5' }, { 'value[]': '1' }]) {
                assert.equal((await second.http.request(endpoint(rootId), { ...fields, csrfToken: second.token })).status, 422);
            }
            assert.equal((await vote(author, rootId, 1)).status, 404, 'The author cannot react to their own comment');
            assert.equal((await vote(second, replyId, -1)).status, 404, 'The author cannot react to their own reply');
            assert.equal((await vote(second, missingId, 1)).status, 404);
            assert.equal((await second.http.request(otherRoute + '/comments/' + rootId + '/reaction', { value: '1', csrfToken: second.token })).status, 404);
            await card(guest, rootPage, rootId, 0, 0);
        });

        await t.test('the session voter owns the reaction and its active button submits removal', async () => {
            redirected(await vote(second, rootId, 1, { userId: author.id, user_id: third.id }));
            await card(guest, rootPage, rootId, 1, 0);
            const selected = await card(second.http, rootPage, rootId, 1, 0);
            reactionButton(selected, 'like', true, 0); reactionButton(selected, 'dislike', false, -1);
            const unselected = await card(third.http, rootPage, rootId, 1, 0);
            reactionButton(unselected, 'like', false, 1); reactionButton(unselected, 'dislike', false, -1);
            assert.doesNotMatch(await card(author.http, rootPage, rootId, 1, 0), /class="[^"]*comment-reaction-form/);
        });

        await t.test('retries are idempotent and switching between choices never counts both for one voter', async () => {
            redirected(await vote(second, rootId, 1)); redirected(await vote(second, rootId, 1));
            await card(guest, rootPage, rootId, 1, 0);
            redirected(await vote(second, rootId, -1)); redirected(await vote(second, rootId, -1));
            const disliked = await card(second.http, rootPage, rootId, 0, 1);
            reactionButton(disliked, 'like', false, 1); reactionButton(disliked, 'dislike', true, 0);
            redirected(await vote(second, rootId, 0)); redirected(await vote(second, rootId, 0));
            await card(guest, rootPage, rootId, 0, 0);
        });

        await t.test('different voters remain independent and a saved choice survives a fresh session', async () => {
            redirected(await vote(second, rootId, 1)); redirected(await vote(third, rootId, -1));
            await card(guest, rootPage, rootId, 1, 1);
            const fresh = client(), token = csrf(await fresh.request('/login'));
            redirected(await fresh.request('/login', { email: second.email, password, csrfToken: token }));
            second.http = fresh; second.token = csrf(await fresh.request('/profile'));
            reactionButton(await card(second.http, rootPage, rootId, 1, 1), 'like', true, 0);
            redirected(await vote(second, rootId, 0));
            reactionButton(await card(third.http, rootPage, rootId, 0, 1), 'dislike', true, 0);
        });

        await t.test('reaction forms and redirects preserve real comment and reply pagination', async () => {
            const main = await card(second.http, rootPage, rootId, 0, 1);
            assert.match(main, /name="commentsPage"\s+value="2"/);
            const rootRedirect = redirected(await vote(second, rootId, 1, { commentsPage: '2' }));
            assert.equal(rootRedirect.pathname, route); assert.equal(rootRedirect.searchParams.get('commentsPage'), '2');
            assert.equal(rootRedirect.hash, '#comment-' + rootId);
            const threadCard = await card(author.http, replyPage, replyId, 0, 0);
            assert.match(threadCard, /name="commentsPage"\s+value="2"/);
            assert.match(threadCard, /name="repliesPage"\s+value="2"/);
            assert.match(threadCard, new RegExp('name="returnThread"\\s+value="' + rootId + '"'));
            const replyRedirect = redirected(await vote(author, replyId, 1, { commentsPage: '2', repliesPage: '2', returnThread: String(missingId) }));
            assert.equal(replyRedirect.pathname, thread, 'Reply routing derives its actual parent instead of trusting returnThread');
            assert.equal(replyRedirect.searchParams.get('commentsPage'), '2'); assert.equal(replyRedirect.searchParams.get('repliesPage'), '2');
            assert.equal(replyRedirect.hash, '#comment-' + replyId);
            const rootThreadRedirect = redirected(await vote(second, rootId, 1, { commentsPage: '2', repliesPage: '2', returnThread: String(rootId) }));
            assert.equal(rootThreadRedirect.pathname, thread); assert.equal(rootThreadRedirect.searchParams.get('repliesPage'), '2');
        });

        await t.test('replies have independent counts and remain reactable after the root becomes a tombstone', async () => {
            redirected(await vote(third, replyId, -1, { repliesPage: '2' }));
            await card(guest, replyPage, replyId, 1, 1);
            reactionButton(await card(author.http, replyPage, replyId, 1, 1), 'like', true, 0);
            assert.doesNotMatch(await card(second.http, replyPage, replyId, 1, 1), /class="[^"]*comment-reaction-form/);
            await card(guest, rootPage, rootId, 1, 1);
            redirected(await author.http.request(route + '/comments/' + rootId + '/delete', { csrfToken: author.token }));
            const tombstone = items(await guest.request(replyPage)).find(item => item.id === rootId);
            assert.ok(tombstone); assert.ok(!tombstone.html.includes(marker + ' komentarz główny 0'));
            assert.doesNotMatch(tombstone.html, /class="[^"]*comment-reaction-form/);
            assert.equal((await vote(second, rootId, 1)).status, 404); assert.equal((await vote(third, rootId, 0)).status, 404);
            redirected(await vote(third, replyId, 1, { repliesPage: '2' }));
            redirected(await vote(author, replyId, 0, { repliesPage: '2' }));
            await card(guest, replyPage, replyId, 1, 0);
        });

        await t.test('a removed reply cannot receive a stale reaction', async () => {
            redirected(await second.http.request(route + '/comments/' + replyId + '/delete', { csrfToken: second.token }));
            assert.equal((await vote(author, replyId, 1)).status, 404);
            assert.ok(!items(await guest.request(thread + '?repliesPage=2')).some(item => item.id === replyId));
        });
    } finally {
        for (const entry of owned.slice().reverse()) {
            const result = await entry.user.http.request(route + '/comments/' + entry.id + '/delete', { csrfToken: entry.user.token });
            assert.ok([303, 404].includes(result.status), 'Cleanup removes only this test account own comments, children first');
        }
        assert.equal(Number((await guest.request(route)).html.match(/data-comments-total="(\d+)"/)[1]), baseline);
    }
});
