const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Komentarze-Testowe-2026!';
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

function items(page) {
    return [...page.html.matchAll(/<article\b[^>]*data-comment-id="(\d+)"[^>]*>[\s\S]*?<\/article>/g)]
        .map(match => ({ id: Number(match[1]), userId: Number(match[0].match(/data-comment-user-id="(\d+)"/)?.[1]), html: match[0] }));
}

function paging(page) {
    assert.equal(page.status, 200);
    return Object.fromEntries(['total', 'page', 'total-pages'].map(name => {
        const match = page.html.match(new RegExp('data-comments-' + name + '="(\\d+)"'));
        assert.ok(match, 'Comments expose ' + name);
        return [name, Number(match[1])];
    }));
}

async function account(name) {
    const client = browser();
    const email = `comments-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    const match = profile.html.match(/href="\/users\/(\d+)"[^>]*class="account-user"/);
    assert.ok(match, 'The account exposes its public profile link');
    return { client, name, email, id: Number(match[1]), token: csrf(profile) };
}

test('anime comments are public, paginated and writable only by their authenticated author', async (t) => {
    const guest = browser();
    const first = await account('Komentujący <svg onload=alert(1)> & Łucja');
    const second = await account('Drugi komentujący');
    const catalog = await guest.request('/anime');
    const animeIds = [...catalog.html.matchAll(/<article\b[^>]*data-anime-id="(\d+)"/g)].map(match => Number(match[1]));
    assert.ok(animeIds.length >= 2);
    const route = '/anime/' + animeIds[1];
    const otherRoute = '/anime/' + animeIds[0];
    const baseline = paging(await guest.request(route)).total;
    const created = [];
    const marker = randomUUID();
    const originalBody = `Świetna seria ${marker}!\n<script>alert(1)</script> & <img src=x onerror=alert(1)>`;
    const location = (notice, page = 1) => route + '?' + (page > 1 ? 'commentsPage=' + page + '&' : '') + notice + '=1#comments';
    const create = async (body, owner = first) => {
        redirected(await owner.client.request(route + '/comments', {
            body, csrfToken: owner.token, userId: second.id, user_id: second.id,
        }), location('commentSaved'));
        const entry = items(await guest.request(route)).find(item => item.userId === owner.id && item.html.includes(escape(body)));
        assert.ok(entry, 'The saved comment belongs to the session account');
        created.push({ ...entry, owner });
        return entry;
    };
    let original;

    try {
        await t.test('guests can read comments but cannot create, edit or remove them', async () => {
            const page = await guest.request(route);
            assert.equal(page.status, 200);
            assert.doesNotMatch(page.html, /<form\b[^>]*action="\/anime\/\d+\/comments/);
            assert.match(page.html, /href="\/login"/);
            for (const suffix of ['/comments', '/comments/1/edit', '/comments/1/delete']) {
                redirected(await guest.request(route + suffix, { body: 'Gość' }), '/login');
            }
        });

        await t.test('saved plain-text comments escape HTML and link the author avatar to a public profile', async () => {
            original = await create(originalBody);
            const page = await guest.request(route);
            const entry = items(page).find(item => item.id === original.id);
            assert.ok(entry.html.includes(escape(originalBody)));
            assert.ok(entry.html.includes(escape(first.name)));
            assert.ok(entry.html.includes('href="/users/' + first.id + '"'));
            assert.doesNotMatch(entry.html, /<script>alert\(1\)<\/script>|<svg onload=|<img src=x/);
            assert.ok(!page.html.includes(first.email) && !page.html.includes(second.email));
            assert.ok(!entry.html.includes('/comments/' + original.id + '/delete'));
            assert.ok(!entry.html.includes('editComment=' + original.id));
        });

        await t.test('CSRF and text validation reject forged or malformed writes without changing comments', async () => {
            for (const token of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
                for (const suffix of ['/comments', '/comments/' + original.id + '/edit', '/comments/' + original.id + '/delete']) {
                    assert.equal((await first.client.request(route + suffix, { body: 'Zmiana', ...token })).status, 403);
                }
            }
            for (const fields of [{ body: '' }, { body: ' \n\t ' }, { body: 'ż'.repeat(2001) }, { 'body[]': 'tablica' }, { body: 'Tekst\0NUL' }]) {
                for (const suffix of ['/comments', '/comments/' + original.id + '/edit']) {
                    assert.equal((await first.client.request(route + suffix, { ...fields, csrfToken: first.token })).status, 422);
                }
            }
            assert.equal(paging(await guest.request(route)).total, baseline + 1);
            assert.ok(items(await guest.request(route)).find(item => item.id === original.id).html.includes(escape(originalBody)));
        });

        await t.test('another author and an unrelated anime cannot edit or delete a comment', async () => {
            for (const suffix of ['/edit', '/delete']) {
                assert.equal((await second.client.request(route + '/comments/' + original.id + suffix, {
                    body: 'Cudzy wpis', csrfToken: second.token, userId: first.id,
                })).status, 404);
                assert.equal((await first.client.request(otherRoute + '/comments/' + original.id + suffix, {
                    body: 'Inne anime', csrfToken: first.token,
                })).status, 404);
                assert.equal((await first.client.request(route + '/comments/' + missingId + suffix, {
                    body: 'Brak wpisu', csrfToken: first.token,
                })).status, 404);
            }
            assert.equal((await second.client.request(route + '?editComment=' + original.id)).status, 404);
            assert.equal((await first.client.request(otherRoute + '?editComment=' + original.id)).status, 404);
            assert.equal((await first.client.request('/anime/' + missingId + '/comments', { body: 'Brak anime', csrfToken: first.token })).status, 404);
            const entry = items(await second.client.request(route)).find(item => item.id === original.id);
            assert.ok(!entry.html.includes('/comments/' + original.id + '/delete'));
            assert.ok(!entry.html.includes('editComment=' + original.id));
        });

        await t.test('ten comments fit a page and navigation keeps all entries in newest-first order', async () => {
            for (let index = 1; index <= 9; index++) {
                await create(index === 1 ? 'ż'.repeat(2000) : index === 2 ? 'Ź' : `${marker} — komentarz ${index}`);
            }
            await create(`${marker} — drugie konto`, second);
            const page = await guest.request(route);
            const expected = created.map(entry => entry.id).reverse();
            assert.equal(paging(page).total, baseline + 11);
            assert.equal(items(page).length, 10);
            assert.deepEqual(items(page).map(entry => entry.id), expected.slice(0, 10));
            const next = await guest.request(route + '?commentsPage=2');
            assert.equal(paging(next).page, 2);
            assert.deepEqual(items(next).filter(entry => created.some(saved => saved.id === entry.id)).map(entry => entry.id), expected.slice(10));
            assert.ok(page.html.includes('commentsPage=2'));
            assert.equal(paging(await guest.request(route + '?commentsPage[]=2')).page, 1);
        });

        await t.test('editing the own comment preserves pagination, author and chronological position', async () => {
            const edit = await first.client.request(route + '?commentsPage=2&editComment=' + original.id);
            assert.equal(edit.status, 200);
            assert.ok(edit.html.includes('action="' + route + '/comments/' + original.id + '/edit"'));
            assert.ok(edit.html.includes(escape(originalBody)));
            const changed = `${marker} — poprawiona treść <b>jako tekst</b>`;
            redirected(await first.client.request(route + '/comments/' + original.id + '/edit', {
                body: changed, csrfToken: first.token, commentsPage: '2', userId: second.id,
            }), location('commentUpdated', 2));
            const entry = items(await guest.request(route + '?commentsPage=2')).find(item => item.id === original.id);
            assert.equal(entry.userId, first.id);
            assert.ok(entry.html.includes(escape(changed)));
            assert.match(entry.html, /[Ee]dytowano/);
            assert.deepEqual(items(await guest.request(route)).map(item => item.id), created.slice(1).reverse().map(item => item.id));
        });

        await t.test('deleting the own comment updates the total and clamps the requested page', async () => {
            const destinationPage = Math.min(2, Math.max(1, Math.ceil((baseline + 10) / 10)));
            redirected(await first.client.request(route + '/comments/' + original.id + '/delete', {
                csrfToken: first.token, commentsPage: '2', userId: second.id,
            }), location('commentRemoved', destinationPage));
            assert.equal(paging(await guest.request(route)).total, baseline + 10);
            assert.equal((await first.client.request(route + '?editComment=' + original.id)).status, 404);
        });
    } finally {
        const owned = new Map(created.map(entry => [entry.id, entry.owner]));
        const firstPage = await guest.request(route);
        for (let page = 1; page <= paging(firstPage)['total-pages']; page++) {
            const result = page === 1 ? firstPage : await guest.request(route + '?commentsPage=' + page);
            for (const entry of items(result)) {
                const owner = [first, second].find(user => user.id === entry.userId);
                if (owner) owned.set(entry.id, owner);
            }
        }
        for (const [id, owner] of owned) {
            const response = await owner.client.request(route + '/comments/' + id + '/delete', { csrfToken: owner.token });
            assert.ok([303, 404].includes(response.status), 'Cleanup removes only comments owned by the test accounts');
        }
        assert.equal(paging(await guest.request(route)).total, baseline, 'Test comments are removed after the scenario');
    }
});
