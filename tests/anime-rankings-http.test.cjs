const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');
const { createAnimeCommunityFixtures, cleanupAnimeCommunityFixtures } = require('./helpers/anime-community-fixtures.cjs');

const base = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Rankingi-Anime-Test-2026!';

function browser() {
    let cookie = '';
    return { async request(route, fields) {
        const response = await fetch(new URL(route, base), {
            method: fields === undefined ? 'GET' : 'POST',
            headers: cookie ? { Cookie: cookie } : {},
            body: fields === undefined ? undefined : new URLSearchParams(fields),
            redirect: 'manual', signal: AbortSignal.timeout(10000),
        });
        for (const value of response.headers.getSetCookie()) if (value.startsWith('PHPSESSID=')) cookie = value.split(';')[0];
        const html = await response.text();
        assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
        return { html, status: response.status, location: response.headers.get('location') };
    } };
}

function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match); return match[1];
}

function redirected(page) { assert.equal(page.status, 303); }

async function account(label) {
    const client = browser(), email = 'anime-ranking-' + randomUUID() + '@example.test', name = label + ' ' + randomUUID();
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }));
    redirected(await client.request('/login', { email, password, csrfToken: token }));
    const profile = await client.request('/profile');
    return { client, email, name, token: csrf(profile), id: Number(profile.html.match(/data-account-id="(\d+)"/)[1]) };
}

function ranking(page) {
    assert.equal(page.status, 200);
    const wrapper = page.html.match(/<[^>]+\bdata-ranking-mode="([^"]+)"[^>]*>/);
    assert.ok(wrapper, 'A public ranking exposes its paging state');
    const number = name => {
        const match = wrapper[0].match(new RegExp('data-ranking-' + name + '="(\\d+)"'));
        assert.ok(match); return Number(match[1]);
    };
    const entries = [...page.html.matchAll(/<li\b[^>]*\bdata-ranked-anime-id="(\d+)"[^>]*>/g)].map(([tag, id]) => {
        const value = name => {
            const match = tag.match(new RegExp('data-' + name + '="([^"]*)"'));
            assert.ok(match, 'A ranked entry exposes ' + name); return match[1];
        };
        const average = value('community-average');
        return { id: Number(id), rank: Number(value('rank')), average: average === '' ? null : Number(average),
            count: Number(value('community-count')), watchers: Number(value('watcher-count')), year: Number(value('year')) };
    });
    return { mode: wrapper[1], page: number('page'), total: number('total'), totalPages: number('total-pages'), entries };
}

function nextUrl(page) {
    const match = page.html.match(/<a\b[^>]*href="([^"]+)"[^>]*rel="next"/);
    assert.ok(match, 'A next-page link is present'); return match[1].replaceAll('&amp;', '&');
}

test('public anime rankings use community votes and distinct watching accounts with stable filtered pagination', async t => {
    const fixture = createAnimeCommunityFixtures({ count: 25 });
    const guest = browser(), users = [], scores = new Map(fixture.animes.map(anime => [anime.id, []]));
    const route = (parameters = {}) => '/anime/ranking?' + new URLSearchParams({ genre: String(fixture.genreId), ...parameters });
    const save = async (user, anime, value) => {
        redirected(await user.client.request('/anime/' + anime.id + '/rating', { rating: String(value), csrfToken: user.token }));
        scores.get(anime.id).push(value);
    };
    const watch = async (user, anime, status, watched) => {
        redirected(await user.client.request('/watchlist', { animeId: String(anime.id), status, watched: String(watched), csrfToken: user.token }));
    };
    const average = id => scores.get(id).reduce((sum, value) => sum + value, 0) / scores.get(id).length;
    const sortedBest = animes => [...animes].sort((first, second) => average(second.id) - average(first.id)
        || scores.get(second.id).length - scores.get(first.id).length
        || first.title.toLowerCase().localeCompare(second.title.toLowerCase()) || first.id - second.id);
    try {
        for (let index = 0; index < 5; index++) users.push(await account('Ranking ' + String.fromCharCode(65 + index)));
        for (const [index, anime] of fixture.animes.entries()) await save(users[0], anime, index === 0 ? 10 : index === 1 ? 9 : 2 + (index % 7));
        for (const user of users.slice(1)) {
            await save(user, fixture.animes[0], 8);
            await save(user, fixture.animes[1], 9);
        }
        for (let index = 0; index < 21; index++) await watch(users[0], fixture.animes[index], index === 0 ? 'completed' : 'watching', index === 0 ? 12 : 3);
        await watch(users[0], fixture.animes[21], 'planned', 0);
        await watch(users[0], fixture.animes[22], 'on_hold', 2);
        await watch(users[0], fixture.animes[23], 'dropped', 2);
        await watch(users[1], fixture.animes[0], 'watching', 2);
        await watch(users[1], fixture.animes[1], 'completed', 12);
        await watch(users[2], fixture.animes[0], 'completed', 12);
        await watch(users[3], fixture.animes[2], 'on_hold', 2);
        await watch(users[4], fixture.animes[3], 'dropped', 2);

        await t.test('guests can read anime rankings while the user leaderboard still requires login', async () => {
            const page = await guest.request(route());
            const result = ranking(page);
            assert.equal(result.mode, 'best');
            assert.equal(result.total, 2, 'The default threshold is five actual account votes');
            assert.deepEqual(result.entries.map(entry => entry.id), [fixture.animes[1].id, fixture.animes[0].id]);
            assert.deepEqual(result.entries.map(entry => [entry.average, entry.count]), [[9, 5], [8.4, 5]]);
            assert.match(page.html, /Minimalna liczba ocen: 5/);
            for (const user of users) assert.ok(!page.html.includes(user.email) && !page.html.includes(user.name), 'Public metrics do not identify voters or watchers');
            assert.doesNotMatch(page.html, /data-user-rating=/);
            const people = await guest.request('/ranking');
            redirected(people); assert.equal(people.location, '/login');
            assert.equal((await users[0].client.request('/ranking')).status, 200);
            assert.equal((await guest.request('/anime/ranking', { mode: 'watched' })).status, 404, 'Ranking is read-only');
        });

        await t.test('best ranking orders by the community mean and keeps exact counts without join multiplication', async () => {
            const result = ranking(await guest.request(route({ mode: 'best', min_votes: '1' })));
            assert.equal(result.total, 25);
            assert.deepEqual(result.entries.map(entry => entry.id), sortedBest(fixture.animes).slice(0, 20).map(anime => anime.id));
            for (const entry of result.entries) {
                assert.ok(Math.abs(entry.average - average(entry.id)) < 1e-10);
                assert.equal(entry.count, scores.get(entry.id).length, 'Watchlist memberships never multiply vote counts');
            }
            assert.deepEqual(result.entries.map(entry => entry.rank), Array.from({ length: 20 }, (_, index) => index + 1));
            const qualified = ranking(await guest.request(route({ min_votes: '6' })));
            assert.equal(qualified.total, 0); assert.deepEqual(qualified.entries, []);
        });

        await t.test('pagination keeps the ranking criteria, stable order and unique positions across pages', async () => {
            const firstPage = await guest.request(route({ mode: 'best', min_votes: '1' }));
            const link = nextUrl(firstPage), parameters = new URL(link, base).searchParams;
            assert.equal(parameters.get('mode'), 'best'); assert.equal(parameters.get('genre'), String(fixture.genreId));
            assert.equal(parameters.get('min_votes'), '1'); assert.equal(parameters.get('page'), '2');
            const first = ranking(firstPage), second = ranking(await guest.request(link));
            assert.deepEqual([first.page, first.totalPages, first.entries.length, second.page, second.entries.length], [1, 2, 20, 2, 5]);
            const combined = [...first.entries, ...second.entries];
            assert.equal(new Set(combined.map(entry => entry.id)).size, 25);
            assert.deepEqual(combined.map(entry => entry.id), sortedBest(fixture.animes).map(anime => anime.id));
            assert.deepEqual(combined.map(entry => entry.rank), Array.from({ length: 25 }, (_, index) => index + 1));
            assert.deepEqual(ranking(await guest.request(link)), second, 'Refreshing an unchanged ranking keeps its complete order');
            assert.deepEqual(ranking(await guest.request(route({ mode: 'best', min_votes: '1', page: '999999' }))), second, 'An oversized valid page clamps to the last real page');
        });

        await t.test('watched counts each watching or completed account once and ignores the vote threshold', async () => {
            const page = await guest.request(route({ mode: 'watched', min_votes: '999999' }));
            const result = ranking(page);
            assert.equal(result.mode, 'watched'); assert.equal(result.total, 21);
            assert.deepEqual(result.entries.slice(0, 2).map(entry => [entry.id, entry.watchers]), [[fixture.animes[0].id, 3], [fixture.animes[1].id, 2]]);
            assert.ok(result.entries.slice(2).every(entry => entry.watchers === 1), 'Paused and dropped accounts do not increase an active watcher count');
            assert.ok(!result.entries.some(entry => fixture.animes.slice(21).some(anime => anime.id === entry.id)));
            assert.equal(result.entries.find(entry => entry.id === fixture.animes[0].id).count, 5, 'Several watchers do not multiply the five votes');
            assert.match(page.html, /Oglądam.*Ukończone/);
            assert.match(page.html, /name="min_votes"[^>]*disabled/);
            const link = nextUrl(page), parameters = new URL(link, base).searchParams;
            assert.equal(parameters.get('mode'), 'watched'); assert.equal(parameters.get('genre'), String(fixture.genreId));
            assert.equal(parameters.has('min_votes'), false, 'The inactive vote filter is not propagated into watched paging');
            const last = ranking(await guest.request(link));
            assert.deepEqual([last.page, last.entries.length, last.entries[0].rank], [2, 1, 21]);
        });

        await t.test('genre and year filters combine before pagination and survive switching ranking modes', async () => {
            for (const year of [2198, 2199]) {
                const expected = fixture.animes.filter(anime => anime.year === year);
                const page = await guest.request(route({ mode: 'best', min_votes: '1', year: String(year), page: '999999' }));
                const result = ranking(page);
                assert.equal(result.total, expected.length); assert.equal(result.page, 1);
                assert.deepEqual(result.entries.map(entry => entry.id), sortedBest(expected).map(anime => anime.id));
                assert.ok(result.entries.every(entry => entry.year === year));
                assert.match(page.html, new RegExp('name="year"[^>]*value="' + year + '"'));
                const modeLink = [...page.html.matchAll(/href="([^"]+)"/g)].map(item => item[1].replaceAll('&amp;', '&'))
                    .find(value => value.startsWith('/anime/ranking?') && new URL(value, base).searchParams.get('mode') === 'watched');
                assert.ok(modeLink);
                const parameters = new URL(modeLink, base).searchParams;
                assert.equal(parameters.get('genre'), String(fixture.genreId)); assert.equal(parameters.get('year'), String(year));
                const watched = ranking(await guest.request(modeLink));
                assert.equal(watched.total, year === 2198 ? 11 : 10);
                assert.ok(watched.entries.every(entry => entry.year === year));
            }
            const unknown = ranking(await guest.request('/anime/ranking?genre=9223372036854775807&min_votes=1'));
            assert.equal(unknown.total, 0); assert.deepEqual(unknown.entries, []);
            const missingYear = ranking(await guest.request(route({ year: '2200', min_votes: '1' })));
            assert.equal(missingYear.total, 0);
        });

        await t.test('malformed filters return safe validation errors without revealing data or executable HTML', async () => {
            const invalid = [
                'mode[]=best', 'mode=unknown', 'mode=%00best', 'genre[]=1', 'genre=-1', 'genre=9223372036854775808',
                'year[]=2198', 'year=1899', 'year=2201', 'year=2198.5', 'year=%FF',
                'min_votes[]=1', 'min_votes=0', 'min_votes=1000001', 'min_votes=1.5', 'mode=watched&min_votes[]=1',
                'page[]=2', 'page=0', 'page=1.5', 'page=1000001', 'year=' + '1'.repeat(65),
                'year=' + encodeURIComponent('<svg onload=alert(1)>'), 'genre=' + encodeURIComponent("1 OR 1=1"),
            ];
            for (const query of invalid) {
                const page = await guest.request('/anime/ranking?' + query);
                assert.equal(page.status, 400, query);
                assert.match(page.html, /role="alert"/);
                assert.doesNotMatch(page.html, /<svg onload=alert\(1\)>|<script>alert\(1\)<\/script>/);
                assert.ok(!page.html.includes(fixture.animes[0].title), 'Rejected filters do not render an accidental unfiltered ranking');
            }
            assert.equal(ranking(await guest.request(route())).total, 2, 'Validation never changes saved ratings');
        });
    } finally {
        const removed = cleanupAnimeCommunityFixtures(fixture);
        assert.equal(removed.removedAnimeCount, fixture.animes.length, 'Cleanup removes exactly the newly created fixture anime');
        assert.equal(removed.removedGenreCount, 1, 'Cleanup removes exactly the newly created fixture genre');
    }
});
