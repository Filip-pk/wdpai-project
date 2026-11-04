const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { gzipSync } = require('node:zlib');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Import-MAL-Testowy-2026!';
const importRoute = '/profile/import';
const missingMalId = 2147483647;

function browser() {
    let cookie = '';
    return {
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: cookie ? { Cookie: cookie } : {},
                body: fields === undefined ? undefined : fields instanceof FormData ? fields : new URLSearchParams(fields),
                redirect: 'manual', signal: AbortSignal.timeout(15000),
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

function token(page, name = 'csrfToken') {
    const match = page.html.match(new RegExp('name="' + name + '"\\s+value="([a-f0-9]{64})"'));
    assert.ok(match, name + ' is present in the form');
    return match[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

function attribute(html, name) {
    const match = html.match(new RegExp('\\b' + name + '="([^\"]*)"'));
    assert.ok(match, name + ' is present');
    return match[1];
}

function counts(page) {
    assert.match(page.html, /\bdata-import-preview\b/);
    return Object.fromEntries(['add', 'update', 'unchanged', 'skipped'].map(action => [
        action, Number(attribute(page.html, 'data-import-count-' + action)),
    ]));
}

function report(page) {
    const match = page.html.match(/<[^>]+\bdata-import-report\b[^>]*>/);
    assert.ok(match, 'A completed import has a report');
    return Object.fromEntries(['added', 'updated', 'unchanged', 'skipped', 'exp-before', 'exp-after'].map(name => [
        name, Number(attribute(match[0], 'data-import-' + name)),
    ]));
}

function watchlist(page) {
    assert.equal(page.status, 200);
    return new Map([...page.html.matchAll(/data-anime-id="(\d+)"\s+data-watchlist-status="([^"]+)"\s+data-watched="(\d+)"/g)]
        .map(match => [Number(match[1]), { status: match[2], watched: Number(match[3]) }]));
}

function ownRating(page) {
    const match = page.html.match(/\bdata-user-rating="(\d*)"/);
    assert.ok(match, 'The anime page exposes only the signed-in rating');
    return match[1] === '' ? null : Number(match[1]);
}

function exp(page) {
    assert.equal(page.status, 200);
    return Number(attribute(page.html, 'data-user-exp'));
}

const cdata = value => '<![CDATA[' + String(value).replaceAll(']]>', ']]]]><![CDATA[>') + ']]>';
function xml(entries, userId = 999999999) {
    return '<?xml version="1.0" encoding="UTF-8"?>\n<myanimelist><myinfo><user_id>' + userId
        + '</user_id><user_name>Imported source</user_name><user_export_type>1</user_export_type></myinfo>'
        + entries.map(entry => '<anime><series_animedb_id>' + entry.malId + '</series_animedb_id>'
            + '<series_title>' + cdata(entry.title ?? 'Tytuł eksportu') + '</series_title>'
            + '<my_status>' + cdata(entry.status ?? 'Watching') + '</my_status>'
            + '<my_watched_episodes>' + (entry.watched ?? 0) + '</my_watched_episodes>'
            + '<my_score>' + (entry.score ?? 0) + '</my_score></anime>').join('') + '</myanimelist>';
}

function upload(csrfToken, content, options = {}) {
    const form = new FormData();
    if (csrfToken !== undefined) form.append('csrfToken', csrfToken);
    if (content !== null) {
        const bytes = Buffer.isBuffer(content) ? content : Buffer.from(content, 'utf8');
        form.append('malFile', new Blob([bytes], { type: options.type ?? 'application/xml' }), options.filename ?? 'animelist.xml');
    }
    if (options.updateExisting) form.append('updateExisting', '1');
    for (const [name, value] of Object.entries(options.fields ?? {})) form.append(name, String(value));
    return form;
}

async function account(name) {
    const client = browser();
    const email = `mal-import-${randomUUID()}@example.test`;
    const csrfToken = token(await client.request('/register'));
    redirected(await client.request('/register', {
        name, email, password, passwordConfirmation: password, csrfToken,
    }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken }), '/projects');
    const page = await client.request(importRoute);
    assert.equal(page.status, 200);
    return { client, email, id: Number(attribute(page.html, 'data-account-id')), token: token(page) };
}

async function prepare(user, content, options = {}) {
    redirected(await user.client.request(importRoute + '/preview', upload(user.token, content, options)), importRoute);
    const page = await user.client.request(importRoute);
    assert.equal(page.status, 200);
    return { page, importToken: token(page, 'importToken') };
}

async function confirm(user, importToken, additional = {}) {
    redirected(await user.client.request(importRoute + '/confirm', {
        csrfToken: user.token, importToken, ...additional,
    }), importRoute + '?imported=1');
    const page = await user.client.request(importRoute + '?imported=1');
    assert.equal(page.status, 200);
    return report(page);
}

test('MAL XML imports are previewed, private, repeatable and preserve current progress by default', async t => {
    const guest = browser();
    const catalog = await guest.request('/anime?episodes_min=6');
    assert.equal(catalog.status, 200);
    const candidates = [...catalog.html.matchAll(/<article\b[^>]*data-anime-id="(\d+)"[^>]*>/g)].map(match => ({
        id: Number(match[1]), episodes: Number(match[0].match(/\bdata-episodes="(\d+)"/)?.[1]),
    }));
    const mapped = [];
    for (const anime of candidates) {
        const detail = await guest.request('/anime/' + anime.id);
        const mal = detail.html.match(/href="https:\/\/myanimelist\.net\/anime\/(\d+)(?:[\/\?"])/);
        if (mal && anime.episodes >= 6) mapped.push({ ...anime, malId: Number(mal[1]) });
        if (mapped.length === 5) break;
    }
    assert.equal(mapped.length, 5, 'Five catalog titles with MAL IDs are available for the import scenarios');
    const [anime, paused, dropped, rated, last] = mapped;
    const completedExp = anime.episodes * 5 + 100;
    let first;
    let second;
    let originalToken;
    const maliciousTitle = 'Nieznane <svg onload=alert(1)> & Żółć';
    const initial = xml([
        { malId: anime.malId, status: 'Completed', watched: 0, score: 8 },
        { malId: paused.malId, status: 'On-Hold', watched: 3 },
        { malId: dropped.malId, status: 'Dropped', watched: 2, score: 6 },
        { malId: rated.malId, status: 'Plan to Watch', score: 7 },
        { malId: missingMalId, title: maliciousTitle, watched: 1 },
        { malId: anime.malId, status: 'Completed', score: 9 },
        { malId: last.malId, watched: 1, score: 11 },
    ]);

    await t.test('private import routes reject guests and each write requires a scalar CSRF token', async () => {
        redirected(await guest.request(importRoute), '/login');
        for (const suffix of ['/preview', '/confirm', '/cancel']) {
            redirected(await guest.request(importRoute + suffix, { csrfToken: 'wrong', importToken: 'wrong' }), '/login');
        }
        first = await account('Import MAL A');
        second = await account('Import MAL B');
        for (const user of [first, second]) {
            assert.equal(exp(await user.client.request('/profile')), 0);
            assert.equal(watchlist(await user.client.request('/watchlist')).size, 0);
        }
        for (const fields of [{}, { csrfToken: 'wrong' }, { 'csrfToken[]': first.token }]) {
            assert.equal((await first.client.request(importRoute + '/preview', upload(undefined, initial, { fields }))).status, 403);
            for (const suffix of ['/confirm', '/cancel']) {
                assert.equal((await first.client.request(importRoute + suffix, { ...fields, importToken: 'wrong' })).status, 403);
            }
        }
    });

    await t.test('malformed files, DTD entities and unsupported uploads cannot change the account', async () => {
        const entity = '<?xml version="1.0"?><!DOCTYPE myanimelist [<!ENTITY payload SYSTEM "file:///etc/passwd">]>'
            + '<myanimelist><anime><series_animedb_id>' + anime.malId + '</series_animedb_id>'
            + '<series_title>&payload;</series_title><my_status>Watching</my_status>'
            + '<my_watched_episodes>1</my_watched_episodes><my_score>8</my_score></anime></myanimelist>';
        for (const [content, options] of [
            [null, {}], ['not XML', {}], ['<myanimelist>', {}], ['<manga/>', {}],
            [initial, { filename: 'list.txt' }], [Buffer.from([0x1f, 0x8b, 0, 1, 2]), { filename: 'list.xml.gz' }],
            [entity, {}],
        ]) {
            const page = await first.client.request(importRoute + '/preview', upload(first.token, content, options));
            assert.equal(page.status, 422, options.filename ?? String(content).slice(0, 35));
            assert.doesNotMatch(page.html, /root:x:|daemon:x:/);
        }
        const oversized = await first.client.request(importRoute + '/preview', upload(first.token, Buffer.alloc(10 * 1024 * 1024 + 1, 65)));
        assert.ok([413, 422].includes(oversized.status), 'An oversized upload is rejected at the application or server limit');
        assert.equal(exp(await first.client.request('/profile')), 0);
        assert.equal(watchlist(await first.client.request('/watchlist')).size, 0);
    });

    await t.test('a preview has no writes and reports missing, duplicate and invalid entries safely', async () => {
        const padded = initial.replace('<myanimelist>', '<myanimelist><!--' + 'x'.repeat(6 * 1024 * 1024) + '-->');
        const largePreview = await prepare(first, padded);
        assert.deepEqual(counts(largePreview.page), { add: 4, update: 0, unchanged: 0, skipped: 3 });
        redirected(await first.client.request(importRoute + '/cancel', {
            csrfToken: first.token, importToken: largePreview.importToken,
        }), importRoute);
        const prepared = await prepare(first, initial, { fields: { user_id: second.id, exp: '999999999' } });
        originalToken = prepared.importToken;
        assert.deepEqual(counts(prepared.page), { add: 4, update: 0, unchanged: 0, skipped: 3 });
        assert.match(prepared.page.html, new RegExp('data-import-mal-id="' + missingMalId + '"'));
        assert.ok(prepared.page.html.includes('&lt;svg onload=alert(1)&gt; &amp; Żółć'));
        assert.ok(!prepared.page.html.includes('<svg onload=alert(1)>'));
        assert.equal(exp(await first.client.request('/profile')), 0);
        assert.equal(watchlist(await first.client.request('/watchlist')).size, 0);
        assert.equal(ownRating(await first.client.request('/anime/' + anime.id)), null);
        const otherPage = await second.client.request(importRoute);
        assert.doesNotMatch(otherPage.html, /\bdata-import-preview\b/);
        assert.ok(!otherPage.html.includes(maliciousTitle));
        assert.equal((await second.client.request(importRoute + '/confirm', { csrfToken: second.token, importToken: originalToken })).status, 409);
        assert.equal((await first.client.request(importRoute + '/confirm', { csrfToken: 'wrong', importToken: originalToken })).status, 403);
    });

    await t.test('confirmation imports all supported statuses, own scores and normalized completion EXP once', async () => {
        const result = await confirm(first, originalToken, { user_id: second.id, exp: '999999999' });
        assert.deepEqual(result, { added: 4, updated: 0, unchanged: 0, skipped: 3, 'exp-before': 0, 'exp-after': completedExp + 25 });
        const entries = watchlist(await first.client.request('/watchlist'));
        assert.equal(entries.size, 4);
        assert.deepEqual(entries.get(anime.id), { status: 'completed', watched: anime.episodes });
        assert.deepEqual(entries.get(paused.id), { status: 'on_hold', watched: 3 });
        assert.deepEqual(entries.get(dropped.id), { status: 'dropped', watched: 2 });
        assert.deepEqual(entries.get(rated.id), { status: 'planned', watched: 0 });
        assert.equal(ownRating(await first.client.request('/anime/' + anime.id)), 8);
        assert.equal(ownRating(await first.client.request('/anime/' + paused.id)), null);
        assert.equal(ownRating(await first.client.request('/anime/' + dropped.id)), 6);
        assert.equal(ownRating(await first.client.request('/anime/' + rated.id)), 7);
        assert.equal(exp(await first.client.request('/profile')), completedExp + 25);
        assert.equal(exp(await second.client.request('/profile')), 0);
        assert.equal(watchlist(await second.client.request('/watchlist')).size, 0);
        assert.equal((await first.client.request(importRoute + '/confirm', { csrfToken: first.token, importToken: originalToken })).status, 409);
        const repeated = await prepare(first, initial);
        assert.deepEqual(counts(repeated.page), { add: 0, update: 0, unchanged: 4, skipped: 3 });
        const repeatedResult = await confirm(first, repeated.importToken);
        assert.equal(repeatedResult['exp-before'], completedExp + 25);
        assert.equal(repeatedResult['exp-after'], completedExp + 25);
    });

    await t.test('default imports preserve existing progress and a rating saved outside the watchlist', async () => {
        redirected(await first.client.request('/watchlist', { animeId: anime.id, status: 'watching', watched: 2, csrfToken: first.token }), '/watchlist');
        redirected(await first.client.request('/anime/' + anime.id + '/rating', { rating: '9', csrfToken: first.token }),
            '/anime/' + anime.id + '?ratingSaved=1#anime-rating-title');
        redirected(await first.client.request('/watchlist/remove', { animeId: rated.id, csrfToken: first.token }), '/watchlist');
        redirected(await first.client.request('/anime/' + rated.id + '/rating', { rating: '10', csrfToken: first.token }),
            '/anime/' + rated.id + '?ratingSaved=1#anime-rating-title');
        const incoming = xml([
            { malId: anime.malId, status: 'Completed', score: 1 },
            { malId: rated.malId, watched: 4, score: 2 },
        ], second.id);
        const prepared = await prepare(first, gzipSync(incoming), { filename: 'animelist.xml.gz', type: 'application/gzip' });
        assert.deepEqual(counts(prepared.page), { add: 1, update: 0, unchanged: 1, skipped: 0 });
        await confirm(first, prepared.importToken);
        const entries = watchlist(await first.client.request('/watchlist'));
        assert.deepEqual(entries.get(anime.id), { status: 'watching', watched: 2 });
        assert.deepEqual(entries.get(rated.id), { status: 'watching', watched: 4 });
        assert.equal(ownRating(await first.client.request('/anime/' + anime.id)), 9);
        assert.equal(ownRating(await first.client.request('/anime/' + rated.id)), 10);
        assert.equal(exp(await first.client.request('/profile')), 55);
    });

    await t.test('explicit updates can replace progress and remove an old score while keeping repeat imports idempotent', async () => {
        const replacement = xml([
            { malId: anime.malId, status: 'Completed', score: 1 },
            { malId: rated.malId, watched: 4, score: 0 },
        ]);
        const prepared = await prepare(first, replacement, { updateExisting: true });
        assert.deepEqual(counts(prepared.page), { add: 0, update: 2, unchanged: 0, skipped: 0 });
        const result = await confirm(first, prepared.importToken);
        assert.equal(result.updated, 2);
        assert.equal(result['exp-after'], completedExp + 45);
        assert.deepEqual(watchlist(await first.client.request('/watchlist')).get(anime.id), { status: 'completed', watched: anime.episodes });
        assert.equal(ownRating(await first.client.request('/anime/' + anime.id)), 1);
        assert.equal(ownRating(await first.client.request('/anime/' + rated.id)), null);
        const repeated = await prepare(first, replacement, { updateExisting: true });
        assert.deepEqual(counts(repeated.page), { add: 0, update: 0, unchanged: 2, skipped: 0 });
        await confirm(first, repeated.importToken);
        assert.equal(exp(await first.client.request('/profile')), completedExp + 45);
    });

    await t.test('a default import after watchlist removal preserves retained progress and EXP', async () => {
        redirected(await first.client.request('/watchlist/remove', { animeId: anime.id, csrfToken: first.token }), '/watchlist');
        const older = xml([{ malId: anime.malId, status: 'Plan to Watch', score: 5 }]);
        const prepared = await prepare(first, older);
        assert.equal(counts(prepared.page).add, 1);
        await confirm(first, prepared.importToken);
        assert.deepEqual(watchlist(await first.client.request('/watchlist')).get(anime.id), { status: 'completed', watched: anime.episodes });
        assert.equal(ownRating(await first.client.request('/anime/' + anime.id)), 1);
        assert.equal(exp(await first.client.request('/profile')), completedExp + 45);
        const reset = await prepare(first, xml([{ malId: anime.malId, status: 'Plan to Watch', score: 0 }]), { updateExisting: true });
        await confirm(first, reset.importToken);
        assert.equal(exp(await first.client.request('/profile')), 45);
        assert.equal(ownRating(await first.client.request('/anime/' + anime.id)), null);
    });

    await t.test('changed data refreshes a stale preview and cancellation invalidates its confirmation token', async () => {
        const incoming = xml([{ malId: last.malId, status: 'Completed', score: 8 }]);
        const prepared = await prepare(first, incoming, { updateExisting: true });
        redirected(await first.client.request('/watchlist', { animeId: last.id, status: 'watching', watched: 1, csrfToken: first.token }), '/watchlist');
        const stale = await first.client.request(importRoute + '/confirm', { csrfToken: first.token, importToken: prepared.importToken });
        assert.equal(stale.status, 409);
        const updatedPreview = await first.client.request(importRoute);
        const freshToken = token(updatedPreview, 'importToken');
        assert.notEqual(freshToken, prepared.importToken, 'Changed data requires a freshly reviewed confirmation');
        assert.deepEqual(watchlist(await first.client.request('/watchlist')).get(last.id), { status: 'watching', watched: 1 });
        assert.equal(ownRating(await first.client.request('/anime/' + last.id)), null);
        assert.equal(exp(await first.client.request('/profile')), 50);
        redirected(await first.client.request(importRoute + '/cancel', { csrfToken: first.token, importToken: freshToken }), importRoute);
        assert.doesNotMatch((await first.client.request(importRoute)).html, /\bdata-import-preview\b/);
        for (const importToken of [freshToken, prepared.importToken, 'wrong']) {
            assert.equal((await first.client.request(importRoute + '/confirm', { csrfToken: first.token, importToken })).status, 409);
        }
        assert.equal(exp(await first.client.request('/profile')), 50);
    });

    await t.test('another account imports its own data without reading or modifying the first account', async () => {
        const prepared = await prepare(second, xml([{ malId: anime.malId, status: 'Completed', score: 4 }]), { updateExisting: true });
        await confirm(second, prepared.importToken);
        assert.equal(exp(await second.client.request('/profile')), completedExp);
        assert.equal(ownRating(await second.client.request('/anime/' + anime.id)), 4);
        assert.equal(exp(await first.client.request('/profile')), 50);
        assert.equal(ownRating(await first.client.request('/anime/' + anime.id)), null);
        assert.deepEqual(watchlist(await first.client.request('/watchlist')).get(anime.id), { status: 'planned', watched: 0 });
        assert.equal(watchlist(await second.client.request('/watchlist')).size, 1);
    });
});
