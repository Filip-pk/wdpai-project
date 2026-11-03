const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Kolekcje-Testowe-2026!';
const missingId = Number.MAX_SAFE_INTEGER;

function browser() {
    let cookie = '';

    return {
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: cookie ? { Cookie: cookie } : {},
                body: fields === undefined ? undefined : new URLSearchParams(fields),
                redirect: 'manual',
                signal: AbortSignal.timeout(10000),
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

function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match, 'The form includes a CSRF token');
    return match[1];
}

function redirected(page, path) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), path);
}

function collectionIds(page) {
    return [...page.html.matchAll(/\bdata-collection-id="(\d+)"/g)].map(match => Number(match[1]));
}

function animeIds(page) {
    return [...page.html.matchAll(/\bdata-collection-anime-id="(\d+)"/g)].map(match => Number(match[1]));
}

function catalogEntries(page) {
    return [...page.html.matchAll(/<article\b[^>]*\bdata-anime-id="(\d+)"[^>]*>/g)].map(match => {
        const episodes = match[0].match(/\bdata-episodes="(\d*)"/);
        assert.ok(episodes, 'Catalog cards expose known or unknown episode counts');
        return { id: Number(match[1]), episodes: episodes[1] === '' ? null : Number(episodes[1]) };
    });
}

function pickerCollectionIds(page) {
    return [...page.html.matchAll(/<input\b[^>]*>/g)]
        .filter(match => /\bname="collectionIds\[\]"/.test(match[0]))
        .map(match => Number(match[0].match(/\bvalue="(\d+)"/)?.[1]));
}

function watchlistEntries(page) {
    return [...page.html.matchAll(/data-anime-id="(\d+)"\s+data-watchlist-status="([^"]+)"\s+data-watched="(\d+)"/g)]
        .map(match => ({ animeId: Number(match[1]), status: match[2], watched: Number(match[3]) }));
}

function selection(token, ids, additional = {}) {
    const fields = new URLSearchParams({ csrfToken: token, ...additional });
    for (const id of ids) fields.append('collectionIds[]', String(id));
    return fields;
}

async function account(name) {
    const client = browser();
    const email = `collections-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const page = await client.request('/profile/collections');
    assert.equal(page.status, 200);
    assert.equal(collectionIds(page).length, 1, 'A new account starts with one collection');
    assert.ok(page.html.includes('moja kolekcja'));
    return { client, email, token: csrf(page), defaultId: collectionIds(page)[0] };
}

test('private anime collections persist independently of watchlist progress', async (t) => {
    const guest = browser();
    const catalog = await guest.request('/anime');
    assert.equal(catalog.status, 200);
    const candidates = catalogEntries(catalog);
    assert.ok(candidates.length >= 2, 'The catalog has two anime to organize');
    const firstAnime = candidates.find(anime => anime.episodes === null || anime.episodes > 0);
    assert.ok(firstAnime, 'An existing anime can hold a nonzero watching progress');
    const firstAnimeId = firstAnime.id;
    const secondAnimeId = candidates.find(anime => anime.id !== firstAnimeId).id;
    const firstAnimeRoute = '/anime/' + firstAnimeId;
    const secondAnimeRoute = '/anime/' + secondAnimeId;
    const bothAnimeIds = [firstAnimeId, secondAnimeId].sort((a, b) => a - b);
    const watched = Math.min(3, firstAnime.episodes ?? 3);
    const expectedWatchlist = [{ animeId: firstAnimeId, status: 'watching', watched }];
    let first;
    let second;
    let customId;
    let createdWithAnimeId;
    const customName = 'Weekend <svg onload=alert(1)> & powroty';
    const customDescription = 'Żółte liście <script>alert(1)</script> & anime ze znajomymi';

    await t.test('guests cannot read or mutate collections', async () => {
        for (const route of ['/profile/collections', '/profile/collections/1']) {
            redirected(await guest.request(route), '/login');
            redirected(await guest.request(route, { name: 'Próba', csrfToken: 'wrong' }), '/login');
        }
        for (const route of [
            '/profile/collections/1/remove', firstAnimeRoute + '/collections', firstAnimeRoute + '/collections/create',
        ]) {
            redirected(await guest.request(route, { name: 'Próba', csrfToken: 'wrong' }), '/login');
        }
        const anime = await guest.request(firstAnimeRoute);
        assert.equal(anime.status, 200);
        assert.ok(anime.html.includes('/login'));
        first = await account('Kolekcje A');
        second = await account('Kolekcje B');
        assert.notEqual(first.defaultId, second.defaultId);
    });

    await t.test('the default collection is created once and profile links to collections', async () => {
        for (const user of [first, second]) {
            for (let visit = 0; visit < 2; visit++) {
                const page = await user.client.request('/profile/collections');
                assert.equal(page.status, 200);
                assert.deepEqual(collectionIds(page), [user.defaultId]);
                assert.deepEqual(animeIds(await user.client.request('/profile/collections/' + user.defaultId)), []);
            }
            assert.match((await user.client.request('/profile')).html, /href="\/profile\/collections"/);
            assert.match((await user.client.request('/watchlist')).html, /href="\/profile\/collections"/);
        }
    });

    await t.test('every mutation requires a valid scalar CSRF token', async () => {
        for (const tokenFields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
            const fields = { name: 'Nieutworzona', description: '', animeId: firstAnimeId, ...tokenFields };
            for (const route of [
                '/profile/collections', '/profile/collections/' + first.defaultId,
                '/profile/collections/' + first.defaultId + '/remove',
                firstAnimeRoute + '/collections', firstAnimeRoute + '/collections/create',
            ]) {
                const page = await first.client.request(route, fields);
                assert.equal(page.status, 403, route);
            }
        }
        assert.deepEqual(collectionIds(await first.client.request('/profile/collections')), [first.defaultId]);
        assert.deepEqual(animeIds(await first.client.request('/profile/collections/' + first.defaultId)), []);
    });

    await t.test('name and description validation rejects invalid input and preserves escaped form values', async () => {
        for (const invalid of [
            { name: '' }, { name: '   ' }, { name: 'ż'.repeat(121) },
            { name: 'Kolekcja\0testowa' }, { description: 'ą'.repeat(501) },
            { 'name[]': 'Nieprawidłowa' }, { 'description[]': 'Nieprawidłowy opis' },
        ]) {
            const fields = { description: '', csrfToken: first.token, ...invalid };
            if (!Object.hasOwn(fields, 'name') && !Object.hasOwn(fields, 'name[]')) fields.name = 'Poprawna';
            assert.equal((await first.client.request('/profile/collections', fields)).status, 422);
        }
        const escaped = await first.client.request('/profile/collections', {
            name: '', description: customDescription, csrfToken: first.token,
        });
        assert.equal(escaped.status, 422);
        assert.ok(escaped.html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
        assert.ok(!escaped.html.includes('<script>alert(1)</script>'));
        const anime = await first.client.request(firstAnimeRoute + '/collections/create', {
            name: '', description: customDescription, csrfToken: first.token,
        });
        assert.equal(anime.status, 422);
        assert.ok(anime.html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
        assert.deepEqual(collectionIds(await first.client.request('/profile/collections')), [first.defaultId]);
    });

    await t.test('custom collection names and descriptions are saved safely for their owner', async () => {
        const created = await first.client.request('/profile/collections', {
            name: customName, description: customDescription, csrfToken: first.token,
            userId: second.defaultId, user_id: '999999999',
        });
        assert.equal(created.status, 303);
        const location = created.headers.get('location');
        const match = location?.match(/^\/profile\/collections\/(\d+)$/);
        assert.ok(match, 'Creating a collection opens its detail page');
        customId = Number(match[1]);
        const detail = await first.client.request(location);
        assert.equal(detail.status, 200);
        assert.ok(detail.html.includes('Weekend &lt;svg onload=alert(1)&gt; &amp; powroty'));
        assert.ok(detail.html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
        assert.ok(!detail.html.includes('<svg onload=alert(1)>'));
        assert.ok(!detail.html.includes('<script>alert(1)</script>'));
        const own = await first.client.request('/profile/collections?user_id=999999999');
        assert.deepEqual(collectionIds(own).sort((a, b) => a - b), [first.defaultId, customId].sort((a, b) => a - b));
        assert.deepEqual(collectionIds(await second.client.request('/profile/collections')), [second.defaultId]);
    });

    await t.test('another user cannot read, rename, populate or remove from a private collection', async () => {
        for (const id of [customId, first.defaultId, missingId]) {
            const page = await second.client.request('/profile/collections/' + id);
            assert.equal(page.status, 404);
            assert.ok(!page.html.includes(customName));
            assert.deepEqual(animeIds(page), []);
            assert.equal((await second.client.request('/profile/collections/' + id, {
                name: 'Przejęta', description: '', csrfToken: second.token,
            })).status, 404);
            assert.equal((await second.client.request('/profile/collections/' + id + '/remove', {
                animeId: firstAnimeId, csrfToken: second.token,
            })).status, 404);
            assert.equal((await second.client.request(firstAnimeRoute + '/collections', selection(second.token, [id]))).status, 404);
        }
        const mixed = await first.client.request(secondAnimeRoute + '/collections', selection(first.token, [first.defaultId, second.defaultId]));
        assert.equal(mixed.status, 404, 'A mixed selection rejects all additions');
        assert.deepEqual(animeIds(await first.client.request('/profile/collections/' + first.defaultId)), []);
        assert.deepEqual(animeIds(await second.client.request('/profile/collections/' + second.defaultId)), []);
    });

    await t.test('one anime can be added to multiple collections repeatedly without changing watchlist progress', async () => {
        redirected(await first.client.request('/watchlist', {
            animeId: firstAnimeId, status: 'watching', watched, csrfToken: first.token,
        }), '/watchlist');
        assert.equal((await first.client.request(firstAnimeRoute + '/collections', { csrfToken: first.token })).status, 422);
        assert.equal((await first.client.request(firstAnimeRoute + '/collections', {
            collectionIds: String(customId), csrfToken: first.token,
        })).status, 422);
        for (let attempt = 0; attempt < 2; attempt++) {
            redirected(await first.client.request(firstAnimeRoute + '/collections', selection(first.token, [first.defaultId, customId], {
                userId: '999999999', user_id: '999999999',
            })), firstAnimeRoute + '?collectionSaved=1');
            for (const id of [first.defaultId, customId]) {
                assert.deepEqual(animeIds(await first.client.request('/profile/collections/' + id)), [firstAnimeId]);
            }
        }
        redirected(await first.client.request(secondAnimeRoute + '/collections', selection(first.token, [customId])), secondAnimeRoute + '?collectionSaved=1');
        assert.deepEqual(animeIds(await first.client.request('/profile/collections/' + customId)).sort((a, b) => a - b), bothAnimeIds);
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), expectedWatchlist);
        assert.deepEqual(watchlistEntries(await second.client.request('/watchlist')), []);
        assert.deepEqual(animeIds(await second.client.request('/profile/collections/' + second.defaultId)), []);
        const picker = await first.client.request(firstAnimeRoute);
        assert.equal(picker.status, 200);
        assert.ok(picker.html.includes('Weekend &lt;svg onload=alert(1)&gt; &amp; powroty'));
        assert.ok(!pickerCollectionIds(picker).includes(second.defaultId), 'The picker only includes the signed-in user collections');
    });

    await t.test('renaming a collection and removing an anime preserves other memberships and watchlist', async () => {
        const route = '/profile/collections/' + customId;
        assert.equal((await first.client.request(route, {
            name: '', description: '', csrfToken: first.token,
        })).status, 422);
        redirected(await first.client.request(route, {
            name: 'Anime na weekend', description: 'Prywatny wybór na sobotę.',
            csrfToken: first.token, user_id: '999999999',
        }), route);
        const saved = await first.client.request(route);
        assert.equal(saved.status, 200);
        assert.ok(saved.html.includes('Anime na weekend'));
        assert.ok(saved.html.includes('Prywatny wybór na sobotę.'));
        assert.deepEqual(animeIds(saved).sort((a, b) => a - b), bothAnimeIds);
        redirected(await first.client.request('/profile/collections/' + first.defaultId + '/remove', {
            animeId: firstAnimeId, csrfToken: first.token, userId: '999999999',
        }), '/profile/collections/' + first.defaultId);
        assert.deepEqual(animeIds(await first.client.request('/profile/collections/' + first.defaultId)), []);
        assert.deepEqual(animeIds(await first.client.request(route)).sort((a, b) => a - b), bothAnimeIds);
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), expectedWatchlist);
    });

    await t.test('a new collection can be created directly from an anime page', async () => {
        redirected(await first.client.request(secondAnimeRoute + '/collections/create', {
            name: 'Filmy i powroty', description: 'Odkrycia z katalogu.', csrfToken: first.token,
            user_id: '999999999',
        }), secondAnimeRoute + '?collectionSaved=1');
        const list = await first.client.request('/profile/collections');
        assert.equal(collectionIds(list).length, 3);
        createdWithAnimeId = collectionIds(list).find(id => id !== first.defaultId && id !== customId);
        assert.ok(createdWithAnimeId);
        const detail = await first.client.request('/profile/collections/' + createdWithAnimeId);
        assert.equal(detail.status, 200);
        assert.ok(detail.html.includes('Filmy i powroty'));
        assert.deepEqual(animeIds(detail), [secondAnimeId]);
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), expectedWatchlist);
        assert.equal((await first.client.request('/anime/' + missingId + '/collections/create', {
            name: 'Bez anime', description: '', csrfToken: first.token,
        })).status, 404);
        assert.equal(collectionIds(await first.client.request('/profile/collections')).length, 3);
        assert.deepEqual(collectionIds(await second.client.request('/profile/collections')), [second.defaultId]);
    });

    await t.test('collections and progress survive logout without duplicating the default collection', async () => {
        const before = collectionIds(await first.client.request('/profile/collections')).sort((a, b) => a - b);
        redirected(await first.client.request('/logout', { csrfToken: first.token }), '/login');
        redirected(await first.client.request('/profile/collections'), '/login');
        const token = csrf(await first.client.request('/login'));
        redirected(await first.client.request('/login', {
            email: first.email, password, csrfToken: token,
        }), '/projects');
        const list = await first.client.request('/profile/collections');
        assert.equal(list.status, 200);
        assert.deepEqual(collectionIds(list).sort((a, b) => a - b), before);
        assert.ok(list.html.includes('moja kolekcja'));
        assert.deepEqual(animeIds(await first.client.request('/profile/collections/' + customId)).sort((a, b) => a - b), bothAnimeIds);
        assert.deepEqual(animeIds(await first.client.request('/profile/collections/' + createdWithAnimeId)), [secondAnimeId]);
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), expectedWatchlist);
        assert.deepEqual(collectionIds(await second.client.request('/profile/collections')), [second.defaultId]);
    });
});
