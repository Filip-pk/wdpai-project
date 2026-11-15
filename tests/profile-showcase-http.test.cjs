const assert = require('node:assert/strict');
const test = require('node:test');
const { browser, csrf, login } = require('./helpers/quiz-fixtures.cjs');
const { createAchievementFixture, seedAchievementProgress, cleanupAchievementFixture,
    ownedAchievementRecords, setAchievementPrivacy } = require('./helpers/achievement-fixtures.cjs');

function fields(token, animeIds = [], badgeCodes = [], extra = {}) {
    const form = new FormData();
    form.append('csrfToken', token);
    for (const id of animeIds) form.append('animeIds[]', String(id));
    for (const code of badgeCodes) form.append('badgeCodes[]', code);
    for (const [key, value] of Object.entries(extra)) form.append(key, value);
    return form;
}

function selection(page) {
    return {
        animeIds: [...page.html.matchAll(/data-showcase-anime-id="(\d+)"/g)].map(match => Number(match[1])),
        badgeCodes: [...page.html.matchAll(/data-showcase-badge-code="([a-z0-9_]+)"/g)].map(match => match[1]),
    };
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

test('profile showcase persists ordered favorites, validates ownership and respects badge privacy', { timeout: 180000 }, async t => {
    const fixture = createAchievementFixture();
    try {
        const guest = browser();
        const owner = await login(fixture.owner, fixture.password);
        const other = await login(fixture.other, fixture.password);
        const initial = await owner.request('/profile/showcase');
        const token = csrf(initial);
        const empty = { animeIds: [], badgeCodes: [] };

        await t.test('guests cannot read the editor, search or write a showcase', async () => {
            redirected(await guest.request('/profile/showcase'), '/login');
            redirected(await guest.request('/profile/showcase/anime?q=anime'), '/login');
            redirected(await guest.request('/profile/showcase', fields('')), '/login');
            assert.equal(initial.status, 200);
            assert.match(initial.html, /data-showcase-editor/);
            assert.deepEqual(selection(await owner.request('/profile')), empty);
        });

        await t.test('catalog search returns bounded matching data without awarding achievements', async () => {
            const before = ownedAchievementRecords(fixture);
            const response = await owner.request('/profile/showcase/anime?q=' + encodeURIComponent(fixture.marker));
            assert.equal(response.status, 200);
            assert.match(response.headers.get('content-type'), /application\/json/);
            const result = JSON.parse(response.html);
            assert.equal(result.animes.length, 10);
            assert.ok(result.animes.every(anime => Number.isInteger(anime.id) && anime.title.includes(fixture.marker)));
            assert.ok(result.animes.every(anime => ['id', 'title', 'image', 'year', 'type'].every(key => key in anime)));
            const all = JSON.parse((await owner.request('/profile/showcase/anime')).html);
            assert.ok(all.animes.length <= 24);
            const literal = JSON.parse((await owner.request('/profile/showcase/anime?q=' + encodeURIComponent(fixture.marker + '%'))).html);
            assert.deepEqual(literal.animes, [], 'SQL wildcard characters are searched literally');
            assert.deepEqual(ownedAchievementRecords(fixture), before);
        });

        seedAchievementProgress(fixture);
        await owner.request('/achievements');
        const wanted = {
            animeIds: [fixture.animes[2].id, fixture.animes[0].id, fixture.animes[1].id],
            badgeCodes: ['quiz_perfect', 'anime_first', 'quiz_first'],
        };

        await t.test('three anime and earned badges preserve the chosen order after reload', async () => {
            redirected(await owner.request('/profile/showcase', fields(token, wanted.animeIds, wanted.badgeCodes)), '/profile?showcaseSaved=1');
            const saved = await owner.request('/profile?showcaseSaved=1');
            assert.match(saved.html, /data-profile-showcase/);
            assert.deepEqual(selection(saved), wanted);
            const publicPage = await guest.request('/users/' + fixture.owner.id);
            assert.deepEqual(selection(publicPage), wanted);
            assert.ok(!publicPage.html.includes(fixture.owner.email));
            assert.doesNotMatch(publicPage.html, /<svg onload=alert\(1\)>/);
            assert.doesNotMatch(publicPage.html, /data-showcase-editor/);
        });

        await t.test('CSRF failures leave the whole previous selection intact', async () => {
            for (const invalidToken of ['', 'f'.repeat(64)]) {
                assert.equal((await owner.request('/profile/showcase', fields(invalidToken))).status, 403);
                assert.deepEqual(selection(await owner.request('/profile')), wanted);
            }
        });

        await t.test('invalid, duplicate, oversized and unearned selections never partially save', async () => {
            const badSelections = [
                [wanted.animeIds.concat(fixture.animes[3].id), wanted.badgeCodes],
                [[wanted.animeIds[0], wanted.animeIds[0]], wanted.badgeCodes],
                [[2147483647], wanted.badgeCodes],
                [['1 OR 1=1'], wanted.badgeCodes],
                [wanted.animeIds, wanted.badgeCodes.concat('quiz_streak_7')],
                [wanted.animeIds, ['quiz_first', 'quiz_first']],
                [wanted.animeIds, ['unknown_badge']],
                [wanted.animeIds, ['anime_completed_50']],
            ];
            for (const [animeIds, badges] of badSelections) {
                const response = await owner.request('/profile/showcase', fields(token, animeIds, badges));
                assert.equal(response.status, 422, JSON.stringify({ animeIds, badges }));
                assert.match(response.html, /role="alert"/);
                assert.deepEqual(selection(await owner.request('/profile')), wanted);
            }
            for (const malformed of [
                { csrfToken: token, animeIds: '1', 'badgeCodes[]': 'quiz_first' },
                { csrfToken: token, 'animeIds[0][id]': '1' },
                { csrfToken: token, badgeCodes: 'quiz_first' },
            ]) {
                assert.equal((await owner.request('/profile/showcase', malformed)).status, 422);
                assert.deepEqual(selection(await owner.request('/profile')), wanted);
            }
        });

        await t.test('foreign target fields cannot write another account or select its earned badges', async () => {
            const otherToken = csrf(await other.request('/profile/showcase'));
            assert.equal((await other.request('/profile/showcase?userId=' + fixture.owner.id,
                fields(otherToken, wanted.animeIds, ['quiz_first'], { userId: fixture.owner.id }))).status, 422);
            redirected(await other.request('/profile/showcase?userId=' + fixture.owner.id,
                fields(otherToken, [fixture.animes[3].id], [], { userId: fixture.owner.id })), '/profile?showcaseSaved=1');
            assert.deepEqual(selection(await other.request('/profile')), { animeIds: [fixture.animes[3].id], badgeCodes: [] });
            assert.deepEqual(selection(await owner.request('/profile')), wanted);
        });

        await t.test('private statistics hide selected badges from guests and others while retaining favorite anime', async () => {
            setAchievementPrivacy(fixture, false);
            const before = ownedAchievementRecords(fixture);
            for (const viewer of [guest, other]) {
                const page = await viewer.request('/users/' + fixture.owner.id);
                assert.deepEqual(selection(page), { animeIds: wanted.animeIds, badgeCodes: [] });
                assert.doesNotMatch(page.html, /data-profile-achievements|data-achievement-code=/);
            }
            assert.deepEqual(selection(await owner.request('/users/' + fixture.owner.id)), wanted);
            assert.deepEqual(ownedAchievementRecords(fixture), before, 'Public views do not award badges');
        });

        await t.test('all six editor languages resolve showcase messages', async () => {
            for (const language of ['en', 'de', 'fr', 'es', 'ru', 'pl']) {
                redirected(await owner.request('/language', { csrfToken: token, language, returnTo: '/profile/showcase' }), '/profile/showcase');
                const page = await owner.request('/profile/showcase');
                assert.equal(page.status, 200);
                assert.match(page.html, new RegExp('<html lang="' + language + '"'));
                assert.doesNotMatch(page.html, />showcase\.[a-z_.]+[<\s]/);
            }
        });

        await t.test('empty selector slots and clearing the showcase are supported', async () => {
            redirected(await owner.request('/profile/showcase', fields(token, ['', fixture.animes[4].id, ''], [])), '/profile?showcaseSaved=1');
            assert.deepEqual(selection(await owner.request('/profile')), { animeIds: [fixture.animes[4].id], badgeCodes: [] });
            redirected(await owner.request('/profile/showcase', fields(token)), '/profile?showcaseSaved=1');
            assert.deepEqual(selection(await owner.request('/profile')), empty);
            assert.deepEqual(selection(await guest.request('/users/' + fixture.owner.id)), empty);
        });
    } finally {
        const removed = cleanupAchievementFixture(fixture);
        assert.equal(removed.removedUsers, 2);
        assert.equal(removed.removedAnime, 10);
        assert.equal(removed.removedHistoricalQuizzes, 30);
    }
});
