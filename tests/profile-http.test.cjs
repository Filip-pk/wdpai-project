const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Profil-Testowy-2026!';

function browser() {
    let cookie = '';
    return {
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: cookie ? { Cookie: cookie } : {},
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual', signal: AbortSignal.timeout(5000),
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
    assert.ok(match, 'The form includes a CSRF token');
    return match[1];
}

function redirected(page, path) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), path);
}

function counts(page, total, completed) {
    assert.ok(page.html.includes(`data-watchlist-count="${total}"`));
    assert.ok(page.html.includes(`data-completed-count="${completed}"`));
}

function entries(page) {
    return [...page.html.matchAll(/data-anime-id="(\d+)"\s+data-watchlist-status="([^"]+)"\s+data-watched="(\d+)"/g)]
        .map((match) => ({ animeId: Number(match[1]), status: match[2], watched: Number(match[3]) }));
}

async function account(name) {
    const client = browser();
    const email = `profile-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const page = await client.request('/profile');
    assert.equal(page.status, 200);
    return { client, email, name, token: csrf(page) };
}

test('profiles, preferences and watchlists belong to the signed-in account', async (t) => {
    const guest = browser();
    let first;
    let second;
    const changedName = 'Łucja <svg onload=alert(1)> & Tester';
    const interests = 'Anime psychologiczne <script>alert(1)</script> & mecha';

    await t.test('private account routes reject anonymous requests', async () => {
        for (const route of ['/profile', '/watchlist']) {
            redirected(await guest.request(route), '/login');
            redirected(await guest.request(route, { csrfToken: 'wrong' }), '/login');
        }
        redirected(await guest.request('/profile/theme', { theme: 'dark' }), '/login');
        const dashboard = await guest.request('/dashboard');
        assert.equal(dashboard.status, 200);
        assert.ok(!dashboard.html.includes('Kacper'));
        first = await account('Profil A');
        second = await account('Profil B');
    });

    await t.test('new accounts display their own identity and empty statistics', async () => {
        for (const user of [first, second]) {
            const page = await user.client.request('/profile');
            assert.ok(page.html.includes(user.name));
            assert.ok(page.html.includes(user.email));
            assert.ok(!page.html.includes('kacper@example.com'));
            counts(page, 0, 0);
            assert.deepEqual(entries(await user.client.request('/watchlist')), []);
            assert.ok((await user.client.request('/dashboard')).html.includes(user.name));
        }
    });

    await t.test('profile validation and CSRF prevent invalid or forged writes', async () => {
        const valid = { name: first.name, email: first.email, interests: '', theme: 'system', csrfToken: first.token };
        for (const invalid of [
            { name: '' }, { name: 'ż'.repeat(121) }, { email: 'invalid' },
            { interests: 'ą'.repeat(1001) }, { theme: 'sepia' }, { 'name[]': 'array', name: undefined },
        ]) {
            const fields = { ...valid, ...invalid };
            if (fields.name === undefined) delete fields.name;
            assert.equal((await first.client.request('/profile', fields)).status, 422);
        }
        for (const token of [{ csrfToken: 'wrong' }, { 'csrfToken[]': first.token, csrfToken: undefined }]) {
            const fields = { ...valid, ...token };
            if (fields.csrfToken === undefined) delete fields.csrfToken;
            assert.equal((await first.client.request('/profile', fields)).status, 403);
        }
        assert.equal((await first.client.request('/profile', {
            ...valid, email: second.email.toUpperCase(), currentPassword: password,
        })).status, 422);
        assert.ok((await first.client.request('/profile')).html.includes(first.email));
    });

    await t.test('saved Unicode identity and interests are escaped and isolated from the other account', async () => {
        redirected(await first.client.request('/profile', {
            name: changedName, email: first.email, interests, theme: 'dark',
            csrfToken: first.token, userId: '0', user_id: '999999999',
        }), '/profile?saved=1');
        const page = await first.client.request('/profile?userId=999999999');
        assert.ok(page.html.includes('Łucja &lt;svg onload=alert(1)&gt; &amp; Tester'));
        assert.ok(page.html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
        assert.ok(!page.html.includes('<svg onload=alert(1)>'));
        assert.ok(!page.html.includes('<script>alert(1)</script>'));
        assert.match(page.html, /value="dark"\s+selected/);
        const other = await second.client.request('/profile');
        assert.ok(other.html.includes(second.name));
        assert.ok(!other.html.includes('Łucja'));
        assert.match(other.html, /value="system"\s+selected/);
        assert.ok((await first.client.request('/dashboard')).html.includes('Łucja'));
    });

    await t.test('theme writes validate input and persist independently across logins', async () => {
        for (const [fields, status] of [
            [{ csrfToken: 'wrong', theme: 'light' }, 403],
            [{ csrfToken: first.token, theme: 'invalid' }, 422],
            [{ csrfToken: first.token, 'theme[]': 'light' }, 422],
        ]) assert.equal((await first.client.request('/profile/theme', fields)).status, status);
        const saved = await first.client.request('/profile/theme', { csrfToken: first.token, theme: 'light' });
        assert.equal(saved.status, 200);
        assert.equal(JSON.parse(saved.html).theme, 'light');
        assert.match((await second.client.request('/profile')).html, /value="system"\s+selected/);
    });

    await t.test('watchlist validates progress and prevents reading or changing another account entries', async () => {
        const valid = { csrfToken: first.token, animeId: '1', status: 'watching', watched: '3' };
        assert.equal((await first.client.request('/watchlist', { ...valid, csrfToken: 'wrong' })).status, 403);
        for (const invalid of [
            { animeId: '999999' }, { status: 'invalid' }, { watched: '-1' },
            { watched: '221' }, { watched: '1.5' },
        ]) assert.equal((await first.client.request('/watchlist', { ...valid, ...invalid })).status, 422);
        redirected(await first.client.request('/watchlist', { ...valid, user_id: '999999999' }), '/watchlist');
        redirected(await first.client.request('/watchlist', { ...valid, mode: 'add' }), '/watchlist');
        assert.deepEqual(entries(await first.client.request('/watchlist')), [{ animeId: 1, status: 'watching', watched: 3 }]);
        counts(await first.client.request('/profile'), 1, 0);
        assert.deepEqual(entries(await second.client.request('/watchlist')), []);
        redirected(await second.client.request('/watchlist/remove', {
            csrfToken: second.token, animeId: '1', userId: '1',
        }), '/watchlist');
        assert.equal(entries(await first.client.request('/watchlist')).length, 1);
        redirected(await first.client.request('/watchlist', { ...valid, status: 'completed', watched: '0' }), '/watchlist');
        assert.deepEqual(entries(await first.client.request('/watchlist')), [{ animeId: 1, status: 'completed', watched: 220 }]);
        counts(await first.client.request('/profile'), 1, 1);
        counts(await second.client.request('/profile'), 0, 0);
        assert.equal((await first.client.request('/watchlist/remove', { animeId: '1', csrfToken: 'wrong' })).status, 403);
        redirected(await first.client.request('/watchlist/remove', { animeId: '1', csrfToken: first.token }), '/watchlist');
        counts(await first.client.request('/profile'), 0, 0);
    });

    await t.test('profile writes cannot bypass email confirmation and preserve account preferences', async () => {
        const email = `changed-${randomUUID()}@example.test`;
        const fields = { name: changedName, email, interests, theme: 'light', csrfToken: first.token };
        for (const currentPassword of ['', 'Wrong-password!', password]) {
            const page = await first.client.request('/profile', { ...fields, currentPassword });
            assert.equal(page.status, 422);
            if (currentPassword) assert.ok(!page.html.includes(currentPassword));
            assert.doesNotMatch(page.html, /<input\b[^>]*type="password"[^>]*\bvalue=/);
        }
        assert.ok(!(await first.client.request('/profile')).html.includes(email));
        redirected(await first.client.request('/logout', { csrfToken: first.token }), '/login');
        const token = csrf(await first.client.request('/login'));
        assert.equal((await first.client.request('/login', { email, password, csrfToken: token })).status, 401);
        redirected(await first.client.request('/login', { email: first.email.toUpperCase(), password, csrfToken: token }), '/projects');
        const page = await first.client.request('/profile');
        assert.match(page.html, /value="light"\s+selected/);
        assert.ok(page.html.includes('Łucja'));
        assert.ok(page.html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
        assert.ok((await second.client.request('/profile')).html.includes(second.email));
    });
});
