const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Oceny-Testowe-2026!';
const missingId = Number.MAX_SAFE_INTEGER;

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

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

function rating(page) {
    const match = page.html.match(/\bdata-user-rating="(\d*)"/);
    assert.ok(match, 'The signed-in anime page exposes the own rating state');
    return match[1] === '' ? null : Number(match[1]);
}

function sourceScore(page) {
    const match = page.html.match(/<dt>Ocena<\/dt>\s*<dd>([^<]*)<\/dd>/);
    assert.ok(match, 'The source score remains present separately from the own rating');
    return match[1];
}

function watchlistEntries(page) {
    return [...page.html.matchAll(/data-anime-id="(\d+)"\s+data-watchlist-status="([^"]+)"\s+data-watched="(\d+)"/g)]
        .map(match => ({ animeId: Number(match[1]), status: match[2], watched: Number(match[3]) }));
}

function watchlistRating(page, animeId) {
    const opening = new RegExp('<li\\b[^>]*data-anime-id="' + animeId + '"[^>]*data-watchlist-status=');
    const start = page.html.search(opening);
    assert.ok(start >= 0, 'The rated anime is present on the watchlist');
    const remaining = page.html.slice(start);
    const nextEntry = remaining.slice(1).search(/<li\b[^>]*data-anime-id="\d+"[^>]*data-watchlist-status=/);
    const entry = nextEntry < 0 ? remaining : remaining.slice(0, nextEntry + 1);
    return rating({ html: entry });
}

function collectionAnimeIds(page) {
    return [...page.html.matchAll(/\bdata-collection-anime-id="(\d+)"/g)].map(match => Number(match[1]));
}

async function account(name) {
    const client = browser();
    const email = `ratings-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    assert.equal(profile.status, 200);
    return { client, email, token: csrf(profile) };
}

test('private anime ratings are validated, isolated and independent of watchlists and collections', async (t) => {
    const guest = browser();
    const catalog = await guest.request('/anime');
    assert.equal(catalog.status, 200);
    const candidates = [...catalog.html.matchAll(/<article\b[^>]*data-anime-id="(\d+)"[^>]*>/g)].map(match => {
        const episodes = match[0].match(/\bdata-episodes="(\d*)"/);
        assert.ok(episodes);
        return { id: Number(match[1]), episodes: episodes[1] === '' ? null : Number(episodes[1]) };
    });
    assert.ok(candidates.length >= 2, 'The catalog contains anime for the ratings scenarios');
    const anime = candidates.find(entry => entry.episodes === null || entry.episodes > 0);
    assert.ok(anime);
    const otherAnime = candidates.find(entry => entry.id !== anime.id);
    const animeRoute = '/anime/' + anime.id;
    const otherRoute = '/anime/' + otherAnime.id;
    const savedLocation = animeRoute + '?ratingSaved=1#anime-rating-title';
    const removedLocation = animeRoute + '?ratingRemoved=1#anime-rating-title';
    const watched = Math.min(3, anime.episodes ?? 3);
    const expectedWatchlist = [{ animeId: anime.id, status: 'watching', watched }];
    const originalSourceScore = sourceScore(await guest.request(animeRoute));
    let first;
    let second;
    let collectionId;

    await t.test('guests cannot submit ratings and see no private rating or rating forms', async () => {
        const page = await guest.request(animeRoute);
        assert.equal(page.status, 200);
        assert.doesNotMatch(page.html, /data-user-rating="\d+"/);
        assert.doesNotMatch(page.html, /<form\b[^>]*action="\/anime\/\d+\/rating(?:\/remove)?"/);
        redirected(await guest.request(animeRoute + '/rating', { rating: '8' }), '/login');
        redirected(await guest.request(animeRoute + '/rating/remove', {}), '/login');
        first = await account('Oceny A');
        second = await account('Oceny B');
        assert.equal(rating(await first.client.request(animeRoute)), null);
        assert.equal(rating(await second.client.request(animeRoute)), null);
    });

    await t.test('saving and removing both require a valid scalar CSRF token', async () => {
        for (const tokenFields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
            for (const suffix of ['/rating', '/rating/remove']) {
                const page = await first.client.request(animeRoute + suffix, { rating: '8', ...tokenFields });
                assert.equal(page.status, 403, suffix);
            }
        }
        assert.equal(rating(await first.client.request(animeRoute)), null);
    });

    await t.test('invalid ratings are rejected without creating data or reflecting executable HTML', async () => {
        for (const fields of [
            {}, { rating: '' }, { rating: '0' }, { rating: '11' }, { rating: '-1' },
            { rating: '1.5' }, { rating: 'NaN' }, { rating: '999999999999999999999999' },
            { 'rating[]': '8' }, { 'rating[score]': '8' }, { rating: '<script>alert(1)</script>' },
        ]) {
            const page = await first.client.request(animeRoute + '/rating', { csrfToken: first.token, ...fields });
            assert.equal(page.status, 422, JSON.stringify(fields));
            assert.doesNotMatch(page.html, /<script>alert\(1\)<\/script>/);
        }
        assert.equal(rating(await first.client.request(animeRoute)), null);
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), []);
    });

    await t.test('the full 1 to 10 scale is private and saving outside a watchlist does not add an entry', async () => {
        redirected(await first.client.request(animeRoute + '/rating', { rating: '1', csrfToken: first.token }), savedLocation);
        assert.equal(rating(await first.client.request(animeRoute)), 1);
        assert.equal(rating(await second.client.request(animeRoute)), null);
        redirected(await first.client.request(animeRoute + '/rating', {
            rating: '8', csrfToken: first.token, userId: '999999999', user_id: '999999999',
        }), savedLocation);
        redirected(await second.client.request(animeRoute + '/rating', { rating: '10', csrfToken: second.token }), savedLocation);
        assert.equal(rating(await first.client.request(animeRoute + '?user_id=999999999')), 8);
        assert.equal(rating(await second.client.request(animeRoute)), 10);
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), []);
        assert.deepEqual(watchlistEntries(await second.client.request('/watchlist')), []);
        const publicPage = await guest.request(animeRoute);
        assert.doesNotMatch(publicPage.html, /data-user-rating="\d+"/);
        assert.equal(sourceScore(publicPage), originalSourceScore);
    });

    await t.test('rated titles show the own score on the watchlist and profile without changing progress or collections', async () => {
        redirected(await first.client.request('/watchlist', {
            animeId: anime.id, status: 'watching', watched, csrfToken: first.token,
        }), '/watchlist');
        redirected(await second.client.request('/watchlist', {
            animeId: anime.id, mode: 'add', csrfToken: second.token,
        }), '/watchlist');
        const collections = await first.client.request('/profile/collections');
        collectionId = Number(collections.html.match(/data-collection-id="(\d+)"/)[1]);
        const selection = new URLSearchParams({ csrfToken: first.token });
        selection.append('collectionIds[]', String(collectionId));
        redirected(await first.client.request(animeRoute + '/collections', selection), animeRoute + '?collectionSaved=1');
        assert.equal(watchlistRating(await first.client.request('/watchlist'), anime.id), 8);
        assert.equal(watchlistRating(await first.client.request('/profile'), anime.id), 8);
        assert.equal(watchlistRating(await second.client.request('/watchlist'), anime.id), 10);
        redirected(await first.client.request(animeRoute + '/rating', { rating: '9', csrfToken: first.token }), savedLocation);
        assert.equal(watchlistRating(await first.client.request('/watchlist'), anime.id), 9);
        assert.equal(rating(await second.client.request(animeRoute)), 10);
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), expectedWatchlist);
        assert.deepEqual(collectionAnimeIds(await first.client.request('/profile/collections/' + collectionId)), [anime.id]);
        assert.equal(sourceScore(await first.client.request(animeRoute)), originalSourceScore);
    });

    await t.test('unknown anime cannot be rated or removed and invalid writes preserve an existing rating', async () => {
        for (const suffix of ['/rating', '/rating/remove']) {
            assert.equal((await first.client.request('/anime/' + missingId + suffix, {
                rating: '8', csrfToken: first.token,
            })).status, 404);
        }
        assert.equal((await first.client.request(animeRoute + '/rating', { rating: '11', csrfToken: first.token })).status, 422);
        assert.equal((await first.client.request(animeRoute + '/rating/remove', { csrfToken: 'wrong' })).status, 403);
        assert.equal(rating(await first.client.request(animeRoute)), 9);
        assert.equal(rating(await second.client.request(animeRoute)), 10);
    });

    await t.test('removal affects only the signed-in rating and is idempotent', async () => {
        for (let attempt = 0; attempt < 2; attempt++) {
            redirected(await first.client.request(animeRoute + '/rating/remove', {
                csrfToken: first.token, userId: '999999999', user_id: '999999999',
            }), removedLocation);
            assert.equal(rating(await first.client.request(animeRoute)), null);
            assert.equal(watchlistRating(await first.client.request('/watchlist'), anime.id), null);
            assert.equal(rating(await second.client.request(animeRoute)), 10);
        }
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), expectedWatchlist);
        assert.deepEqual(collectionAnimeIds(await first.client.request('/profile/collections/' + collectionId)), [anime.id]);
        assert.equal(sourceScore(await first.client.request(animeRoute)), originalSourceScore);
    });

    await t.test('rating survives watchlist removal and a fresh login without exposing another account data', async () => {
        redirected(await first.client.request(animeRoute + '/rating', { rating: '7', csrfToken: first.token }), savedLocation);
        redirected(await first.client.request(otherRoute + '/rating', {
            rating: '4', csrfToken: first.token,
        }), otherRoute + '?ratingSaved=1#anime-rating-title');
        redirected(await first.client.request('/watchlist/remove', { animeId: anime.id, csrfToken: first.token }), '/watchlist');
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), []);
        assert.equal(rating(await first.client.request(animeRoute)), 7);
        assert.deepEqual(collectionAnimeIds(await first.client.request('/profile/collections/' + collectionId)), [anime.id]);
        redirected(await first.client.request('/logout', { csrfToken: first.token }), '/login');
        assert.doesNotMatch((await first.client.request(animeRoute)).html, /data-user-rating="\d+"/);
        const loginToken = csrf(await first.client.request('/login'));
        redirected(await first.client.request('/login', { email: first.email, password, csrfToken: loginToken }), '/projects');
        assert.equal(rating(await first.client.request(animeRoute)), 7);
        assert.equal(rating(await first.client.request(otherRoute)), 4);
        assert.equal(rating(await second.client.request(animeRoute)), 10);
        assert.equal(rating(await second.client.request(otherRoute)), null);
        assert.equal(sourceScore(await guest.request(animeRoute)), originalSourceScore);
    });
});
