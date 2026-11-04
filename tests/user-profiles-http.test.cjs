const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Profile-Publiczne-2026!';

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
    assert.ok(token, 'A CSRF token is present');
    return token[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

function entries(page) {
    return [...page.html.matchAll(/data-anime-id="(\d+)"\s+data-watchlist-status="([^"]+)"\s+data-watched="(\d+)"/g)]
        .map(match => ({ animeId: Number(match[1]), status: match[2], watched: Number(match[3]) }));
}

function statistics(page, total, completed, watching, watched, exp) {
    assert.equal(page.status, 200);
    for (const [name, value] of Object.entries({
        'watchlist-count': total, 'completed-count': completed,
        'watching-count': watching, 'watched-episodes': watched, 'user-exp': exp,
    })) assert.ok(page.html.includes(`data-${name}="${value}"`), `${name} equals ${value}`);
}

function privateDataHidden(page, user) {
    assert.equal(page.status, 200);
    assert.ok(page.html.includes(`data-profile-user-id="${user.id}"`));
    assert.ok(!page.html.includes(user.email), 'The account email remains private');
    assert.doesNotMatch(page.html, /data-user-rating=|name="currentPassword"|name="email"|name="theme"/);
    assert.doesNotMatch(page.html, /<form\b[^>]*action="\/(?:watchlist(?:\/remove)?|profile(?:\/avatar)?)"/);
}

async function account(name) {
    const client = browser();
    const email = `public-profile-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    const link = profile.html.match(/<a\b[^>]*href="\/users\/(\d+)"[^>]*class="account-user"/);
    assert.ok(link, 'The account avatar and name link to the public profile');
    return { client, name, email, id: Number(link[1]), token: csrf(profile) };
}

test('public user profiles expose identity, EXP and read-only watchlists while preserving private account data', async (t) => {
    const guest = browser();
    const name = 'Publiczny <svg onload=alert(1)> & Tester';
    const interests = 'Mecha <script>alert(1)</script> & fantasy';
    const first = await account(name);
    const second = await account('Odwiedzający profil');
    const route = '/users/' + first.id;
    const catalog = await guest.request('/anime?episodes_min=2');
    const candidates = [...catalog.html.matchAll(/<article\b[^>]*data-anime-id="(\d+)"[^>]*>/g)]
        .map(match => ({ id: Number(match[1]), episodes: Number(match[0].match(/data-episodes="(\d+)"/)?.[1]) }))
        .filter(anime => anime.episodes >= 2);
    assert.ok(candidates.length >= 2, 'Two series are available in the catalog');
    const [completed, watching] = candidates;
    const totalExp = completed.episodes * 5 + 110;

    await t.test('anonymous and signed-in visitors see a public empty profile without account settings', async () => {
        for (const client of [guest, second.client]) {
            const page = await client.request(route);
            privateDataHidden(page, first);
            statistics(page, 0, 0, 0, 0, 0);
            assert.deepEqual(entries(page), []);
            assert.match(page.html, /Napisz wiadomość/);
            assert.match(page.html, /Prywatne wiadomości nie są jeszcze dostępne/);
            assert.match(page.html, /<button\b[^>]*disabled[^>]*>Wyślij wiadomość/);
            assert.match(page.headers.get('cache-control'), /no-store/);
        }
        const own = await first.client.request(route);
        assert.match(own.html, /href="\/profile">Edytuj profil/);
        assert.doesNotMatch(own.html, /data-open-message/);
    });

    await t.test('public identity and interests escape HTML and private ratings remain hidden', async () => {
        redirected(await first.client.request('/profile', {
            name, email: first.email, interests, theme: 'dark', csrfToken: first.token,
        }), '/profile?saved=1');
        redirected(await first.client.request('/anime/' + completed.id + '/rating', {
            rating: '8', csrfToken: first.token,
        }), '/anime/' + completed.id + '?ratingSaved=1#anime-rating-title');
        const page = await guest.request(route);
        privateDataHidden(page, first);
        assert.match(page.html, /Publiczny &lt;svg onload=alert\(1\)&gt; &amp; Tester/);
        assert.match(page.html, /Mecha &lt;script&gt;alert\(1\)&lt;\/script&gt; &amp; fantasy/);
        assert.doesNotMatch(page.html, /<svg onload=|<script>alert\(1\)<\/script>/);
    });

    await t.test('statistics cover the full watchlist while status filters affect only visible entries', async () => {
        for (const [anime, status, watched] of [[completed, 'completed', 0], [watching, 'watching', 2]]) {
            redirected(await first.client.request('/watchlist', {
                animeId: anime.id, status, watched, csrfToken: first.token,
            }), '/watchlist');
        }
        const full = await guest.request(route);
        statistics(full, 2, 1, 1, completed.episodes + 2, totalExp);
        privateDataHidden(full, first);
        assert.equal(entries(full).length, 2);
        for (const [status, anime, watched] of [['completed', completed, completed.episodes], ['watching', watching, 2]]) {
            const filtered = await guest.request(route + '?status=' + status);
            statistics(filtered, 2, 1, 1, completed.episodes + 2, totalExp);
            assert.deepEqual(entries(filtered), [{ animeId: anime.id, status, watched }]);
        }
        const invalid = await guest.request(route + '?status[]=completed&page[]=2');
        statistics(invalid, 2, 1, 1, completed.episodes + 2, totalExp);
        assert.equal(entries(invalid).length, 2);
        statistics(await guest.request('/users/' + second.id), 0, 0, 0, 0, 0);
    });

    await t.test('forged target identifiers cannot change another user watchlist or EXP', async () => {
        redirected(await second.client.request('/watchlist', {
            animeId: watching.id, status: 'watching', watched: 1,
            userId: first.id, user_id: first.id, csrfToken: second.token,
        }), '/watchlist');
        statistics(await guest.request(route), 2, 1, 1, completed.episodes + 2, totalExp);
        statistics(await guest.request('/users/' + second.id), 1, 0, 1, 1, 5);
        assert.equal((await second.client.request(route, { name: 'Forged', csrfToken: second.token })).status, 404);
    });

    await t.test('invalid or missing users and unsupported writes return 404', async () => {
        for (const path of ['/users/0', '/users/2147483647', '/users/999999999999999999999999', '/users/abc', '/users/-1']) {
            assert.equal((await guest.request(path)).status, 404, path);
        }
        assert.equal((await guest.request(route, { name: 'Forged' })).status, 404);
    });
});
