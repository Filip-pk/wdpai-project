const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';

function browser(initialCookie = '') {
    let cookie = initialCookie;

    return {
        get cookie() { return cookie; },
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: cookie ? { Cookie: cookie } : {},
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual',
                signal: AbortSignal.timeout(5000),
            });
            for (const value of response.headers.getSetCookie()) {
                if (value.startsWith('PHPSESSID=')) {
                    cookie = value.split(';', 1)[0];
                    if (/^PHPSESSID=(?:deleted)?$/.test(cookie)) cookie = '';
                }
            }
            const html = await response.text();
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);

            return { status: response.status, headers: response.headers, html };
        },
    };
}

function csrfFrom(page) {
    const token = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(token, 'The rendered form has a CSRF token');
    return token[1];
}

function redirected(page, path) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), path);
}

function passwordIsEmpty(page, password) {
    assert.ok(!page.html.includes(password), 'A password must never be echoed');
    assert.doesNotMatch(page.html, /<input\b[^>]*type="password"[^>]*\bvalue=/);
}

test('registration, sessions, protected projects and logout', async (t) => {
    const client = browser();
    const email = `auth-${randomUUID()}@example.test`;
    const name = 'Żółć <svg onload=alert(1)> & Tester';
    const password = 'Testowe-Haslo-2026!';
    let anonymousCookie;
    let anonymousToken;
    let signedInToken;

    await t.test('public forms create a session and guests cannot access projects', async () => {
        const page = await client.request('/register');
        assert.equal(page.status, 200);
        anonymousToken = csrfFrom(page);
        anonymousCookie = client.cookie;
        assert.ok(anonymousCookie.startsWith('PHPSESSID='));
        const cookies = page.headers.getSetCookie().join(';');
        assert.match(cookies, /HttpOnly/i);
        assert.match(cookies, /SameSite=Lax/i);
        assert.doesNotMatch(cookies, /Max-Age|expires=/i);
        assert.equal(/;\s*secure(?:;|$)/i.test(cookies), new URL(baseUrl).protocol === 'https:');
        for (const path of ['/projects', '/projects/1']) {
            redirected(await client.request(path), '/login');
        }
        assert.equal((await client.request('/login')).status, 200);
        assert.equal((await client.request('/logout')).status, 404);
    });

    await t.test('missing, incorrect and array CSRF tokens are rejected', async () => {
        const fields = { name, email, password, passwordConfirmation: password };
        for (const route of ['/register', '/login', '/logout']) {
            for (const token of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': anonymousToken }]) {
                const page = await client.request(route, { ...fields, ...token });
                assert.equal(page.status, 403, route);
                passwordIsEmpty(page, password);
            }
        }
    });

    await t.test('server validation preserves escaped safe fields and clears passwords', async () => {
        const valid = { name, email, password, passwordConfirmation: password, csrfToken: anonymousToken };
        for (const invalid of [
            { name: '' }, { name: 'x'.repeat(121) }, { email: 'invalid' },
            { password: 'short', passwordConfirmation: 'short' },
            { passwordConfirmation: 'different' },
            { password: 'ą'.repeat(37), passwordConfirmation: 'ą'.repeat(37) },
            { password: 'Haslo\0testowe', passwordConfirmation: 'Haslo\0testowe' },
        ]) {
            const page = await client.request('/register', { ...valid, ...invalid });
            assert.equal(page.status, 422);
            passwordIsEmpty(page, password);
            assert.ok(!page.html.includes('<svg onload=alert(1)>'));
        }
        const escaped = await client.request('/register', { ...valid, passwordConfirmation: 'different' });
        assert.ok(escaped.html.includes('&lt;svg onload=alert(1)&gt;'));
        assert.ok(escaped.html.includes(email));
    });

    await t.test('registration persists the account and rejects case-insensitive duplicates', async () => {
        const fields = { name, email: `  ${email.toUpperCase()}  `, password,
            passwordConfirmation: password, csrfToken: anonymousToken };
        redirected(await client.request('/register', fields), '/login?registered=1&mail=sent');
        redirected(await client.request('/projects'), '/login');
        const login = await client.request('/login?registered=1');
        assert.equal(login.status, 200);
        assert.equal(csrfFrom(login), anonymousToken);
        const duplicate = await client.request('/register', { ...fields, email });
        assert.equal(duplicate.status, 422);
        passwordIsEmpty(duplicate, password);
    });

    await t.test('invalid credentials return the same message without exposing passwords', async () => {
        for (const fields of [
            { email, password: 'Niepoprawne-Haslo!' },
            { email: `missing-${email}`, password },
            { email: "' OR 1=1 --", password },
        ]) {
            const page = await client.request('/login', { ...fields, csrfToken: anonymousToken });
            assert.equal(page.status, 401);
            assert.ok(page.html.includes('Nieprawidłowy e-mail lub hasło.'));
            passwordIsEmpty(page, fields.password);
        }
        redirected(await client.request('/projects'), '/login');
    });

    await t.test('login rotates the session and CSRF token and unlocks project pages', async () => {
        redirected(await client.request('/login', {
            email: ` ${email.toUpperCase()} `, password, csrfToken: anonymousToken,
        }), '/projects');
        assert.notEqual(client.cookie, anonymousCookie);
        redirected(await browser(anonymousCookie).request('/projects'), '/login');
        const projects = await client.request('/projects');
        assert.equal(projects.status, 200);
        assert.match(projects.headers.get('cache-control') ?? '', /no-store/i);
        assert.ok(projects.html.includes('&lt;svg onload=alert(1)&gt;'));
        assert.ok(!projects.html.includes('<svg onload=alert(1)>'));
        assert.ok(projects.html.includes('action="/logout"'));
        signedInToken = csrfFrom(projects);
        assert.notEqual(signedInToken, anonymousToken);
        const projectId = projects.html.match(/data-project-id="(\d+)"/);
        if (projectId) assert.equal((await client.request(`/projects/${projectId[1]}`)).status, 200);
        assert.equal((await client.request('/projects/0')).status, 404);
    });

    await t.test('signed-in users are redirected from login and registration without changing their session', async () => {
        const signedInCookie = client.cookie;
        const fields = {
            name: 'Inne konto', email: `blocked-${randomUUID()}@example.test`,
            password: 'Different-password123!', passwordConfirmation: 'Different-password123!',
            csrfToken: signedInToken,
        };

        for (const route of ['/login', '/register', '/login?source=test', '/register?source=test']) {
            redirected(await client.request(route), '/');
        }
        for (const route of ['/login', '/register']) {
            redirected(await client.request(route, fields), '/');
            redirected(await client.request(route, { ...fields, csrfToken: 'wrong' }), '/');
        }

        assert.equal(client.cookie, signedInCookie);
        const projects = await client.request('/projects');
        assert.equal(projects.status, 200);
        assert.equal(csrfFrom(projects), signedInToken);
        assert.ok(projects.html.includes('&lt;svg onload=alert(1)&gt;'));
        assert.ok((await client.request('/profile')).html.includes(email));
    });

    await t.test('logout requires POST and valid CSRF and destroys the previous session', async () => {
        assert.equal((await client.request('/logout')).status, 404);
        for (const token of [{}, { csrfToken: anonymousToken }, { 'csrfToken[]': signedInToken }]) {
            assert.equal((await client.request('/logout', token)).status, 403);
            assert.equal((await client.request('/projects')).status, 200);
        }
        const previousCookie = client.cookie;
        const logout = await client.request('/logout', { csrfToken: signedInToken });
        redirected(logout, '/login');
        assert.ok(logout.headers.getSetCookie().some((value) =>
            value.startsWith('PHPSESSID=') && /expires=|Max-Age=0/i.test(value)));
        assert.equal(client.cookie, '');
        redirected(await browser(previousCookie).request('/projects'), '/login');
        redirected(await client.request('/projects'), '/login');
        const login = await client.request('/login');
        assert.equal(login.status, 200);
        assert.equal((await client.request('/register')).status, 200);
        assert.notEqual(csrfFrom(login), signedInToken);
        assert.ok(!login.html.includes('&lt;svg onload=alert(1)&gt;'));
    });
});
