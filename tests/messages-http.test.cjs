const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Wiadomosci-Testowe-2026!';
const missingId = Number.MAX_SAFE_INTEGER;
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');

function browser() {
    let cookie = '';
    return {
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
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
            return { status: response.status, headers: response.headers, html };
        },
    };
}

function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match, 'The form contains a CSRF token');
    return match[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

function conversationLocation(page) {
    assert.equal(page.status, 303);
    const location = page.headers.get('location');
    const match = location?.match(/^\/messages\/(\d+)(?:\?sent=1)?(?:#message-(?:history|\d+))?$/);
    assert.ok(match, 'A successful write redirects to its private conversation');
    return '/messages/' + match[1];
}

function messages(page) {
    return [...page.html.matchAll(/<article\b[^>]*data-message-id="(\d+)"[^>]*data-message-sender-id="(\d+)"[^>]*>[\s\S]*?<\/article>/g)]
        .map(match => ({ id: Number(match[1]), senderId: Number(match[2]), html: match[0] }));
}

function messagePaging(page) {
    assert.equal(page.status, 200);
    return Object.fromEntries(['page', 'total', 'total-pages'].map(name => {
        const match = page.html.match(new RegExp('data-message-' + name + '="(\\d+)"'));
        assert.ok(match, 'The message history exposes ' + name);
        return [name, Number(match[1])];
    }));
}

function conversations(page) {
    assert.equal(page.status, 200);
    return [...page.html.matchAll(/data-nav-conversation-id="(\d+)"/g)].map(match => Number(match[1]));
}

async function account(name) {
    const client = browser();
    const email = `messages-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    const id = Number(profile.html.match(/data-account-id="(\d+)"/)[1]);
    return { client, email, name, id, token: csrf(profile) };
}

test('private text messages belong to one conversation per pair and are available only to participants', async (t) => {
    const guest = browser();
    const first = await account('Nadawca <svg onload=alert(1)> & Łucja');
    const second = await account('Odbiorca wiadomości');
    const outsider = await account('Poza rozmową');
    const draft = '/messages/new/' + second.id;
    const initial = 'Prywatna treść ' + randomUUID() + '\n<script>alert(1)</script> & <img src=x onerror=alert(1)>';
    let route;

    await t.test('guests cannot read drafts, inboxes or histories, or send any message', async () => {
        for (const path of ['/messages', draft, '/messages/1']) redirected(await guest.request(path), '/login');
        for (const path of [draft, '/messages/1']) redirected(await guest.request(path, { body: 'Gość' }), '/login');
        assert.deepEqual(conversations(await first.client.request('/messages')), []);
        assert.equal((await first.client.request(draft)).status, 200);
        assert.deepEqual(conversations(await first.client.request('/messages')), [], 'Opening a draft does not create a conversation');
        for (const recipient of [first.id, missingId]) {
            assert.equal((await first.client.request('/messages/new/' + recipient)).status, 404);
            assert.equal((await first.client.request('/messages/new/' + recipient, { body: 'Tekst', csrfToken: first.token })).status, 404);
        }
    });

    await t.test('CSRF and body validation protect starting a private conversation', async () => {
        for (const fields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
            assert.equal((await first.client.request(draft, { body: 'Tekst', ...fields })).status, 403);
        }
        for (const fields of [{ body: '' }, { body: ' \n\t ' }, { body: 'ż'.repeat(4001) }, { 'body[]': 'tablica' }, { body: '\0Tekst' }]) {
            const response = await first.client.request(draft, { ...fields, csrfToken: first.token });
            assert.equal(response.status, 422);
        }
        assert.deepEqual(conversations(await first.client.request('/messages')), []);
    });

    await t.test('participants see escaped text and identities while the third account sees no private data', async () => {
        route = conversationLocation(await first.client.request(draft, { body: initial, csrfToken: first.token, senderId: outsider.id, userId: outsider.id }));
        for (const user of [first, second]) {
            const page = await user.client.request(route);
            assert.equal(page.status, 200);
            assert.match(page.headers.get('cache-control'), /no-store/);
            assert.ok(messages(page)[0].html.includes(escape(initial)));
            assert.equal(messages(page)[0].senderId, first.id);
            assert.doesNotMatch(page.html, /<svg onload=|<script>alert\(1\)<\/script>|<img src=x/);
            assert.ok(!page.html.includes(first.email) && !page.html.includes(second.email));
            assert.ok(conversations(await user.client.request('/messages')).includes(Number(route.split('/').at(-1))));
        }
        assert.ok((await second.client.request(route)).html.includes(escape(first.name)));
        assert.equal((await outsider.client.request(route)).status, 404);
        assert.equal((await outsider.client.request(route, { body: 'Wstrzyknięcie', csrfToken: outsider.token, senderId: first.id, userId: first.id })).status, 404);
        const inbox = await outsider.client.request('/messages');
        assert.deepEqual(conversations(inbox), []);
        assert.ok(!inbox.html.includes(escape(initial)));
        assert.equal((await first.client.request('/messages/' + missingId)).status, 404);
    });

    await t.test('starting from either direction reuses the same conversation and its sender remains the session account', async () => {
        redirected(await first.client.request(draft), route);
        redirected(await second.client.request('/messages/new/' + first.id), route);
        const reused = conversationLocation(await second.client.request('/messages/new/' + first.id, {
            body: 'Odpowiedź\r\nDruga linia', csrfToken: second.token, sender_id: first.id, user_id: first.id,
        }));
        assert.equal(reused, route);
        const entries = messages(await first.client.request(route));
        assert.equal(entries.length, 2);
        assert.equal(entries[1].senderId, second.id);
        assert.ok(entries[1].html.includes('Odpowiedź\nDruga linia'));
        assert.deepEqual(conversations(await first.client.request('/messages')), [Number(route.split('/').at(-1))]);
    });

    await t.test('all sends validate CSRF and text while preserving the input after errors', async () => {
        for (const fields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
            assert.equal((await first.client.request(route, { body: 'Zmiana', ...fields })).status, 403);
        }
        for (const fields of [{ body: '' }, { body: ' \n\t ' }, { body: 'ż'.repeat(4001) }, { 'body[]': 'tablica' }, { body: 'Tekst\0' }]) {
            assert.equal((await first.client.request(route, { ...fields, csrfToken: first.token })).status, 422);
        }
        const tooLong = '<b>' + 'ż'.repeat(4001);
        assert.ok((await first.client.request(route, { body: tooLong, csrfToken: first.token })).html.includes(escape(tooLong)));
        assert.equal(messagePaging(await first.client.request(route)).total, 2);
    });

    await t.test('history is chronological and paginated into thirty messages with the latest page as default', async () => {
        for (let index = 0; index < 29; index++) {
            const body = index === 0 ? 'ż'.repeat(4000) : index === 1 ? 'Ź' : 'Wiadomość ' + index;
            assert.equal(conversationLocation(await first.client.request(route, { body, csrfToken: first.token, conversationId: missingId, recipientId: outsider.id })), route);
        }
        const latest = await second.client.request(route);
        assert.deepEqual(messagePaging(latest), { page: 2, total: 31, 'total-pages': 2 });
        assert.equal(messages(latest).length, 1);
        const oldest = await first.client.request(route + '?page=1');
        assert.equal(messages(oldest).length, 30);
        assert.ok(messages(oldest)[2].html.includes('ż'.repeat(4000)));
        const ids = [...messages(oldest), ...messages(latest)].map(entry => entry.id);
        assert.deepEqual(ids, [...ids].sort((a, b) => a - b));
        assert.equal(new Set(ids).size, 31);
        assert.equal((await first.client.request(route + '?page[]=1')).status, 200);
        assert.equal(messagePaging(await first.client.request(route + '?page=999999')).page, 2);
    });

    await t.test('private history survives logout and a fresh authenticated session', async () => {
        redirected(await first.client.request('/logout', { csrfToken: first.token }), '/login');
        redirected(await first.client.request(route), '/login');
        const token = csrf(await first.client.request('/login'));
        redirected(await first.client.request('/login', { email: first.email, password, csrfToken: token }), '/projects');
        const history = await first.client.request(route + '?page=1');
        assert.equal(messagePaging(history).total, 31);
        assert.ok(messages(history)[0].html.includes(escape(initial)));
        assert.equal((await outsider.client.request(route)).status, 404);
    });
});
