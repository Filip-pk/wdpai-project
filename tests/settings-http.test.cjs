const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');
const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Settings-Test-2026!';

function browser() {
    let cookie = '';
    return { async request(path, fields) {
        const response = await fetch(new URL(path, baseUrl), {
            method: fields === undefined ? 'GET' : 'POST',
            headers: cookie ? { Cookie: cookie } : {},
            body: fields === undefined ? undefined : new URLSearchParams(fields),
            redirect: 'manual', signal: AbortSignal.timeout(10000),
        });
        for (const value of response.headers.getSetCookie()) {
            if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
        }
        const html = await response.text();
        assert.doesNotMatch(html, /SQLSTATE|PDOException|Fatal error|Warning:/i);
        return { status: response.status, location: response.headers.get('location'), html };
    } };
}
function csrf(page) {
    const token = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(token);
    return token[1];
}
async function account() {
    const client = browser();
    const email = `settings-${randomUUID()}@example.test`;
    let token = csrf(await client.request('/register'));
    assert.equal((await client.request('/register', { name: 'Ustawienia', email, password, passwordConfirmation: password, csrfToken: token })).status, 303);
    assert.equal((await client.request('/login', { email, password, csrfToken: token })).status, 303);
    token = csrf(await client.request('/settings'));
    return { client, email, token };
}

test('appearance settings enforce auth/CSRF/validation and persist per account across sessions', async () => {
    const guest = browser();
    for (const [path, fields] of [['/settings'], ['/settings', {}], ['/settings/appearance/reset', {}]]) {
        assert.equal((await guest.request(path, fields)).location, '/login');
    }
    const first = await account();
    const second = await account();
    const fields = { csrfToken: first.token, theme: 'dark', font_size: 'largest', line_height: 'spacious', interface_density: 'compact' };
    const before = await first.client.request('/settings');
    assert.match(before.html, /href="\/settings" aria-current="page"/);
    assert.equal((await first.client.request('/settings', { ...fields, csrfToken: 'bad' })).status, 403);
    for (const key of ['theme', 'font_size', 'line_height', 'interface_density']) {
        assert.equal((await first.client.request('/settings', { ...fields, [key]: 'invalid' })).status, 422);
    }
    const arrayFields = { ...fields, 'font_size[]': 'large' };
    delete arrayFields.font_size;
    assert.equal((await first.client.request('/settings', arrayFields)).status, 422);
    const saved = await first.client.request('/settings', { ...fields, userId: '999999' });
    assert.equal(saved.location, '/settings#appearance');
    const page = await first.client.request('/settings');
    assert.match(page.html, /data-font-size="largest" data-line-height="spacious" data-density="compact"/);
    assert.match(page.html, /data-account-theme="dark"/);
    assert.match((await first.client.request('/dashboard')).html, /data-font-size="largest"/);
    assert.match((await second.client.request('/settings')).html, /data-font-size="standard"/);
    const otherSession = browser();
    const loginToken = csrf(await otherSession.request('/login'));
    assert.equal((await otherSession.request('/login', { email: first.email, password, csrfToken: loginToken })).status, 303);
    assert.match((await otherSession.request('/settings')).html, /data-font-size="largest"/);
    assert.equal((await first.client.request('/profile/theme', { csrfToken: first.token, theme: 'light' })).status, 200);
    assert.match((await otherSession.request('/settings')).html, /data-account-theme="light"/);
    assert.equal((await first.client.request('/settings/appearance/reset', { csrfToken: 'bad' })).status, 403);
    assert.match((await first.client.request('/settings')).html, /data-font-size="largest"/);
    assert.equal((await first.client.request('/settings/appearance/reset', { csrfToken: first.token })).status, 303);
    assert.match((await otherSession.request('/settings')).html, /data-account-theme="system" data-font-size="standard" data-line-height="standard" data-density="comfortable"/);
});
