const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const mailpitUrl = process.env.ANIVIBE_MAILPIT_URL ?? 'http://127.0.0.1:8025';
const expectMailFailure = process.env.ANIVIBE_EXPECT_MAIL_FAILURE === '1';
const password = 'Powitanie-Testowe-2026!';

function browser() {
    const cookies = new Map();

    return {
        cookies,
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: { Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; ') },
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual',
                signal: AbortSignal.timeout(10000),
            });
            for (const value of response.headers.getSetCookie()) {
                const pair = value.split(';', 1)[0];
                const separator = pair.indexOf('=');
                cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
            }
            const html = await response.text();
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:|stream_socket_client|Connection refused/i);
            assert.ok(!html.includes(password), 'A response never contains the password');

            return { status: response.status, headers: response.headers, html };
        },
    };
}

function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match, 'The form includes a CSRF token');
    return match[1];
}

async function mailpit(route, asText = false) {
    const response = await fetch(new URL(route, mailpitUrl), {
        signal: AbortSignal.timeout(5000),
    });
    assert.equal(response.status, 200, 'Mailpit API is available');
    return asText ? response.text() : response.json();
}

async function messagesTo(email) {
    const result = await mailpit(`/api/v1/search?${new URLSearchParams({ query: `to:${email}` })}`);
    assert.ok(Array.isArray(result.messages));
    return result;
}

function decodedSubject(raw) {
    const headers = raw.split(/\r?\n\r?\n/, 1)[0].replace(/\r?\n[ \t]+/g, ' ');
    const match = headers.match(/^Subject:\s*(.+)$/im);
    assert.ok(match, 'The source contains a Subject header');
    return match[1].replace(/(\?=)\s+(=\?)/g, '$1$2')
        .replace(/=\?UTF-8\?B\?([^?]+)\?=/gi, (_, value) => Buffer.from(value, 'base64').toString('utf8'));
}

test('welcome notifications accept only known scalar query parameters', async () => {
    const client = browser();
    for (const route of [
        '/login', '/login?mail=sent', '/login?registered=1',
        '/login?registered=1&mail=unknown', '/login?registered=1&mail[]=sent',
        '/login?registered[]=1&mail=sent',
        '/login?registered=1&mail=%3Csvg%20onload%3Dalert(1)%3E',
    ]) {
        const page = await client.request(route);
        assert.equal(page.status, 200);
        assert.doesNotMatch(page.html, /data-mail-status=/);
        assert.ok(!page.html.includes('<svg onload=alert(1)>'));
    }
    for (const status of ['sent', 'failed']) {
        const page = await client.request(`/login?registered=1&mail=${status}`);
        assert.equal(page.status, 200);
        assert.ok(page.html.includes(`data-mail-status="${status}"`));
        assert.doesNotMatch(page.html, /mailpit|SMTP|localhost:1025/i);
    }
});

test('registration and email confirmation use the recipient language', { skip: expectMailFailure }, async () => {
    const client = browser();
    const email = `welcome-locale-${randomUUID()}@example.test`;
    const name = 'Ustawienia <Ada> Łucja';
    let token = csrf(await client.request('/register'));
    assert.equal((await client.request('/language', { language: 'de', csrfToken: token })).status, 303);
    assert.equal((await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    })).status, 303);
    const welcomeResults = await messagesTo(email);
    assert.equal(welcomeResults.messages_count, 1);
    const welcome = await mailpit(`/api/v1/message/${encodeURIComponent(welcomeResults.messages[0].ID)}`);
    assert.equal(welcome.Subject, 'Willkommen bei AniVibe — hallo!');
    assert.ok(welcome.Text.includes(`Hallo ${name}!`));

    assert.equal((await client.request('/login', { email, password, csrfToken: token })).status, 303);
    const settings = await client.request('/settings');
    assert.match(settings.html, /<html lang="de"/);
    token = csrf(settings);
    await client.request('/language', { language: 'fr', csrfToken: token });
    client.cookies.set('anivibe_language', 'ru');
    const newEmail = `confirmation-locale-${randomUUID()}@example.test`;
    const requested = await client.request('/settings/email', {
        csrfToken: token, currentPassword: password, newEmail, newEmailConfirmation: newEmail,
    });
    assert.equal(requested.status, 303);
    const confirmationResults = await messagesTo(newEmail);
    assert.equal(confirmationResults.messages_count, 1);
    const confirmation = await mailpit(`/api/v1/message/${encodeURIComponent(confirmationResults.messages[0].ID)}`);
    assert.equal(confirmation.Subject, 'AniVibe — confirmez votre nouvelle adresse e-mail');
    assert.match(confirmation.Text, /Code de confirmation : [a-f0-9]{32}/);
    assert.ok(!confirmation.Text.includes(password));
});

test(expectMailFailure ? 'SMTP failure preserves the account and allows login' : 'registration sends exactly one UTF-8 welcome message', async () => {
    const client = browser();
    const email = `welcome-${randomUUID()}@example.test`;
    const name = 'Łucja Żółć';
    const token = csrf(await client.request('/register'));
    const fields = { name, email, password, passwordConfirmation: password, csrfToken: token };

    if (!expectMailFailure) assert.equal((await messagesTo(email)).messages_count, 0);

    const registered = await client.request('/register', fields);
    assert.equal(registered.status, 303);
    const status = expectMailFailure ? 'failed' : 'sent';
    assert.equal(registered.headers.get('location'), `/login?registered=1&mail=${status}`);
    const loginPage = await client.request(registered.headers.get('location'));
    assert.equal(loginPage.status, 200);
    assert.ok(loginPage.html.includes(`data-mail-status="${status}"`));
    assert.ok(loginPage.html.includes(expectMailFailure
        ? 'Nie udało się wysłać wiadomości powitalnej. Konto jest aktywne i możesz się zalogować.'
        : 'Wysłaliśmy wiadomość powitalną na podany adres e-mail.'));
    assert.doesNotMatch(loginPage.html, /mailpit|SMTP|localhost:1025/i);

    if (!expectMailFailure) {
        const results = await messagesTo(email);
        assert.equal(results.messages_count, 1, 'One successful registration sends one email');
        assert.equal(results.messages.length, 1);
        const id = encodeURIComponent(results.messages[0].ID);
        const message = await mailpit(`/api/v1/message/${id}`);
        assert.deepEqual(message.To.map((address) => address.Address), [email]);
        assert.deepEqual(message.From, { Address: 'noreply@anivibe.local', Name: 'AniVibe' });
        assert.equal(message.Subject, 'Witaj w AniVibe — cześć!');
        assert.ok(message.Text.includes(`Cześć ${name}!`));
        assert.ok(message.Text.includes('Twoje konto w AniVibe zostało utworzone. Możesz się już zalogować.'));
        assert.ok(!message.Text.includes(password));
        assert.ok(!message.Text.includes('\uFFFD'), 'Polish text is valid UTF-8');

        const raw = await mailpit(`/api/v1/message/${id}/raw`, true);
        assert.match(raw, /^MIME-Version:\s*1\.0\s*$/im);
        assert.match(raw, /^Content-Type:\s*text\/plain;\s*charset="?UTF-8"?\s*$/im);
        assert.match(raw, /^Content-Transfer-Encoding:\s*8bit\s*$/im);
        assert.equal(decodedSubject(raw), message.Subject);
        assert.ok(raw.includes(`Cześć ${name}!`));
        assert.ok(raw.includes(`<${email}>`));
        assert.ok(!raw.includes(password));

        const duplicate = await client.request('/register', { ...fields, email: email.toUpperCase() });
        assert.equal(duplicate.status, 422);
        const invalidCsrf = await client.request('/register', {
            ...fields, email: `csrf-${email}`, csrfToken: 'wrong',
        });
        assert.equal(invalidCsrf.status, 403);
        assert.equal((await messagesTo(email)).messages_count, 1, 'A duplicate does not send another email');
        assert.equal((await messagesTo(`csrf-${email}`)).messages_count, 0, 'Invalid CSRF cannot send email');
    }

    const loggedIn = await client.request('/login', { email, password, csrfToken: csrf(loginPage) });
    assert.equal(loggedIn.status, 303);
    assert.equal(loggedIn.headers.get('location'), '/projects');
    const profile = await client.request('/profile');
    assert.equal(profile.status, 200);
    assert.ok(profile.html.includes(email));
    assert.ok(profile.html.includes(name));
});
