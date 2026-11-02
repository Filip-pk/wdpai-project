const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const expectedCatalogCount = process.env.ANIVIBE_EXPECTED_CATALOG_COUNT === undefined
    ? null : Number(process.env.ANIVIBE_EXPECTED_CATALOG_COUNT);
const unknownId = Number.MAX_SAFE_INTEGER;

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

function cardMetadata(page) {
    return [...page.html.matchAll(/<article\b[^>]*\bdata-anime-id="\d+"[^>]*>/g)].map(match => {
        const read = name => {
            const value = match[0].match(new RegExp('\\b' + name + '="([^"]*)"'));
            assert.ok(value, name + ' is included on the anime card');
            return value[1];
        };
        const number = name => {
            const value = read(name);
            if (value === '') return null;
            assert.ok(Number.isFinite(Number(value)), name + ' is a numeric value');
            return Number(value);
        };
        return {
            id: number('data-anime-id'),
            title: read('data-title'),
            score: number('data-score'),
            episodes: number('data-episodes'),
            year: number('data-year'),
        };
    });
}

function assertNumericOrder(records, field, direction) {
    assert.ok(records.length > 1, 'The sort check has multiple anime');
    for (let index = 1; index < records.length; index++) {
        const previous = records[index - 1][field];
        const current = records[index][field];
        if (previous === null) {
            assert.equal(current, null, 'Unknown ' + field + ' values are listed last');
        } else if (current !== null) {
            assert.ok(direction === 'asc' ? previous <= current : previous >= current,
                field + ' follows ' + direction + ' order');
        }
    }
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

async function resultIncludes(client, route, animeId, firstPage) {
    const first = firstPage ?? await client.request(route);
    if (cards(first).includes(animeId)) return true;

    const pages = Math.ceil(attribute(first, 'data-result-count') / 20);
    for (let number = 2; number <= pages; number++) {
        const url = new URL(route, baseUrl);
        url.searchParams.set('page', String(number));
        const page = await client.request(url.pathname + url.search);
        assert.equal(page.status, 200);
        if (cards(page).includes(animeId)) return true;
    }
    return false;
}

async function allResultMetadata(client, route) {
    const first = await client.request(route);
    assert.equal(first.status, 200);
    const records = cardMetadata(first);
    const pages = Math.ceil(attribute(first, 'data-result-count') / 20);
    for (let number = 2; number <= pages; number++) {
        const url = new URL(route, baseUrl);
        url.searchParams.set('page', String(number));
        const page = await client.request(url.pathname + url.search);
        assert.equal(page.status, 200);
        records.push(...cardMetadata(page));
    }
    return records;
}

test('the anime catalog supports advanced searching, sorting and saved watchlists', async (t) => {
    const guest = browser();
    let catalog;
    let catalogTotal;
    let actionGenre;

    await t.test('the full local catalog is paginated into separate pages of 20', async () => {
        catalog = await guest.request('/anime');
        assert.equal(catalog.status, 200);
        catalogTotal = attribute(catalog, 'data-catalog-count');
        assert.ok(catalogTotal >= 200, 'The catalog contains at least 200 anime');
        if (expectedCatalogCount !== null) {
            assert.ok(Number.isSafeInteger(expectedCatalogCount) && expectedCatalogCount >= 200,
                'ANIVIBE_EXPECTED_CATALOG_COUNT must be an integer of at least 200');
            assert.equal(catalogTotal, expectedCatalogCount, 'The catalog matches the completed import');
        }
        assert.equal(attribute(catalog, 'data-result-count'), catalogTotal);
        assert.equal(attribute(catalog, 'data-page'), 1);
        assert.equal(cards(catalog).length, 20);
        assert.equal(new Set(cards(catalog)).size, 20);
        const second = await guest.request('/anime?page=2');
        assert.equal(attribute(second, 'data-page'), 2);
        assert.equal(cards(second).length, 20);
        assert.deepEqual(cards(second).filter(id => cards(catalog).includes(id)), []);
        const last = await guest.request('/anime?page=999999');
        assert.equal(last.status, 200);
        assert.equal(attribute(last, 'data-page'), Math.ceil(catalogTotal / 20));
        assert.equal(cards(last).length, catalogTotal % 20 || 20);
        const genreSelect = catalog.html.match(/<select\b[^>]*id="anime-genre"[^>]*>([\s\S]*?)<\/select>/);
        assert.ok(genreSelect && !/\bdisabled\b/.test(genreSelect[0]));
        actionGenre = genreSelect[1].match(/<option\b[^>]*value="(\d+)"[^>]*>\s*Akcja\s*<\/option>/)?.[1];
        assert.ok(actionGenre, 'The catalog has a localized Action genre');
    });

    await t.test('rating descending is the default and refreshing keeps a stable order', async () => {
        assertNumericOrder(cardMetadata(catalog), 'score', 'desc');
        const refreshed = await guest.request('/anime');
        assert.equal(refreshed.status, 200);
        assert.deepEqual(cards(refreshed), cards(catalog));
        const descending = await guest.request('/anime?sort=score_desc');
        assert.equal(descending.status, 200);
        assert.deepEqual(cards(descending), cards(catalog));
        const ascending = await guest.request('/anime?sort=score_asc');
        assert.equal(ascending.status, 200);
        assertNumericOrder(cardMetadata(ascending), 'score', 'asc');
        assert.notDeepEqual(cards(ascending), cards(descending));
        const searched = await guest.request('/anime?q=Naruto');
        assert.equal(searched.status, 200);
        assertNumericOrder(cardMetadata(searched), 'score', 'desc');
    });

    await t.test('alphabetical sorting reverses titles across all result pages', async () => {
        const ascending = await allResultMetadata(guest, '/anime?q=Naruto&sort=title_asc');
        const descending = await allResultMetadata(guest, '/anime?q=Naruto&sort=title_desc');
        assert.ok(ascending.length > 1);
        assert.notEqual(ascending[0].title, ascending.at(-1).title);
        assert.deepEqual(ascending.map(anime => anime.id).sort((left, right) => left - right),
            descending.map(anime => anime.id).sort((left, right) => left - right));
        assert.deepEqual(descending.map(anime => anime.title), ascending.map(anime => anime.title).reverse());
    });

    await t.test('title aliases, case-insensitive queries and no-result states work', async () => {
        const naruto = await guest.request('/anime?q=nArUtO');
        assert.equal(naruto.status, 200);
        assert.ok(await resultIncludes(guest, '/anime?q=nArUtO', 1, naruto));
        assert.ok(attribute(naruto, 'data-result-count') < catalogTotal);
        const titan = await guest.request('/anime?q=Attack%20on%20Titan');
        assert.ok(await resultIncludes(guest, '/anime?q=Attack%20on%20Titan', 5, titan),
            'English title and aliases resolve the existing Attack on Titan ID');
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
        assert.ok(total > 0 && total < catalogTotal);
        for (const url of catalogLinks(filtered).filter(url => url.searchParams.has('page'))) {
            assert.equal(url.searchParams.get('genre'), actionGenre);
        }
        const combined = await guest.request('/anime?q=Naruto&genre=' + actionGenre);
        assert.equal(combined.status, 200);
        assert.ok(await resultIncludes(guest, '/anime?q=Naruto&genre=' + actionGenre, 1, combined));
        assert.ok(attribute(combined, 'data-result-count') <= total);
        assert.match(combined.html, new RegExp('<option\\b[^>]*value="' + actionGenre + '"[^>]*selected'));
        const missingGenre = await guest.request('/anime?genre=' + unknownId);
        assert.equal(missingGenre.status, 200);
        assert.equal(attribute(missingGenre, 'data-result-count'), 0);
    });

    await t.test('all criteria work together and survive pagination and refresh', async () => {
        const criteria = {
            q: 'a', genre: actionGenre, type: 'TV',
            episodes_min: '12', episodes_max: '10000',
            score_min: '7.5', score_max: '10',
            year_min: '2000', year_max: '2200', sort: 'title_desc',
        };
        const route = '/anime?' + new URLSearchParams(criteria);
        const filtered = await guest.request(route);
        assert.equal(filtered.status, 200);
        const total = attribute(filtered, 'data-result-count');
        assert.ok(total > 20 && total < catalogTotal);
        const checkRanges = page => {
            for (const anime of cardMetadata(page)) {
                assert.ok(anime.episodes !== null && anime.episodes >= 12 && anime.episodes <= 10000);
                assert.ok(anime.score !== null && anime.score >= 7.5 && anime.score <= 10);
                assert.ok(anime.year !== null && anime.year >= 2000 && anime.year <= 2200);
            }
        };
        checkRanges(filtered);
        const links = catalogLinks(filtered).filter(url => url.searchParams.has('page'));
        assert.ok(links.length > 0);
        for (const url of links) {
            for (const [name, value] of Object.entries(criteria)) {
                assert.equal(url.searchParams.get(name), value, 'Pagination retains ' + name);
            }
        }
        const secondUrl = links.find(url => url.searchParams.get('page') === '2');
        assert.ok(secondUrl, 'The filtered catalog links to its second page');
        const second = await guest.request(secondUrl.pathname + secondUrl.search);
        assert.equal(second.status, 200);
        assert.equal(attribute(second, 'data-page'), 2);
        checkRanges(second);
        assert.deepEqual(cards(second).filter(id => cards(filtered).includes(id)), []);
        const refreshed = await guest.request(secondUrl.pathname + secondUrl.search);
        assert.equal(refreshed.status, 200);
        assert.deepEqual(cards(refreshed), cards(second));
        assert.equal(attribute(refreshed, 'data-result-count'), total);
        const empty = await guest.request('/anime?type=MOVIE&episodes_min=2147483647');
        assert.equal(empty.status, 200);
        assert.equal(attribute(empty, 'data-result-count'), 0);
        assert.deepEqual(cards(empty), []);
        const zero = await guest.request('/anime?score_min=0&score_max=0');
        assert.equal(zero.status, 200);
        for (const anime of cardMetadata(zero)) assert.equal(anime.score, 0);
    });

    await t.test('invalid advanced criteria and reversed ranges return a validation error', async () => {
        const invalidQueries = [
            'episodes_min=-1', 'episodes_max=2147483648', 'episodes_min=1.5', 'episodes_max[]=12',
            'episodes_min=24&episodes_max=12',
            'score_min=-0.1', 'score_max=10.01', 'score_min=wrong', 'score_min[]=8',
            'score_min=9&score_max=8',
            'year_min=1899', 'year_max=2201', 'year_min[]=2020', 'year_min=2025&year_max=2020',
            'type[]=TV', 'type=INVALID', 'sort[]=score_desc', 'sort=score_desc%3BDROP%20TABLE%20anime',
        ];
        for (const query of invalidQueries) {
            assert.equal((await guest.request('/anime?' + query)).status, 400, query);
        }
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
        assert.equal((await guest.request('/anime/' + unknownId)).status, 404);
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
        assert.match(watchlist.html, new RegExp('<li\\b[^>]*data-anime-id="' + animeId
            + '"[^>]*data-watchlist-status="planned"[^>]*data-watched="0"'));
        assert.ok(cards(watchlist).includes(animeId));
        redirected(await client.request('/watchlist/remove', { animeId, csrfToken: token }), '/watchlist');
        assert.deepEqual(cards(await client.request('/watchlist')), []);
    });
});
