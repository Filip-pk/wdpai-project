const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Privacy-Settings-2026!';

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
    const token = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(token, 'CSRF token is rendered');
    return token[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

async function account(name) {
    const client = browser();
    const email = `privacy-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    const id = Number(profile.html.match(/data-account-id="(\d+)"/)[1]);
    return { client, email, id, token: csrf(profile) };
}

function privacyFields(user, overrides = {}) {
    return { csrfToken: user.token, messagePolicy: 'everyone', publicRatings: '1', publicStatistics: '1', ...overrides };
}

async function savePrivacy(user, overrides) {
    redirected(await user.client.request('/settings/privacy', privacyFields(user, overrides)), '/settings#privacy');
}

test('privacy is account scoped and enforced on message writes and public profile responses', async (t) => {
    const guest = browser();
    const sender = await account('Prywatność — nadawca');
    const receiver = await account('Prywatność — odbiorca');
    const outsider = await account('Prywatność — trzecie konto');
    const draft = '/messages/new/' + receiver.id;
    let conversation;

    await t.test('anonymous writes, invalid policies, CSRF and forged owners cannot change privacy', async () => {
        assert.match((await receiver.client.request('/settings')).html, /id="public-ratings"[\s\S]*?value="0" selected/);
        redirected(await guest.request('/settings/privacy', privacyFields(receiver)), '/login');
        for (const invalid of [
            { csrfToken: 'wrong', messagePolicy: 'nobody' },
            { messagePolicy: 'invalid' }, { publicRatings: 'yes' },
            { messagePolicy: undefined, 'messagePolicy[]': 'nobody' },
        ]) {
            const fields = privacyFields(receiver, invalid);
            if (fields.messagePolicy === undefined) delete fields.messagePolicy;
            redirected(await receiver.client.request('/settings/privacy', fields), '/settings#privacy');
            const settings = await receiver.client.request('/settings');
            assert.match(settings.html, /role="alert"/);
        }
        await savePrivacy(outsider, { messagePolicy: 'nobody', userId: receiver.id, user_id: receiver.id });
        const page = await sender.client.request(draft);
        assert.match(page.html, /name="body"/);
        assert.equal(page.status, 200);
    });

    await t.test('everyone permits a new conversation; existing permits only an already persisted pair', async () => {
        const sent = await sender.client.request(draft, { csrfToken: sender.token, body: 'Początek ' + randomUUID() });
        assert.equal(sent.status, 303);
        conversation = sent.headers.get('location').match(/^\/messages\/\d+/)[0];
        await savePrivacy(receiver, { messagePolicy: 'existing' });
        assert.equal((await sender.client.request(conversation, { csrfToken: sender.token, body: 'Kontynuacja ' + randomUUID() })).status, 303);
        const forbidden = await outsider.client.request(draft, { csrfToken: outsider.token, body: 'Nowa para ' + randomUUID() });
        assert.equal(forbidden.status, 403);
        assert.match(forbidden.html, /Wysyłanie wiadomości do tego konta jest niedostępne/);
        assert.doesNotMatch((await outsider.client.request(draft)).html, /name="body"/);
        assert.doesNotMatch((await outsider.client.request('/messages')).html, /data-nav-conversation-id=/);
    });

    await t.test('nobody blocks compose and direct replies while history and outgoing messages remain available', async () => {
        await savePrivacy(receiver, { messagePolicy: 'nobody' });
        for (const route of [draft, conversation]) {
            const denied = await sender.client.request(route, { csrfToken: sender.token, body: 'Zablokowana ' + randomUUID() });
            assert.equal(denied.status, 403);
            assert.doesNotMatch(denied.html, /name="body"/);
        }
        const history = await sender.client.request(conversation);
        assert.equal(history.status, 200);
        assert.match(history.html, /Początek/);
        assert.doesNotMatch(history.html, /name="body"/);
        assert.equal((await receiver.client.request(conversation, { csrfToken: receiver.token, body: 'Wychodząca ' + randomUUID() })).status, 303);
        assert.equal((await outsider.client.request(conversation)).status, 404);
    });

    await t.test('visibility removes personal ratings, aggregate statistics and EXP from server-rendered public profiles', async () => {
        const catalog = await guest.request('/anime?episodes_min=2');
        const animeId = catalog.html.match(/<article\b[^>]*data-anime-id="(\d+)"/)[1];
        assert.equal((await receiver.client.request('/watchlist', {
            csrfToken: receiver.token, animeId, status: 'watching', watched: '1',
        })).status, 303);
        assert.equal((await receiver.client.request('/anime/' + animeId + '/rating', {
            csrfToken: receiver.token, rating: '9',
        })).status, 303);
        const route = '/users/' + receiver.id;
        await savePrivacy(receiver, { publicRatings: '0' });
        assert.doesNotMatch((await guest.request(route)).html, /data-profile-rating=/);
        await savePrivacy(receiver, { publicRatings: '1' });
        assert.match((await guest.request(route)).html, /data-profile-rating="9"/);
        await savePrivacy(receiver, { messagePolicy: 'nobody', publicRatings: '0', publicStatistics: '0' });
        for (const client of [guest, sender.client]) {
            const page = await client.request(route + '?showRatings=1&userId=' + receiver.id);
            assert.equal(page.status, 200);
            assert.doesNotMatch(page.html, /data-profile-rating=|data-watchlist-count=|data-completed-count=|data-watched-episodes=|data-user-exp=/);
            assert.match(page.html, /ukrył statystyki/);
            assert.match(page.html, /Watchlista użytkownika/);
        }
        const own = await receiver.client.request(route);
        assert.match(own.html, /data-profile-rating="9"/);
        assert.match(own.html, /data-watched-episodes="1"/);
        assert.match((await receiver.client.request('/profile')).html, /data-watchlist-count="1"/);
        redirected(await receiver.client.request('/logout', { csrfToken: receiver.token }), '/login');
        receiver.token = csrf(await receiver.client.request('/login'));
        redirected(await receiver.client.request('/login', {
            csrfToken: receiver.token, email: receiver.email, password,
        }), '/projects');
        receiver.token = csrf(await receiver.client.request('/settings'));
        const persisted = await receiver.client.request('/settings');
        assert.match(persisted.html, /value="nobody" selected/);
        assert.match(persisted.html, /id="public-ratings"[\s\S]*?value="0" selected/);
    });

    await t.test('restoring everyone cannot bypass an explicit user block', async () => {
        await savePrivacy(receiver, {});
        const publicPage = await guest.request('/users/' + receiver.id);
        assert.match(publicPage.html, /data-profile-rating="9"/);
        assert.match(publicPage.html, /data-watchlist-count="1"/);
        assert.equal((await sender.client.request(conversation, { csrfToken: sender.token, body: 'Ponownie ' + randomUUID() })).status, 303);
        assert.equal((await receiver.client.request('/users/' + sender.id + '/block', { csrfToken: receiver.token })).status, 303);
        assert.equal((await sender.client.request(conversation, { csrfToken: sender.token, body: 'Po blokadzie ' + randomUUID() })).status, 403);
    });
});
