const assert = require('node:assert/strict');
const test = require('node:test');
const { browser, csrf, login } = require('./helpers/quiz-fixtures.cjs');
const { createAnimeClueFixture, cleanupAnimeClueFixture, clueRecords, seedHistoricalClueProgress, guessFields } = require('./helpers/anime-clue-fixtures.cjs');

const escapeHtml = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
function redirected(page, route = '/guess') {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), route);
}
function state(page, { round = null, hints = null, score = 0, completed = false } = {}) {
    assert.equal(page.status, 200);
    assert.match(page.html, new RegExp('data-guess-score="' + score + '"'));
    assert.match(page.html, new RegExp('data-guess-completed="' + Number(completed) + '"'));
    if (round !== null) assert.match(page.html, new RegExp('data-guess-round="' + round + '"'));
    if (hints !== null) {
        assert.match(page.html, new RegExp('data-guess-hints="' + hints + '"'));
        assert.equal([...page.html.matchAll(/data-guess-clue="/g)].length, hints);
    }
    if (completed) assert.doesNotMatch(page.html, /data-guess-answer-form|action="\/guess\/reveal"|action="\/guess\/skip"/);
}

test('anime clues keep secrets, score server-side and unlock five game achievements', { timeout: 180000 }, async t => {
    const fixture = createAnimeClueFixture();
    try {
        const guest = browser();
        const owner = await login(fixture.owner, fixture.password);
        const other = await login(fixture.other, fixture.password);
        const initial = await owner.request('/guess');
        const token = csrf(initial);
        const otherToken = csrf(await other.request('/guess'));
        const fields = (round = 0, extra = {}) => guessFields(fixture, token, round, extra);

        await t.test('guest sees the explanation and cannot modify progress', async () => {
            const intro = await guest.request('/guess');
            assert.equal(intro.status, 200);
            assert.match(intro.html, /href="\/login"/);
            assert.doesNotMatch(intro.html, /data-guess-clue="|data-guess-answer-form/);
            for (const action of ['answer', 'reveal', 'skip']) redirected(await guest.request('/guess/' + action, fields()), '/login');
            redirected(await guest.request('/guess/ranking'), '/login');
            redirected(await guest.request('/guess/suggestions?q=nar'), '/login');
            assert.equal(clueRecords(fixture).attempt, null);
        });

        await t.test('the initial page exposes one clue, no titles, aliases or later clues', async () => {
            state(initial, { round: 0, hints: 1 });
            assert.match(initial.headers.get('cache-control'), /no-store/);
            assert.ok(initial.html.includes(escapeHtml(fixture.game.entries[0].clues.pl[0])));
            for (const entry of fixture.game.entries) {
                for (const locale of ['pl', 'en', 'de', 'fr', 'es', 'ru']) {
                    for (let hint = 1; hint < 3; hint++) assert.ok(!initial.html.includes(escapeHtml(entry.clues[locale][hint])), 'A future hint is not serialized');
                }
                assert.ok(!initial.html.includes('value="' + escapeHtml(entry.title) + '"'));
                assert.ok(!initial.html.includes('<h3>' + escapeHtml(entry.title) + '</h3>'));
            }
            assert.doesNotMatch(initial.html, /sourceUrl|aliases|data-anime-id=|data-answer-key|"entries":/);
            assert.equal(clueRecords(fixture).attempt, null, 'GET does not create user participation');
        });

        await t.test('title suggestions match substrings and aliases without depending on the current round', async () => {
            for (const query of ['nar', 'NAR', 'naruto']) {
                const page = await owner.request('/guess/suggestions?q=' + query + '&gameId=' + fixture.game.id + '&roundIndex=4');
                assert.equal(page.status, 200);
                assert.match(page.headers.get('content-type'), /application\/json/);
                assert.match(page.headers.get('cache-control'), /no-store/);
                const result = JSON.parse(page.html);
                assert.ok(result.suggestions.some(entry => entry.title === 'Naruto'));
                assert.ok(result.suggestions.length <= 10);
                for (const entry of result.suggestions) assert.deepEqual(Object.keys(entry).sort(), ['matchedTitle', 'title']);
            }
            const alias = JSON.parse((await owner.request('/guess/suggestions?q=' + encodeURIComponent('zabojcy demonow'))).html);
            assert.ok(alias.suggestions.some(entry => entry.title === 'Demon Slayer: Kimetsu no Yaiba'));
            const another = await other.request('/guess/suggestions?q=nar');
            assert.deepEqual(JSON.parse(another.html), JSON.parse((await owner.request('/guess/suggestions?q=nar')).html));
            for (const query of ['', 'n', 'a'.repeat(201)]) {
                const page = await owner.request('/guess/suggestions?q=' + query);
                assert.ok([200, 422].includes(page.status));
                if (page.status === 200) assert.deepEqual(JSON.parse(page.html).suggestions, []);
            }
            assert.equal(clueRecords(fixture).attempt, null, 'Searching never creates participation');
        });

        await t.test('invalid CSRF and malformed fields cannot reveal or consume a round', async () => {
            for (const action of ['answer', 'reveal', 'skip']) {
                assert.equal((await owner.request('/guess/' + action, fields(0, { csrfToken: 'wrong', answer: fixture.game.entries[0].title, expectedHints: 1 }))).status, 403);
            }
            for (const answer of ['', '   ', '!!!', 'a'.repeat(201)]) assert.equal((await owner.request('/guess/answer', fields(0, { answer }))).status, 422);
            assert.equal((await owner.request('/guess/answer', fields(0, { 'answer[]': fixture.game.entries[0].title }))).status, 422);
            assert.equal((await owner.request('/guess/reveal', fields(0, { expectedHints: '4' }))).status, 422);
            assert.equal((await owner.request('/guess/answer', fields(0, { answer: fixture.game.entries[0].title, expectedHints: '' }))).status, 422);
            assert.equal((await owner.request('/guess/answer', fields(0, { answer: fixture.game.entries[0].title, expectedHints: '4' }))).status, 422);
            assert.equal((await owner.request('/guess/skip', fields(4))).status, 409);
            assert.equal((await owner.request('/guess/skip', fields(0, { gameId: '9223372036854775807' }))).status, 409);
            assert.equal((await owner.request('/guess/skip', { csrfToken: token, 'gameId[]': fixture.game.id, roundIndex: 0 })).status, 409);
            assert.equal(clueRecords(fixture).attempt, null);
        });

        await t.test('a first-clue alias awards three points and stale retries preserve the answer', async () => {
            const answer = fixture.game.entries[0].aliases[0] || fixture.game.entries[0].title;
            const post = fields(0, { answer: '  ' + answer.toUpperCase() + '! ', score: 999, userId: fixture.other.id, hintsUsed: 1 });
            redirected(await owner.request('/guess/answer', post));
            const recorded = clueRecords(fixture);
            assert.equal(recorded.attempt.score, 3);
            assert.equal(recorded.attempt.roundIndex, 1);
            assert.ok(recorded.awards.some(award => award.code === 'guess_sharp'));
            assert.ok(!recorded.awards.some(award => award.code === 'guess_first'), 'Partial participation is not a completed day');
            redirected(await owner.request('/guess/answer', fields(0, { answer: 'wrong title' })));
            redirected(await owner.request('/guess/reveal', fields(0, { expectedHints: 1 })));
            redirected(await owner.request('/guess/skip', fields(0)));
            assert.deepEqual(clueRecords(fixture), recorded);
            state(await owner.request('/guess'), { round: 1, hints: 1, score: 3 });
            state(await other.request('/guess'), { round: 0, hints: 1 });
        });

        await t.test('a wrong answer is visible, unlocks the second clue and cannot be replayed to consume another chance', async () => {
            const wrong = '<svg onload=alert(1)> Definitely wrong';
            const post = fields(1, { answer: wrong });
            redirected(await owner.request('/guess/answer', post));
            state(await owner.request('/guess'), { round: 1, hints: 2, score: 3 });
            const page = await owner.request('/guess');
            assert.ok(page.html.includes(escapeHtml(wrong)));
            assert.ok(!page.html.includes(wrong));
            assert.ok(!page.html.includes('<h3>' + escapeHtml(fixture.game.entries[1].title) + '</h3>'));
            const recorded = clueRecords(fixture);
            await Promise.all([owner.request('/guess/answer', post), owner.request('/guess/answer', post)]);
            redirected(await owner.request('/guess/answer', fields(1, { answer: fixture.game.entries[1].title, expectedHints: 1 })));
            assert.deepEqual(clueRecords(fixture), recorded, 'Stale wrong/correct submissions leave the next chance intact');
            const fresh = await login(fixture.owner, fixture.password);
            state(await fresh.request('/guess'), { round: 1, hints: 2, score: 3 });
            redirected(await owner.request('/guess/answer', fields(1, { answer: fixture.game.entries[1].title, expectedHints: 2, hintsUsed: 1, points: 3 })));
            state(await owner.request('/guess'), { round: 2, hints: 1, score: 5 });
            assert.equal(clueRecords(fixture).answers[1].points, 2);
        });

        await t.test('manual reveal remains idempotent and a wrong second answer unlocks the final clue in all languages', async () => {
            redirected(await owner.request('/guess/reveal', fields(2, { expectedHints: 1 })));
            redirected(await owner.request('/guess/reveal', fields(2, { expectedHints: 1 })));
            state(await owner.request('/guess'), { round: 2, hints: 2, score: 5 });
            redirected(await owner.request('/guess/answer', fields(2, { answer: 'Second clue wrong answer', expectedHints: 2 })));
            for (const locale of ['en', 'de', 'fr', 'es', 'ru', 'pl']) {
                redirected(await owner.request('/language', { csrfToken: token, language: locale, returnTo: '/guess' }));
                const page = await owner.request('/guess');
                state(page, { round: 2, hints: 3, score: 5 });
                for (const clue of fixture.game.entries[2].clues[locale]) assert.ok(page.html.includes(escapeHtml(clue)), locale + ' hint');
                assert.ok(page.html.includes('Second clue wrong answer'));
            }
        });

        await t.test('three wrong answers finish a round and skipping remains possible after a wrong answer', async () => {
            redirected(await owner.request('/guess/answer', fields(2, { answer: fixture.game.entries[2].title, expectedHints: 3 })));
            for (let hint = 1; hint <= 3; hint++) {
                redirected(await owner.request('/guess/answer', fields(3, { answer: 'Wrong chance ' + hint, expectedHints: hint })));
                if (hint < 3) state(await owner.request('/guess'), { round: 3, hints: hint + 1, score: 6 });
            }
            state(await owner.request('/guess'), { round: 4, hints: 1, score: 6 });
            redirected(await owner.request('/guess/answer', fields(3, { answer: fixture.game.entries[3].title })));
            redirected(await owner.request('/guess/answer', fields(4, { answer: 'Wrong before skipping' })));
            state(await owner.request('/guess'), { round: 4, hints: 2, score: 6 });
            redirected(await owner.request('/guess/skip', fields(4)));
            const done = await owner.request('/guess');
            state(done, { score: 6, completed: true });
            assert.ok(done.html.includes('&lt;svg onload=alert(1)&gt; Definitely wrong'));
            assert.ok(!done.html.includes('<svg onload=alert(1)>'));
            for (const wrong of ['Second clue wrong answer', 'Wrong chance 1', 'Wrong chance 2', 'Wrong chance 3', 'Wrong before skipping']) assert.ok(done.html.includes(wrong), 'Results retain ' + wrong);
            const record = clueRecords(fixture);
            assert.deepEqual(record.answers.map(answer => answer.points), [3, 2, 1, 0, 0]);
            assert.ok(record.awards.some(award => award.code === 'guess_first'));
            assert.ok(!record.awards.some(award => award.code === 'guess_perfect'));
            const fresh = await login(fixture.owner, fixture.password);
            state(await fresh.request('/guess'), { score: 6, completed: true });
        });

        await t.test('another account can independently earn a perfect fifteen-point day', async () => {
            for (let round = 0; round < 5; round++) {
                redirected(await other.request('/guess/answer', guessFields(fixture, otherToken, round, { answer: fixture.game.entries[round].title })));
            }
            state(await other.request('/guess'), { score: 15, completed: true });
            const records = clueRecords(fixture, 'other');
            assert.ok(records.awards.some(award => award.code === 'guess_perfect'));
            assert.ok(records.awards.some(award => award.code === 'guess_first'));
            assert.equal(clueRecords(fixture).attempt.score, 6);
        });

        await t.test('weekly leaderboard shows server points and keeps each account’s own position', async () => {
            const ownerRanking = await owner.request('/guess/ranking?userId=' + fixture.other.id);
            assert.equal(ownerRanking.status, 200);
            assert.match(ownerRanking.html, /data-guess-ranking/);
            assert.match(ownerRanking.html, /data-own-guess-rank="\d+"/);
            assert.match(ownerRanking.html, /data-own-guess-points="6"/);
            assert.match(ownerRanking.html, /data-own-guess-correct="3"/);
            const otherRanking = await other.request('/guess/ranking');
            assert.equal(otherRanking.status, 200);
            assert.match(otherRanking.html, /data-own-guess-points="15"/);
            assert.match(otherRanking.html, /data-own-guess-correct="5"/);
            for (const page of [ownerRanking, otherRanking]) {
                assert.match(page.headers.get('cache-control'), /no-store/);
                assert.ok(!page.html.includes(fixture.owner.email) && !page.html.includes(fixture.other.email));
                assert.ok(!page.html.includes('<svg onload=alert(1)>'));
                assert.doesNotMatch(page.html, /sourceUrl|data-guess-clue=|"entries":|aliases/);
            }
        });

        await t.test('historical completed sets unlock fifty correct recognitions and a seven-day record', async () => {
            seedHistoricalClueProgress(fixture);
            const before = clueRecords(fixture, 'other').awards;
            assert.ok(!before.some(award => award.code === 'guess_correct_50'));
            const achievements = await other.request('/achievements');
            assert.equal(achievements.status, 200);
            const codes = clueRecords(fixture, 'other').awards.map(award => award.code);
            for (const code of ['guess_first', 'guess_sharp', 'guess_perfect', 'guess_correct_50', 'guess_streak_7']) assert.ok(codes.includes(code), code);
            assert.match(achievements.html, /data-achievement-code="guess_correct_50" data-achievement-unlocked="1"/);
            const weekly = await other.request('/guess/ranking');
            assert.equal(weekly.status, 200);
            assert.match(weekly.html, /data-own-guess-points="15"/, 'Ancient fixture sets do not enter the current week');
        });
    } finally {
        const cleanup = cleanupAnimeClueFixture(fixture);
        assert.equal(cleanup.removedUsers, 2);
        assert.equal(cleanup.removedDays, fixture.history.length);
    }
});
