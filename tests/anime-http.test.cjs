const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';

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
                if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
            }
            const html = await response.text();
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
            return { status: response.status, headers: response.headers, html };
        },
    };
}

function attribute(page, name) {
    const match = page.html.match(new RegExp('\\b' + name + '="(\\d+)"'));
    assert.ok(match, name + ' is included in the rendered page');
    return Number(match[1]);
}

function cards(page) {
    return [...page.html.matchAll(/<article\b[^>]*>/g)]
        .map(match => match[0].match(/\bdata-anime-id="(\d+)"/))
        .filter(Boolean)
        .map(match => Number(match[1]));
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

function catalogLinks(page) {
    return [...page.html.matchAll(/href="(\/anime\?[^"#]+)"/g)]
        .map(match => new URL(match[1].replaceAll('&amp;', '&'), baseUrl));
}

test('the pilot anime catalog supports searching, genres and saved watchlists', async (t) => {
    const guest = browser();
    let catalog;
    let actionGenre;

    await t.test('200 local anime are paginated into separate pages of 20', async () => {
        catalog = await guest.request('/anime');
        assert.equal(catalog.status, 200);
        assert.equal(attribute(catalog, 'data-catalog-count'), 200);
        assert.equal(attribute(catalog, 'data-result-count'), 200);
        assert.equal(attribute(catalog, 'data-page'), 1);
        assert.equal(cards(catalog).length, 20);
        assert.equal(new Set(cards(catalog)).size, 20);
        const second = await guest.request('/anime?page=2');
        assert.equal(attribute(second, 'data-page'), 2);
        assert.equal(cards(second).length, 20);
        assert.deepEqual(cards(second).filter(id => cards(catalog).includes(id)), []);
        const last = await guest.request('/anime?page=9999');
        assert.equal(last.status, 200);
        assert.equal(attribute(last, 'data-page'), 10);
        assert.equal(cards(last).length, 20);
        const genreSelect = catalog.html.match(/<select\b[^>]*id="anime-genre"[^>]*>([\s\S]*?)<\/select>/);
        assert.ok(genreSelect && !/\bdisabled\b/.test(genreSelect[0]));
        actionGenre = genreSelect[1].match(/<option\b[^>]*value="(\d+)"[^>]*>\s*Akcja\s*<\/option>/)?.[1];
        assert.ok(actionGenre, 'The catalog has a localized Action genre');
    });

    await t.test('title aliases, case-insensitive queries and no-result states work', async () => {
        const naruto = await guest.request('/anime?q=nArUtO');
        assert.equal(naruto.status, 200);
        assert.ok(cards(naruto).includes(1));
        assert.ok(attribute(naruto, 'data-result-count') < 200);
        const titan = await guest.request('/anime?q=Attack%20on%20Titan');
        assert.ok(cards(titan).includes(5), 'English title and aliases resolve the existing Attack on Titan ID');
        const empty = await guest.request('/anime?q=DefinitelyMissingAnime-' + randomUUID());
        assert.equal(empty.status, 200);
        assert.equal(attribute(empty, 'data-result-count'), 0);
        assert.deepEqual(cards(empty), []);
        const broad = await guest.request('/anime?q=a');
        const pages = catalogLinks(broad).filter(url => url.searchParams.has('page'));
        assert.ok(pages.length > 0, 'A broad filtered query has pagination');
        for (const url of pages) assert.equal(url.searchParams.get('q'), 'a');
    });

    await t.test('genre filtering combines with title search and keeps the chosen genre', async () => {
        const filtered = await guest.request('/anime?genre=' + actionGenre);
        assert.equal(filtered.status, 200);
        const total = attribute(filtered, 'data-result-count');
        assert.ok(total > 0 && total < 200);
        for (const url of catalogLinks(filtered).filter(url => url.searchParams.has('page'))) {
            assert.equal(url.searchParams.get('genre'), actionGenre);
        }
        const combined = await guest.request('/anime?q=Naruto&genre=' + actionGenre);
        assert.equal(combined.status, 200);
        assert.ok(cards(combined).includes(1));
        assert.ok(attribute(combined, 'data-result-count') <= total);
        assert.match(combined.html, new RegExp('<option\\b[^>]*value="' + actionGenre + '"[^>]*selected'));
        const missingGenre = await guest.request('/anime?genre=999999999');
        assert.equal(missingGenre.status, 200);
        assert.equal(attribute(missingGenre, 'data-result-count'), 0);
    });

    await t.test('invalid query shapes are rejected and HTML input is safely displayed', async () => {
        for (const query of ['q[]=Naruto', 'q=%00', 'genre[]=1', 'genre=wrong', 'page[]=2', 'page=0', 'page=-1', 'page=1.5', 'q=' + 'a'.repeat(101)]) {
            assert.equal((await guest.request('/anime?' + query)).status, 400, query);
        }
        const malicious = '<script>alert(1)</script>';
        const page = await guest.request('/anime?q=' + encodeURIComponent(malicious));
        assert.equal(page.status, 200);
        assert.equal(attribute(page, 'data-result-count'), 0);
        assert.ok(!page.html.includes(malicious));
        assert.ok(page.html.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
        assert.equal((await guest.request('/anime?q=' + encodeURIComponent("' OR 1=1 --"))).status, 200);
    });

    await t.test('legacy links keep the same anime and details have real metadata and covers', async () => {
        for (const [id, title] of [[1, /Naruto/], [2, /Death Note/], [3, /Cyberpunk/], [4, /Steins;Gate/], [5, /Shingeki|Attack on Titan/], [6, /Chainsaw Man/]]) {
            const detail = await guest.request('/anime/' + id);
            assert.equal(detail.status, 200);
            assert.match(detail.html, title);
            assert.match(detail.html, /data-anime-cover/);
            assert.match(detail.html, /id="synopsis-title"/);
            assert.doesNotMatch(detail.html, /Ocena demonstracyjna/);
        }
        assert.equal((await guest.request('/anime/999999999')).status, 404);
        assert.equal((await guest.request('/anime/0')).status, 404);
    });

    await t.test('an imported anime outside the six demo entries can be saved on the account watchlist', async () => {
        const animeId = cards(catalog).find(id => id > 6);
        assert.ok(animeId);
        redirected(await guest.request('/watchlist', { animeId, mode: 'add' }), '/login');
        const client = browser();
        const email = 'anime-' + randomUUID() + '@example.test';
        const password = 'Anime-Testowe-2026!';
        let token = csrf(await client.request('/register'));
        redirected(await client.request('/register', { name: 'Katalog testowy', email, password, passwordConfirmation: password, csrfToken: token }), '/login?registered=1&mail=sent');
        redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
        token = csrf(await client.request('/profile'));
        redirected(await client.request('/watchlist', { animeId, mode: 'add', csrfToken: token, user_id: '999999999' }), '/watchlist');
        assert.equal((await client.request('/watchlist', {
            animeId, status: 'watching', watched: '2147483648', csrfToken: token,
        })).status, 422);
        const watchlist = await client.request('/watchlist');
        assert.equal(watchlist.status, 200);
        assert.match(watchlist.html, new RegExp('data-anime-id="' + animeId + '"\\s+data-watchlist-status="planned"\\s+data-watched="0"'));
        assert.ok(cards(watchlist).includes(animeId));
        redirected(await client.request('/watchlist/remove', { animeId, csrfToken: token }), '/watchlist');
        assert.deepEqual(cards(await client.request('/watchlist')), []);
    });
});
