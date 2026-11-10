const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');
const { createAnimeCommunityFixtures, cleanupAnimeCommunityFixtures } = require('./helpers/anime-community-fixtures.cjs');

const base = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Rekomendacje-Test-2026!';

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
function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match); return match[1];
}
function redirected(page) { assert.equal(page.status, 303); }
async function account(label) {
    const client = browser(), email = 'recommendations-' + randomUUID() + '@example.test', name = label + ' ' + randomUUID();
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }));
    redirected(await client.request('/login', { email, password, csrfToken: token }));
    const profile = await client.request('/profile');
    return { client, email, name, token: csrf(profile), id: Number(profile.html.match(/data-account-id="(\d+)"/)[1]) };
}
function recommendations(page) {
    assert.equal(page.status, 200);
    const mode = page.html.match(/data-recommendation-mode="([^"]+)"/);
    const sourceCount = page.html.match(/data-recommendation-source-count="(\d+)"/);
    assert.ok(mode && sourceCount);
    return {
        mode: mode[1], sourceCount: Number(sourceCount[1]),
        ids: [...page.html.matchAll(/data-recommended-anime-id="(\d+)"/g)].map(item => Number(item[1])),
        genres: [...page.html.matchAll(/data-preferred-genre-id="(\d+)"/g)].map(item => Number(item[1])),
    };
}

test('dashboard recommendations follow the current account watchlist and ratings without AI', async t => {
    const fixtures = [];
    const watch = async (user, anime, status = 'completed') => redirected(await user.client.request('/watchlist', {
        animeId: String(anime.id), status, watched: status === 'completed' ? '12' : '0', csrfToken: user.token,
    }));
    const rate = async (user, anime, rating) => redirected(await user.client.request('/anime/' + anime.id + '/rating', {
        rating: String(rating), csrfToken: user.token,
    }));
    const remove = async (user, anime) => redirected(await user.client.request('/watchlist/remove', {
        animeId: String(anime.id), csrfToken: user.token,
    }));
    try {
        fixtures.push(createAnimeCommunityFixtures({ count: 8 }));
        fixtures.push(createAnimeCommunityFixtures({ count: 8 }));
        const [action, romance] = fixtures;
        const first = await account('Preferencje A'), second = await account('Preferencje B'), guest = browser();

        await t.test('guests and empty accounts see honestly labelled starter suggestions', async () => {
            const guestPage = await guest.request('/dashboard?user_id=' + first.id);
            const publicResult = recommendations(guestPage);
            assert.equal(publicResult.mode, 'guest'); assert.equal(publicResult.sourceCount, 0);
            assert.ok(publicResult.ids.length > 0 && publicResult.ids.length <= 6);
            assert.deepEqual(publicResult.genres, []);
            assert.ok(!guestPage.html.includes(first.email) && !guestPage.html.includes(first.name));
            const page = await first.client.request('/dashboard'), result = recommendations(page);
            assert.equal(result.mode, 'starter'); assert.equal(result.sourceCount, 0); assert.deepEqual(result.genres, []);
            assert.match(page.html, /propozycje|propozycj/i);
            assert.match(page.html, /\/css\/dashboard\.css\?v=[a-f0-9]{12}/);
            assert.equal((await guest.request('/dashboard', {})).status, 404, 'Recommendations are read-only');
        });

        await watch(first, action.animes[0]); await rate(first, action.animes[0], 10);
        await watch(second, romance.animes[0], 'planned');
        await t.test('different private watchlists give different recommendations, explanations and stable order', async () => {
            for (const [user, fixture] of [[first, action], [second, romance]]) {
                const page = await user.client.request('/dashboard'), result = recommendations(page);
                assert.equal(result.mode, 'personalized'); assert.equal(result.sourceCount, 1);
                assert.deepEqual(result.genres, [fixture.genreId]);
                assert.deepEqual(result.ids, fixture.animes.slice(1, 7).map(anime => anime.id));
                assert.ok(page.html.includes(fixture.genreName));
                assert.match(page.html, /Pasuje do Twoich gustów/);
                assert.deepEqual(recommendations(await user.client.request('/dashboard')), result);
                assert.deepEqual(recommendations(await user.client.request('/dashboard?user_id=' + (user === first ? second.id : first.id))), result);
                const other = user === first ? second : first;
                assert.ok(!page.html.includes(other.email) && !page.html.includes(other.name));
                assert.doesNotMatch(page.html, /data-user-rating=/);
                for (const id of result.ids) assert.ok(page.html.includes('data-community-anime-id="' + id + '"'));
            }
        });

        await t.test('every own watchlist status excludes the title immediately', async () => {
            for (const [index, status] of ['planned', 'watching', 'on_hold', 'dropped'].entries()) await watch(first, action.animes[index + 1], status);
            const result = recommendations(await first.client.request('/dashboard'));
            assert.equal(result.sourceCount, 5); assert.equal(result.mode, 'personalized');
            assert.deepEqual(result.ids, action.animes.slice(5).map(anime => anime.id));
            assert.deepEqual(recommendations(await second.client.request('/dashboard')).ids, romance.animes.slice(1, 7).map(anime => anime.id));
        });

        await t.test('a low own score changes preferences without changing another account', async () => {
            await rate(first, action.animes[0], 1);
            const result = recommendations(await first.client.request('/dashboard'));
            assert.equal(result.mode, 'starter'); assert.deepEqual(result.genres, []);
            assert.ok(result.ids.every(id => !action.animes.some(anime => anime.id === id)), 'Negative genres do not become starter suggestions');
            assert.deepEqual(recommendations(await second.client.request('/dashboard')).ids, romance.animes.slice(1, 7).map(anime => anime.id));
            await rate(first, action.animes[0], 10);
            assert.equal(recommendations(await first.client.request('/dashboard')).mode, 'personalized');
        });

        await t.test('exhausted matches show an empty state and removing entries makes them eligible again', async () => {
            for (const anime of action.animes.slice(5)) await watch(first, anime, 'planned');
            const page = await first.client.request('/dashboard'), result = recommendations(page);
            assert.equal(result.mode, 'personalized'); assert.deepEqual(result.ids, []);
            assert.match(page.html, /Brak|brakuje|Nie ma|nie znaleźliśmy/i);
            await remove(first, action.animes[7]);
            assert.deepEqual(recommendations(await first.client.request('/dashboard')).ids, [action.animes[7].id]);
        });

        await t.test('ratings outside the watchlist do not keep a removed preference alive', async () => {
            for (const anime of action.animes.slice(0, 7)) await remove(first, anime);
            const result = recommendations(await first.client.request('/dashboard'));
            assert.equal(result.mode, 'starter'); assert.equal(result.sourceCount, 0); assert.deepEqual(result.genres, []);
            assert.equal(recommendations(await second.client.request('/dashboard')).mode, 'personalized');
        });
    } finally {
        for (const fixture of fixtures) {
            const removed = cleanupAnimeCommunityFixtures(fixture);
            assert.equal(removed.removedAnimeCount, fixture.animes.length);
            assert.equal(removed.removedGenreCount, 1);
        }
    }
});
