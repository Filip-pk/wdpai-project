const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const base = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
function browser(initial = {}) {
    const cookies = new Map(Object.entries(initial));
    return { cookies, async request(route, fields) {
        const response = await fetch(new URL(route, base), {
            method: fields === undefined ? 'GET' : 'POST',
            headers: { Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; ') },
            body: fields === undefined ? undefined : new URLSearchParams(fields),
            redirect: 'manual', signal: AbortSignal.timeout(15000),
        });
        for (const value of response.headers.getSetCookie()) {
            const pair = value.split(';', 1)[0];
            const index = pair.indexOf('=');
            cookies.set(pair.slice(0, index), pair.slice(index + 1));
        }
        const html = await response.text();
        assert.doesNotMatch(html, /Fatal error|Warning:|SQLSTATE|PDOException|Stack trace/);
        return { status: response.status, location: response.headers.get('location'), html, headers: response.headers };
    } };
}
const csrf = page => {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match, 'CSRF token is rendered');
    return match[1];
};

test('guest language selection supports six locales, protects CSRF and keeps redirects local', async () => {
    const client = browser();
    const token = csrf(await client.request('/login'));
    assert.equal((await client.request('/language', { language: 'en', csrfToken: 'bad' })).status, 403);
    assert.equal(client.cookies.has('anivibe_language'), false);
    for (const language of ['xx', '../config', '', 'EN']) {
        assert.equal((await client.request('/language', { language, csrfToken: token })).status, 422);
    }
    assert.equal((await client.request('/language', { 'language[]': 'en', csrfToken: token })).status, 422);
    for (const language of ['pl', 'en', 'de', 'fr', 'es', 'ru']) {
        const saved = await client.request('/language', { language, csrfToken: token, returnTo: '/anime?q=Naruto' });
        assert.equal(saved.status, 303);
        assert.equal(saved.location, '/anime?q=Naruto');
        assert.equal(client.cookies.get('anivibe_language'), language);
        const cookie = saved.headers.getSetCookie().find(value => value.startsWith('anivibe_language='));
        assert.match(cookie, /HttpOnly/i);
        assert.match(cookie, /SameSite=Lax/i);
        const page = await client.request('/login');
        assert.ok(page.html.includes(`<html lang="${language}"`));
        assert.ok(page.html.includes(`"locale":"${language}"`));
    }
    for (const returnTo of ['https://example.test', '//example.test', '/\\example.test', '/%5cexample.test', '/%0d%0aLocation:evil']) {
        assert.equal((await client.request('/language', { language: 'en', csrfToken: token, returnTo })).location, '/');
    }
    assert.equal((await client.request('/language', {
        language: 'en', csrfToken: token, returnTo: '/anime?q=Fullmetal%20Alchemist',
    })).location, '/anime?q=Fullmetal%20Alchemist');
    const hostile = browser({ anivibe_language: '..%2Fconfig' });
    assert.match((await hostile.request('/login')).html, /<html lang="pl"/);
    const english = await client.request('/login');
    assert.match(english.html, /Interface language/);
    assert.doesNotMatch(english.html, />Ustawienia<|>Zaloguj się<|>Wyloguj</);
});

test('account language persists across sessions, overrides guest cookies and preserves user content', async () => {
    const client = browser();
    let token = csrf(await client.request('/register'));
    await client.request('/language', { language: 'de', csrfToken: token });
    const name = 'Ustawienia <Ada> ' + randomUUID().slice(0, 8);
    const email = `language-${randomUUID()}@example.test`;
    const password = 'Language-Test-2026!';
    assert.equal((await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token })).status, 303);
    assert.equal((await client.request('/login', { email, password, csrfToken: token })).status, 303);
    let settings = await client.request('/settings');
    assert.match(settings.html, /<html lang="de"/);
    assert.ok(settings.html.includes(name.replace('<', '&lt;').replace('>', '&gt;')));
    token = csrf(settings);
    assert.equal((await client.request('/language', { language: 'fr', csrfToken: token, returnTo: '/settings#language' })).location, '/settings#language');
    client.cookies.set('anivibe_language', 'ru');
    settings = await client.request('/settings');
    assert.match(settings.html, /<html lang="fr"/);
    const otherSession = browser();
    const loginToken = csrf(await otherSession.request('/login'));
    assert.equal((await otherSession.request('/login', { email, password, csrfToken: loginToken })).status, 303);
    assert.match((await otherSession.request('/settings')).html, /<html lang="fr"/);
    await client.request('/language', { language: 'es', csrfToken: token });
    assert.match((await otherSession.request('/settings')).html, /<html lang="es"/);
    assert.equal((await otherSession.request('/language', { language: 'pl', csrfToken: 'bad' })).status, 403);
    assert.match((await client.request('/settings')).html, /<html lang="es"/);
    const collection = await client.request('/profile/collections', {
        name: 'Ustawienia', description: 'Wiadomości <bez tłumaczenia>', csrfToken: token,
    });
    assert.equal(collection.status, 303);
    assert.match(collection.location, /^\/profile\/collections\/[1-9][0-9]*$/);
    const detail = await client.request(collection.location);
    assert.match(detail.html, /<title>Ustawienia — AniVibe<\/title>/);
    assert.match(detail.html, /Wiadomości &lt;bez tłumaczenia&gt;/);
});

test('English anime descriptions stay identical in every interface language', async () => {
    const client = browser();
    const listing = await client.request('/anime');
    const animePath = listing.html.match(/href="(\/anime\/[1-9][0-9]*)"/);
    assert.ok(animePath, 'Catalog contains anime');
    const original = await client.request(animePath[1]);
    const description = original.html.match(/<p[^>]*class="[^"]*anime-synopsis[^"]*"[^>]*lang="en"[^>]*>([\s\S]*?)<\/p>/)
        ?? original.html.match(/<p[^>]*lang="en"[^>]*>([\s\S]*?)<\/p>/);
    assert.ok(description, 'Anime description is marked with lang=en');
    const token = csrf(original);
    for (const language of ['en', 'de', 'fr', 'es', 'ru']) {
        await client.request('/language', { language, csrfToken: token, returnTo: animePath[1] });
        const page = await client.request(animePath[1]);
        assert.ok(page.html.includes(description[1]), 'Description text is unchanged in ' + language);
        assert.match(page.html, /lang="en"/);
    }
});
