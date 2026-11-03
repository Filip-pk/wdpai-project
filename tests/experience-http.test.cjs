const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Poziomy-Testowe-2026!';

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
    const token = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(token, 'A CSRF token is present');
    return token[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

function attribute(tag, name) {
    const match = tag.match(new RegExp('\\b' + name + '="([^"]*)"'));
    assert.ok(match, name + ' is present');
    return match[1];
}

function expectedProgress(total) {
    let level = 1;
    let remaining = total;
    while (remaining >= level * 50) {
        remaining -= level * 50;
        level++;
    }
    return { level, withinLevel: remaining, nextCost: level * 50 };
}

function experience(page, total) {
    assert.equal(page.status, 200);
    const panels = [...page.html.matchAll(/<[^>]+\bdata-user-exp="\d+"[^>]*>/g)];
    assert.equal(panels.length, 1, 'The page has one own experience panel');
    const progress = expectedProgress(total);
    assert.equal(Number(attribute(panels[0][0], 'data-user-exp')), total);
    assert.equal(Number(attribute(panels[0][0], 'data-user-level')), progress.level);
    const meter = page.html.match(/<progress\b[^>]*\bdata-exp-progress\b[^>]*>/);
    assert.ok(meter, 'The current level has an accessible progress element');
    assert.equal(Number(attribute(meter[0], 'value')), progress.withinLevel);
    assert.equal(Number(attribute(meter[0], 'max')), progress.nextCost);
}

function ownRanking(page, user, total) {
    assert.equal(page.status, 200);
    const cards = [...page.html.matchAll(/<[^>]+\bdata-own-ranking-user-id="\d+"[^>]*>/g)];
    assert.equal(cards.length, 1, 'The current user position is separate from the top 100');
    const card = cards[0][0];
    assert.equal(Number(attribute(card, 'data-own-ranking-user-id')), user.id);
    assert.equal(Number(attribute(card, 'data-own-ranking-exp')), total);
    assert.ok(Number(attribute(card, 'data-own-ranking-rank')) >= 1);
    assert.doesNotMatch(card, /\bdata-ranking-user-id=/);
}

function leaders(page) {
    return [...page.html.matchAll(/<[^>]+\bdata-ranking-user-id="\d+"[^>]*>/g)].map(match => ({
        id: Number(attribute(match[0], 'data-ranking-user-id')),
        rank: Number(attribute(match[0], 'data-ranking-position')),
        exp: Number(attribute(match[0], 'data-total-exp')),
        level: Number(attribute(match[0], 'data-user-level')),
    }));
}

function watchlistEntries(page) {
    return [...page.html.matchAll(/data-anime-id="(\d+)"\s+data-watchlist-status="([^"]+)"\s+data-watched="(\d+)"/g)]
        .map(match => ({ animeId: Number(match[1]), status: match[2], watched: Number(match[3]) }));
}

async function account(name) {
    const client = browser();
    const email = `experience-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken: token,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    experience(profile, 0);
    const accountId = profile.html.match(/\bdata-account-id="(\d+)"/);
    assert.ok(accountId);
    return { client, name, email, id: Number(accountId[1]), token: csrf(profile) };
}

test('experience follows saved progress and the live ranking contains at most 100 users', async (t) => {
    const guest = browser();
    const catalog = await guest.request('/anime?episodes_min=30');
    assert.equal(catalog.status, 200);
    const candidates = [...catalog.html.matchAll(/<article\b[^>]*data-anime-id="(\d+)"[^>]*>/g)].map(match => ({
        id: Number(match[1]),
        episodes: Number(match[0].match(/\bdata-episodes="(\d+)"/)?.[1]),
    }));
    const anime = candidates.find(entry => entry.episodes >= 30);
    assert.ok(anime, 'The local catalog contains a series for both 50 and 150 EXP boundaries');
    const animeRoute = '/anime/' + anime.id;
    const completedExp = anime.episodes * 5 + 100;
    const privateCollectionName = 'Prywatna kolekcja EXP ' + randomUUID();
    const firstName = 'EXP A <svg onload=alert(1)> & Tester';
    let first;
    let second;
    let collectionRoute;

    async function save(user, status, watched, additional = {}) {
        redirected(await user.client.request('/watchlist', {
            animeId: anime.id, status, watched, csrfToken: user.token, ...additional,
        }), '/watchlist');
    }

    await t.test('ranking requires login and new accounts start at level 1 with zero EXP', async () => {
        redirected(await guest.request('/ranking'), '/login');
        first = await account(firstName);
        second = await account('EXP B');
        experience(await first.client.request('/watchlist'), 0);
        experience(await second.client.request('/watchlist'), 0);
        const ranking = await first.client.request('/ranking');
        ownRanking(ranking, first, 0);
        assert.match(ranking.headers.get('cache-control'), /no-store/);
        assert.match(ranking.html, /href="\/ranking"/);
    });

    await t.test('only valid CSRF-protected progress updates can change EXP', async () => {
        for (const fields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
            for (const route of ['/watchlist', '/watchlist/remove']) {
                const page = await first.client.request(route, {
                    animeId: anime.id, status: 'completed', watched: '0', ...fields,
                });
                assert.equal(page.status, 403, route);
            }
        }
        for (const fields of [
            { watched: '-1' }, { watched: '1.5' }, { watched: '2147483648' },
            { watched: String(anime.episodes + 1) }, { 'watched[]': '10' },
            { status: 'forged' }, { animeId: String(Number.MAX_SAFE_INTEGER) },
        ]) {
            const request = { animeId: anime.id, status: 'watching', csrfToken: first.token, ...fields };
            if (!Object.hasOwn(request, 'watched') && !Object.hasOwn(request, 'watched[]')) request.watched = '10';
            assert.equal((await first.client.request('/watchlist', request)).status, 422);
        }
        experience(await first.client.request('/profile'), 0);
        experience(await second.client.request('/profile'), 0);
    });

    await t.test('5 EXP per episode crosses growing level thresholds and ignores forged owner or XP fields', async () => {
        await save(first, 'watching', 10, { userId: second.id, user_id: second.id, exp: '999999', totalExp: '999999', level: '999' });
        experience(await first.client.request('/profile'), 50);
        experience(await first.client.request('/watchlist'), 50);
        ownRanking(await first.client.request('/ranking?user_id=' + second.id), first, 50);
        experience(await second.client.request('/profile'), 0);
        await save(first, 'watching', 30);
        experience(await first.client.request('/profile'), 150);
        ownRanking(await first.client.request('/ranking'), first, 150);
        await save(first, 'watching', 4);
        experience(await first.client.request('/profile'), 20);
        await save(first, 'watching', 4);
        experience(await first.client.request('/profile'), 20);
    });

    await t.test('completion adds 100 EXP once and status corrections replace the previous contribution', async () => {
        for (let attempt = 0; attempt < 2; attempt++) {
            await save(first, 'completed', 0);
            experience(await first.client.request('/profile'), completedExp);
            assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), [
                { animeId: anime.id, status: 'completed', watched: anime.episodes },
            ]);
        }
        await save(first, 'watching', anime.episodes);
        experience(await first.client.request('/profile'), anime.episodes * 5);
        await save(first, 'planned', 0);
        experience(await first.client.request('/profile'), 0);
        await save(first, 'completed', 0);
        experience(await first.client.request('/profile'), completedExp);
        await save(second, 'watching', 1);
        experience(await second.client.request('/profile'), 5);
        experience(await first.client.request('/profile'), completedExp);
    });

    await t.test('watchlist removal keeps EXP and re-adding or re-completing cannot duplicate it', async () => {
        redirected(await first.client.request('/watchlist/remove', { animeId: anime.id, csrfToken: first.token }), '/watchlist');
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), []);
        experience(await first.client.request('/profile'), completedExp);
        for (let attempt = 0; attempt < 2; attempt++) {
            redirected(await first.client.request('/watchlist', {
                animeId: anime.id, mode: 'add', csrfToken: first.token,
            }), '/watchlist');
            experience(await first.client.request('/profile'), completedExp);
        }
        assert.deepEqual(watchlistEntries(await first.client.request('/watchlist')), [
            { animeId: anime.id, status: 'planned', watched: 0 },
        ]);
        await save(first, 'completed', 0);
        experience(await first.client.request('/profile'), completedExp);
        ownRanking(await first.client.request('/ranking'), first, completedExp);
    });

    await t.test('ratings and private collections are independent of experience', async () => {
        redirected(await first.client.request(animeRoute + '/rating', { rating: '8', csrfToken: first.token }),
            animeRoute + '?ratingSaved=1#anime-rating-title');
        const created = await first.client.request('/profile/collections', {
            name: privateCollectionName, description: 'Ta lista pozostaje prywatna.', csrfToken: first.token,
        });
        assert.equal(created.status, 303);
        collectionRoute = created.headers.get('location');
        assert.match(collectionRoute, /^\/profile\/collections\/\d+$/);
        const collectionId = collectionRoute.split('/').pop();
        const selection = new URLSearchParams({ csrfToken: first.token });
        selection.append('collectionIds[]', collectionId);
        redirected(await first.client.request(animeRoute + '/collections', selection), animeRoute + '?collectionSaved=1');
        redirected(await first.client.request(collectionRoute + '/remove', { animeId: anime.id, csrfToken: first.token }), collectionRoute);
        redirected(await first.client.request(animeRoute + '/rating/remove', { csrfToken: first.token }),
            animeRoute + '?ratingRemoved=1#anime-rating-title');
        experience(await first.client.request('/profile'), completedExp);
        experience(await second.client.request('/profile'), 5);
    });

    await t.test('the live top 100 is ordered, shares tied ranks and exposes only ranking data', async () => {
        const page = await first.client.request('/ranking');
        ownRanking(page, first, completedExp);
        const rows = leaders(page);
        assert.ok(rows.length >= 2 && rows.length <= 100);
        assert.equal(new Set(rows.map(row => row.id)).size, rows.length);
        for (let index = 0; index < rows.length; index++) {
            const row = rows[index];
            assert.equal(row.level, expectedProgress(row.exp).level);
            if (index === 0) {
                assert.equal(row.rank, 1);
            } else {
                const previous = rows[index - 1];
                assert.ok(row.exp <= previous.exp, 'EXP is sorted descending');
                if (row.exp === previous.exp) {
                    assert.equal(row.rank, previous.rank, 'Equal EXP has an equal place');
                    assert.ok(row.id > previous.id, 'Tied rows have a deterministic order');
                } else {
                    assert.equal(row.rank, index + 1);
                }
            }
        }
        assert.ok(!page.html.includes(first.email));
        assert.ok(!page.html.includes(second.email));
        assert.ok(!page.html.includes(privateCollectionName));
        assert.doesNotMatch(page.html, /data-watchlist-status=|data-collection-id=|data-user-rating=/);
        assert.ok(!page.html.includes('<svg onload=alert(1)>'));
        assert.ok(page.html.includes('EXP A &lt;svg onload=alert(1)&gt; &amp; Tester'));
        ownRanking(await second.client.request('/ranking'), second, 5);
        await save(first, 'watching', 2);
        ownRanking(await first.client.request('/ranking'), first, 10);
        experience(await first.client.request('/profile'), 10);
    });

    await t.test('EXP survives logout and only the signed-in account can correct its progress', async () => {
        redirected(await first.client.request('/watchlist/remove', { animeId: anime.id, csrfToken: first.token }), '/watchlist');
        experience(await first.client.request('/profile'), 10);
        redirected(await first.client.request('/logout', { csrfToken: first.token }), '/login');
        redirected(await first.client.request('/ranking'), '/login');
        redirected(await first.client.request('/watchlist', {
            animeId: anime.id, status: 'completed', watched: 0, user_id: first.id,
        }), '/login');
        const token = csrf(await first.client.request('/login'));
        redirected(await first.client.request('/login', { email: first.email, password, csrfToken: token }), '/projects');
        const profile = await first.client.request('/profile');
        first.token = csrf(profile);
        experience(profile, 10);
        ownRanking(await first.client.request('/ranking'), first, 10);
        experience(await second.client.request('/profile'), 5);
        await save(first, 'watching', 0, { user_id: second.id });
        experience(await first.client.request('/profile'), 0);
        experience(await second.client.request('/profile'), 5);
    });
});
