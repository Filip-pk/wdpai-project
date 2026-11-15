const assert = require('node:assert/strict');
const test = require('node:test');
const { browser, csrf, login, quizFields } = require('./helpers/quiz-fixtures.cjs');
const { createAchievementFixture, seedAchievementProgress, cleanupAchievementFixture, ownedAchievementRecords } = require('./helpers/achievement-fixtures.cjs');

function card(page, code, unlocked, progress) {
    assert.equal(page.status, 200);
    const match = page.html.match(new RegExp('<[^>]+data-achievement-code="' + code + '"[^>]*>'));
    assert.ok(match, 'Achievement card: ' + code);
    assert.match(match[0], new RegExp('data-achievement-unlocked="' + Number(unlocked) + '"'));
    if (progress !== undefined) assert.match(match[0], new RegExp('data-achievement-progress="' + progress + '"'));
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

test('achievements follow saved activity, preserve unlocks and respect profile privacy', { timeout: 180000 }, async t => {
    const fixture = createAchievementFixture();
    try {
        const guest = browser();
        const owner = await login(fixture.owner, fixture.password);
        const other = await login(fixture.other, fixture.password);
        const initial = await owner.request('/achievements');
        const token = csrf(initial);
        let firstUnlocks;

        await t.test('only the signed-in account can open its achievement progress', async () => {
            redirected(await guest.request('/achievements'), '/login');
            assert.equal(initial.status, 200);
            assert.match(initial.html, /data-achievements-page/);
            assert.equal([...initial.html.matchAll(/data-achievement-code="/g)].length, 10);
            assert.equal([...initial.html.matchAll(/data-achievement-unlocked="0"/g)].length, 10);
            assert.equal(ownedAchievementRecords(fixture).length, 0);
            const forged = await owner.request('/achievements?userId=' + fixture.other.id + '&achievement_code=quiz_perfect&progress=999');
            card(forged, 'quiz_perfect', false, 0);
            assert.equal((await owner.request('/achievements', { csrfToken: token, code: 'quiz_perfect', progress: 999 })).status, 404);
            assert.equal(ownedAchievementRecords(fixture).length, 0);
        });

        await t.test('valid quiz submission awards first quiz and perfect score before any profile GET', async () => {
            const fields = quizFields(fixture, token);
            assert.equal((await owner.request('/quiz', { ...fields, csrfToken: 'wrong' })).status, 403);
            assert.deepEqual(ownedAchievementRecords(fixture), []);
            redirected(await owner.request('/quiz', { ...fields, userId: fixture.other.id }), '/quiz');
            firstUnlocks = ownedAchievementRecords(fixture);
            assert.deepEqual(firstUnlocks.map(row => row.code), ['quiz_first', 'quiz_perfect']);
            assert.deepEqual(ownedAchievementRecords(fixture, 'other'), []);
            redirected(await owner.request('/quiz', fields), '/quiz');
            assert.deepEqual(ownedAchievementRecords(fixture), firstUnlocks, 'A retry preserves the first unlock timestamp');
        });

        await t.test('watchlist completion awards a badge and invalid CSRF cannot change progress', async () => {
            const anime = fixture.animes[0];
            const fields = { animeId: anime.id, status: 'completed', watched: '0', csrfToken: token };
            assert.equal((await owner.request('/watchlist', { ...fields, csrfToken: 'wrong' })).status, 403);
            assert.deepEqual(ownedAchievementRecords(fixture), firstUnlocks);
            redirected(await owner.request('/watchlist', { ...fields, user_id: fixture.other.id }), '/watchlist');
            assert.ok(ownedAchievementRecords(fixture).some(row => row.code === 'anime_first'));
            assert.deepEqual(ownedAchievementRecords(fixture, 'other'), []);
        });

        await t.test('public profile reads cannot award pending badges and the owner backfills saved progress', async () => {
            seedAchievementProgress(fixture, { quizDays: 7 });
            const before = ownedAchievementRecords(fixture);
            const publicPage = await other.request('/users/' + fixture.owner.id);
            assert.equal(publicPage.status, 200);
            assert.match(publicPage.html, /data-profile-achievements/);
            assert.deepEqual(ownedAchievementRecords(fixture), before, 'A visitor cannot award badges on behalf of another user');
            const progress = await owner.request('/achievements');
            card(progress, 'quiz_streak_7', true, 7);
            card(progress, 'quiz_streak_30', false, 7);
            card(progress, 'quiz_correct_100', false, 40);
            card(progress, 'anime_completed_10', true, 10);
            card(progress, 'anime_completed_50', false, 10);
            card(progress, 'anime_genres_5', true, 5);
            card(progress, 'anime_episodes_100', true, 100);
            assert.equal(ownedAchievementRecords(fixture).length, 7);
            const visible = await guest.request('/users/' + fixture.owner.id);
            assert.match(visible.html, /data-profile-achievements/);
            assert.equal([...visible.html.matchAll(/data-achievement-code="/g)].length, 7);
            assert.doesNotMatch(visible.html, /data-achievement-unlocked="0"/);
            assert.ok(!visible.html.includes(fixture.owner.email));
            assert.ok(!visible.html.includes('<svg onload=alert(1)>'));
        });

        await t.test('100 correct answers and a 30-day record unlock only at their thresholds', async () => {
            seedAchievementProgress(fixture, { quizDays: 20 });
            const hundred = await owner.request('/achievements');
            card(hundred, 'quiz_correct_100', true, 100);
            card(hundred, 'quiz_streak_30', false, 20);
            seedAchievementProgress(fixture, { quizDays: 30 });
            const month = await owner.request('/achievements');
            card(month, 'quiz_streak_30', true, 30);
            card(month, 'anime_completed_50', false, 10);
            assert.equal(ownedAchievementRecords(fixture).length, 9);
        });

        await t.test('correcting or removing watchlist activity keeps earned badges and their timestamps', async () => {
            const before = ownedAchievementRecords(fixture);
            for (const anime of fixture.animes) {
                redirected(await owner.request('/watchlist', { animeId: anime.id, status: 'planned', watched: 0, csrfToken: token }), '/watchlist');
                redirected(await owner.request('/watchlist/remove', { animeId: anime.id, csrfToken: token }), '/watchlist');
            }
            const retained = await owner.request('/achievements');
            card(retained, 'anime_completed_10', true, 10);
            card(retained, 'anime_genres_5', true, 5);
            card(retained, 'anime_episodes_100', true, 100);
            assert.deepEqual(ownedAchievementRecords(fixture), before);
        });

        await t.test('private statistics hide profile badges from guests and other accounts', async () => {
            redirected(await owner.request('/settings/privacy', {
                csrfToken: token, messagePolicy: 'everyone', publicRatings: '0', publicStatistics: '0',
            }), '/settings#privacy');
            for (const viewer of [guest, other]) {
                const hidden = await viewer.request('/users/' + fixture.owner.id);
                assert.equal(hidden.status, 200);
                assert.doesNotMatch(hidden.html, /data-profile-achievements|data-achievement-code=/);
            }
            const own = await owner.request('/users/' + fixture.owner.id);
            assert.match(own.html, /data-profile-achievements/);
            assert.match((await owner.request('/profile')).html, /data-profile-achievements/);
            const foreignQuery = await other.request('/achievements?userId=' + fixture.owner.id);
            card(foreignQuery, 'quiz_first', false, 0);
            assert.deepEqual(ownedAchievementRecords(fixture, 'other'), []);
        });

        await t.test('all achievement labels follow the six interface languages', async () => {
            for (const locale of ['en', 'de', 'fr', 'es', 'ru', 'pl']) {
                redirected(await owner.request('/language', { language: locale, returnTo: '/achievements', csrfToken: token }), '/achievements');
                const translated = await owner.request('/achievements');
                assert.equal(translated.status, 200);
                assert.match(translated.html, new RegExp('<html lang="' + locale + '"'));
                assert.doesNotMatch(translated.html, />achievements\.[a-z_]+/);
                card(translated, 'quiz_perfect', true, 1);
            }
        });

        await t.test('confirmed MAL import awards watching achievements in the same request', async () => {
            assert.deepEqual(ownedAchievementRecords(fixture, 'other'), []);
            const page = await other.request('/profile/import');
            const importCsrf = csrf(page);
            const xml = '<?xml version="1.0" encoding="UTF-8"?><myanimelist><myinfo><user_export_type>1</user_export_type></myinfo>'
                + '<anime><series_animedb_id>' + fixture.animes[0].malId + '</series_animedb_id>'
                + '<series_title>Achievement import fixture</series_title><my_status>Completed</my_status>'
                + '<my_watched_episodes>0</my_watched_episodes><my_score>0</my_score></anime></myanimelist>';
            const upload = new FormData();
            upload.append('csrfToken', importCsrf);
            upload.append('malFile', new Blob([xml], { type: 'application/xml' }), 'achievement-list.xml');
            redirected(await other.request('/profile/import/preview', upload), '/profile/import');
            const preview = await other.request('/profile/import');
            const importToken = preview.html.match(/name="importToken"\s+value="([a-f0-9]{64})"/);
            assert.ok(importToken, 'The imported file requires explicit confirmation');
            assert.deepEqual(ownedAchievementRecords(fixture, 'other'), [], 'Preview alone never grants achievements');
            redirected(await other.request('/profile/import/confirm', { csrfToken: importCsrf, importToken: importToken[1] }), '/profile/import?imported=1');
            assert.deepEqual(ownedAchievementRecords(fixture, 'other').map(row => row.code), ['anime_first']);
        });
    } finally {
        const removed = cleanupAchievementFixture(fixture);
        assert.equal(removed.removedUsers, 2);
        assert.equal(removed.removedAnime, 10);
        assert.equal(removed.removedHistoricalQuizzes, 30);
    }
});
