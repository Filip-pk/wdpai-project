const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');
const { createAnimeCommunityFixtures, cleanupAnimeCommunityFixtures } = require('./helpers/anime-community-fixtures.cjs');

const base = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Spolecznosc-Oceny-2026!';

function browser() {
    let cookie = '';
    return { async request(route, fields) {
        const response = await fetch(new URL(route, base), {
            method: fields === undefined ? 'GET' : 'POST', headers: cookie ? { Cookie: cookie } : {},
            body: fields === undefined ? undefined : new URLSearchParams(fields), redirect: 'manual', signal: AbortSignal.timeout(10000),
        });
        for (const value of response.headers.getSetCookie()) if (value.startsWith('PHPSESSID=')) cookie = value.split(';')[0];
        const html = await response.text();
        assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
        return { status: response.status, location: response.headers.get('location'), html };
    } };
}
function csrf(page) { const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/); assert.ok(match); return match[1]; }
function redirected(page, destination) { assert.equal(page.status, 303); if (destination) assert.equal(page.location, destination); }
async function account(label) {
    const client = browser(), email = 'community-ratings-' + randomUUID() + '@example.test', name = label + ' ' + randomUUID();
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }));
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    return { client, email, name, id: Number(profile.html.match(/data-account-id="(\d+)"/)[1]), token: csrf(profile) };
}
function summary(page, animeId) {
    assert.equal(page.status, 200);
    const tags = [...page.html.matchAll(new RegExp('<[^>]+\\bdata-community-anime-id="' + animeId + '"[^>]*>', 'g'))];
    assert.ok(tags.length > 0, 'The page exposes the community summary for anime ' + animeId);
    const values = tags.map(([tag]) => {
        const average = tag.match(/data-community-rating-average="([^"]*)"/), count = tag.match(/data-community-rating-count="(\d+)"/);
        assert.ok(average && count, 'Average and count accompany the anime identity');
        return { average: average[1] === '' ? null : Number(average[1]), count: Number(count[1]) };
    });
    for (const value of values) assert.deepEqual(value, values[0], 'Duplicate summaries remain consistent');
    return values[0];
}
function personalRating(page) {
    const match = page.html.match(/data-user-rating="(\d*)"/);
    assert.ok(match);
    return match[1] === '' ? null : Number(match[1]);
}
function sourceScore(page) {
    const match = page.html.match(/<dt>Ocena(?: źródłowa)?<\/dt>\s*<dd>([^<]*)<\/dd>/);
    assert.ok(match, 'The imported score has its own label and value');
    return match[1];
}

test('community anime averages are public while individual ratings remain owned and source scores unchanged', async t => {
    const fixture = createAnimeCommunityFixtures({ count: 2 });
    const [anime, unrated] = fixture.animes, route = '/anime/' + anime.id;
    const guest = browser();
    let first, second, initialSource;
    const save = async (user, value, extra = {}) => redirected(await user.client.request(route + '/rating', { rating: String(value), csrfToken: user.token, ...extra }), route + '?ratingSaved=1#anime-rating-title');
    const publicSummary = async () => summary(await guest.request(route), anime.id);
    try {
        first = await account('Ocena społeczności A'); second = await account('Ocena społeczności B');
        await t.test('an unrated title has no community average even when its source score exists', async () => {
            const page = await guest.request(route); initialSource = sourceScore(page);
            assert.match(initialSource, /9,25/);
            assert.deepEqual(summary(page, anime.id), { average: null, count: 0 });
            assert.match(page.html, /Brak ocen/);
            assert.doesNotMatch(page.html, /data-user-rating=/);
            assert.deepEqual(summary(await guest.request('/anime?q=' + fixture.marker), anime.id), { average: null, count: 0 });
            assert.deepEqual(summary(await guest.request('/anime/' + unrated.id), unrated.id), { average: null, count: 0 });
        });
        await t.test('the first score adds one vote and repeating it never adds a second vote', async () => {
            await save(first, 8);
            assert.deepEqual(await publicSummary(), { average: 8, count: 1 });
            await save(first, 8);
            assert.deepEqual(await publicSummary(), { average: 8, count: 1 });
            assert.equal(personalRating(await first.client.request(route)), 8);
            assert.equal(personalRating(await second.client.request(route)), null);
        });
        await t.test('two account contributions form the same public average on the catalog and detail page', async () => {
            await save(second, 4, { userId: first.id, user_id: first.id });
            assert.deepEqual(await publicSummary(), { average: 6, count: 2 });
            assert.deepEqual(summary(await guest.request('/anime?q=' + fixture.marker), anime.id), { average: 6, count: 2 });
            assert.equal(personalRating(await first.client.request(route)), 8);
            assert.equal(personalRating(await second.client.request(route)), 4);
            const page = await guest.request(route);
            for (const privateValue of [first.email, second.email, first.name, second.name]) assert.ok(!page.html.includes(privateValue));
            assert.doesNotMatch(page.html, /data-user-rating=/);
            assert.equal(sourceScore(page), initialSource);
        });
        await t.test('guest writes, missing or malformed CSRF and invalid values cannot change the average', async () => {
            redirected(await guest.request(route + '/rating', { rating: '10' }), '/login');
            redirected(await guest.request(route + '/rating/remove', {}), '/login');
            for (const fields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
                for (const suffix of ['/rating', '/rating/remove']) assert.equal((await first.client.request(route + suffix, { rating: '10', ...fields })).status, 403);
            }
            for (const fields of [{ rating: '0' }, { rating: '11' }, { rating: '2.5' }, { 'rating[]': '10' }]) {
                assert.equal((await first.client.request(route + '/rating', { ...fields, csrfToken: first.token })).status, 422);
            }
            assert.deepEqual(await publicSummary(), { average: 6, count: 2 });
        });
        await t.test('watchlists, public profiles and private collections use the same community summary', async () => {
            redirected(await first.client.request('/watchlist', { animeId: anime.id, status: 'watching', watched: '2', csrfToken: first.token }), '/watchlist');
            const collections = await first.client.request('/profile/collections');
            const collectionId = Number(collections.html.match(/data-collection-id="(\d+)"/)[1]);
            const selection = new URLSearchParams({ csrfToken: first.token }); selection.append('collectionIds[]', String(collectionId));
            redirected(await first.client.request(route + '/collections', selection), route + '?collectionSaved=1');
            for (const destination of ['/profile', '/watchlist', '/users/' + first.id, '/profile/collections/' + collectionId]) {
                assert.deepEqual(summary(await first.client.request(destination), anime.id), { average: 6, count: 2 }, destination);
            }
            const publicProfile = await guest.request('/users/' + first.id);
            assert.deepEqual(summary(publicProfile, anime.id), { average: 6, count: 2 });
            assert.doesNotMatch(publicProfile.html, /data-user-rating=/);
            redirected(await guest.request('/profile/collections/' + collectionId), '/login');
            const dashboard = await first.client.request('/dashboard');
            const dashboardIds = [...new Set([...dashboard.html.matchAll(/data-community-anime-id="(\d+)"/g)].map(item => Number(item[1])))];
            assert.ok(dashboardIds.length > 0, 'Dashboard recommendation cards include community summaries');
            for (const id of dashboardIds) assert.deepEqual(summary(dashboard, id), summary(await guest.request('/anime/' + id), id));
        });
        await t.test('updating a score replaces its contribution without changing the count or source score', async () => {
            await save(first, 10);
            assert.deepEqual(await publicSummary(), { average: 7, count: 2 });
            assert.equal(personalRating(await first.client.request(route)), 10);
            assert.equal(personalRating(await second.client.request(route)), 4);
            assert.deepEqual(summary(await first.client.request('/watchlist'), anime.id), { average: 7, count: 2 });
            assert.equal(sourceScore(await guest.request(route)), initialSource);
        });
        await t.test('removing a score is idempotent and removing the final score restores an empty average', async () => {
            for (let attempt = 0; attempt < 2; attempt++) {
                redirected(await second.client.request(route + '/rating/remove', { csrfToken: second.token, userId: first.id }), route + '?ratingRemoved=1#anime-rating-title');
                assert.deepEqual(await publicSummary(), { average: 10, count: 1 });
                assert.equal(personalRating(await first.client.request(route)), 10);
            }
            redirected(await first.client.request(route + '/rating/remove', { csrfToken: first.token }), route + '?ratingRemoved=1#anime-rating-title');
            assert.deepEqual(await publicSummary(), { average: null, count: 0 });
            assert.deepEqual(summary(await guest.request('/anime?q=' + fixture.marker), anime.id), { average: null, count: 0 });
            assert.equal(sourceScore(await guest.request(route)), initialSource);
        });
    } finally {
        const removed = cleanupAnimeCommunityFixtures(fixture);
        assert.equal(removed.removedAnimeCount, fixture.animes.length, 'Only the recorded fixture anime are removed');
        assert.equal(removed.removedGenreCount, 1, 'Only the recorded fixture genre is removed');
    }
});
