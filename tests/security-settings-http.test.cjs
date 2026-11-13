const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const mailpitUrl = process.env.ANIVIBE_MAILPIT_URL ?? 'http://127.0.0.1:8025';
const originalPassword = 'Security-Original-2026!';
const nextPassword = 'Security-Changed-2026!';
const finalPassword = 'Security-Final-2026!';

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
            for (const password of [originalPassword, nextPassword, finalPassword]) {
                assert.ok(!html.includes(password), 'Passwords never appear in the response');
            }
            assert.doesNotMatch(html, /<input\b[^>]*type="password"[^>]*\bvalue=/);
            return { status: response.status, headers: response.headers, html };
        },
    };
}

function csrf(page) {
    const value = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(value);
    return value[1];
}

function redirect(page, path) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), path);
}

async function account() {
    const client = browser();
    const email = `security-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirect(await client.request('/register', {
        name: 'Security test', email, password: originalPassword,
        passwordConfirmation: originalPassword, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirect(await client.request('/login', { email, password: originalPassword, csrfToken: token }), '/projects');
    return { client, email, token: csrf(await client.request('/profile')) };
}

async function login(email, password) {
    const client = browser();
    const token = csrf(await client.request('/login'));
    return client.request('/login', { email, password, csrfToken: token });
}

async function confirmationCode(email) {
    const result = await fetch(new URL(`/api/v1/search?${new URLSearchParams({ query: `to:${email}` })}`, mailpitUrl), {
        signal: AbortSignal.timeout(5000),
    }).then((response) => response.json());
    const id = result.messages.find((message) => message.Subject === 'AniVibe — potwierdź nowy adres e-mail')?.ID;
    assert.ok(id, 'A confirmation message is delivered to the new address');
    const message = await fetch(new URL(`/api/v1/message/${encodeURIComponent(id)}`, mailpitUrl), {
        signal: AbortSignal.timeout(5000),
    }).then((response) => response.json());
    assert.deepEqual(message.To.map((recipient) => recipient.Address), [email]);
    const code = message.Text.match(/Kod potwierdzenia: ([a-f0-9]{32})/);
    assert.ok(code);
    assert.ok(!message.Text.includes(originalPassword));
    return code[1];
}

test('account security validates passwords and confirms the new mailbox before changing email', async (t) => {
    const guest = browser();
    const first = await account();
    const second = await account();
    const passwordFields = {
        csrfToken: first.token, currentPassword: originalPassword,
        newPassword: nextPassword, newPasswordConfirmation: nextPassword,
    };

    await t.test('anonymous requests cannot inspect or mutate account security', async () => {
        for (const route of ['/settings/password', '/settings/email', '/settings/email/confirm']) {
            redirect(await guest.request(route, {}), '/login');
        }
        redirect(await guest.request('/settings/email/confirm'), '/login');
    });

    await t.test('password validation and CSRF reject invalid writes', async () => {
        assert.equal((await first.client.request('/settings/password', { ...passwordFields, csrfToken: 'wrong' })).status, 403);
        for (const invalid of [
            { currentPassword: 'Wrong-password!' },
            { newPassword: 'short', newPasswordConfirmation: 'short' },
            { newPassword: 'ą'.repeat(37), newPasswordConfirmation: 'ą'.repeat(37) },
            { newPassword: 'password\0', newPasswordConfirmation: 'password\0' },
            { newPasswordConfirmation: 'not-the-same' },
            { newPassword: undefined, 'newPassword[]': nextPassword },
        ]) {
            const fields = { ...passwordFields, ...invalid };
            if (fields.newPassword === undefined) delete fields.newPassword;
            assert.equal((await first.client.request('/settings/password', fields)).status, 422);
        }
        redirect(await login(first.email, originalPassword), '/projects');
    });

    await t.test('successful password change affects only the logged-in account', async () => {
        redirect(await first.client.request('/settings/password', { ...passwordFields, userId: '0' }), '/settings#security');
        const settings = await first.client.request('/settings');
        assert.match(settings.html, /Hasło zostało zmienione/);
        assert.equal((await login(first.email, originalPassword)).status, 401);
        redirect(await login(first.email, nextPassword), '/projects');
        redirect(await login(second.email, originalPassword), '/projects');
    });

    const newEmail = `security-confirmed-${randomUUID()}@example.test`;
    const emailFields = {
        csrfToken: first.token, currentPassword: nextPassword,
        newEmail, newEmailConfirmation: newEmail,
    };
    await t.test('email changes validate current password, duplicate addresses and confirmation', async () => {
        assert.equal((await first.client.request('/settings/email', { ...emailFields, csrfToken: 'wrong' })).status, 403);
        for (const invalid of [
            { currentPassword: originalPassword },
            { newEmail: 'invalid', newEmailConfirmation: 'invalid' },
            { newEmailConfirmation: first.email },
            { newEmail: first.email, newEmailConfirmation: first.email },
            { newEmail: second.email.toUpperCase(), newEmailConfirmation: second.email },
            { newEmail: undefined, 'newEmail[]': newEmail },
        ]) {
            const fields = { ...emailFields, ...invalid };
            if (fields.newEmail === undefined) delete fields.newEmail;
            assert.equal((await first.client.request('/settings/email', fields)).status, 422);
        }
    });

    let code;
    await t.test('requesting a change sends mail and preserves the existing address until confirmation', async () => {
        redirect(await first.client.request('/settings/email', emailFields), '/settings/email/confirm');
        code = await confirmationCode(newEmail);
        assert.ok((await first.client.request('/profile')).html.includes(first.email));
        assert.equal((await login(newEmail, nextPassword)).status, 401);
        redirect(await login(first.email, nextPassword), '/projects');
        assert.equal((await first.client.request('/settings/email', emailFields)).status, 429);
    });

    await t.test('forged, foreign and invalid codes cannot confirm; the correct code works once', async () => {
        assert.equal((await first.client.request('/settings/email/confirm', { csrfToken: 'wrong', confirmationCode: code })).status, 403);
        assert.equal((await second.client.request('/settings/email/confirm', { csrfToken: second.token, confirmationCode: code })).status, 422);
        assert.equal((await first.client.request('/settings/email/confirm', { csrfToken: first.token, confirmationCode: 'f'.repeat(32) })).status, 422);
        redirect(await first.client.request('/settings/email/confirm', { csrfToken: first.token, confirmationCode: code }), '/settings#security');
        assert.equal((await first.client.request('/settings/email/confirm', { csrfToken: first.token, confirmationCode: code })).status, 422);
        assert.equal((await login(first.email, nextPassword)).status, 401);
        redirect(await login(newEmail.toUpperCase(), nextPassword), '/projects');
        assert.ok((await first.client.request('/settings')).html.includes(newEmail));
    });

    await t.test('password changes invalidate a pending email confirmation', async () => {
        const anotherEmail = `security-invalidated-${randomUUID()}@example.test`;
        redirect(await first.client.request('/settings/email', {
            ...emailFields, newEmail: anotherEmail, newEmailConfirmation: anotherEmail,
        }), '/settings/email/confirm');
        const anotherCode = await confirmationCode(anotherEmail);
        redirect(await first.client.request('/settings/password', {
            csrfToken: first.token, currentPassword: nextPassword,
            newPassword: finalPassword, newPasswordConfirmation: finalPassword,
        }), '/settings#security');
        assert.equal((await first.client.request('/settings/email/confirm', {
            csrfToken: first.token, confirmationCode: anotherCode,
        })).status, 422);
        redirect(await login(newEmail, finalPassword), '/projects');
        assert.equal((await login(anotherEmail, finalPassword)).status, 401);
    });
});
