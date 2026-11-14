const assert = require('node:assert/strict');
const test = require('node:test');
const { createQuizFixture, cleanupQuizFixture, browser, csrf, login, quizFields } = require('./helpers/quiz-fixtures.cjs');

const escapeHtml = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');

function score(page, expected) {
    assert.equal(page.status, 200);
    assert.match(page.html, new RegExp('data-quiz-score="' + expected + '"'));
    assert.match(page.html, /data-quiz-total="5"/);
    assert.match(page.html, /data-quiz-streak="1"/);
    assert.match(page.html, /data-quiz-best-streak="1"/);
    assert.doesNotMatch(page.html, /data-quiz-form/);
}

test('daily quiz validates on the server, saves once per account and preserves EXP', { timeout: 120000 }, async t => {
    const fixture = createQuizFixture();
    try {
        const guest = browser();
        const owner = await login(fixture.owner, fixture.password);
        const other = await login(fixture.other, fixture.password);
        const page = await owner.request('/quiz');
        const token = csrf(page);
        const fields = quizFields(fixture, token);

        await t.test('guest sees login and cannot submit a quiz', async () => {
            const landing = await guest.request('/quiz');
            assert.equal(landing.status, 200);
            assert.match(landing.html, /href="\/login"/);
            assert.doesNotMatch(landing.html, /data-quiz-form|data-quiz-question-id/);
            const post = await guest.request('/quiz', fields);
            assert.equal(post.status, 303);
            assert.equal(post.headers.get('location'), '/login');
            const ranking = await guest.request('/quiz/ranking');
            assert.equal(ranking.status, 303);
            assert.equal(ranking.headers.get('location'), '/login');
        });

        await t.test('questions, choices and explanations follow all six languages without changing IDs', async () => {
            for (const locale of ['en', 'de', 'fr', 'es', 'ru', 'pl']) {
                const changed = await owner.request('/language', { language: locale, returnTo: '/quiz', csrfToken: token });
                assert.equal(changed.status, 303);
                const translated = await owner.request('/quiz');
                assert.equal(translated.status, 200);
                assert.match(translated.html, new RegExp('<html lang="' + locale + '"'));
                assert.doesNotMatch(translated.html, /quiz-language-note/);
                if (locale !== 'pl') assert.doesNotMatch(translated.html, /<fieldset[^>]*\blang="pl"/);
                for (const question of fixture.localized[locale].questions) {
                    assert.ok(translated.html.includes(escapeHtml(question.question)), locale + ': translated question');
                    question.options.forEach(option => assert.ok(translated.html.includes(escapeHtml(option)), locale + ': translated option'));
                    assert.ok(!translated.html.includes(escapeHtml(question.explanation)), locale + ': no early explanation');
                }
            }
        });

        await t.test('daily questions are stable and answers stay on the server', async () => {
            assert.equal(page.status, 200);
            assert.match(page.headers.get('cache-control'), /no-store/);
            assert.match(page.html, /data-quiz-form/);
            const ids = html => [...html.matchAll(/data-quiz-question-id="(\d+)"/g)].map(match => Number(match[1]));
            assert.deepEqual(ids(page.html), fixture.quiz.questions.map(question => question.id));
            assert.deepEqual(ids((await owner.request('/quiz')).html), ids(page.html));
            assert.deepEqual(ids((await other.request('/quiz')).html), ids(page.html));
            assert.doesNotMatch(page.html, /correctOption|data-correct|data-quiz-score=/);
            for (const question of fixture.quiz.questions) {
                assert.ok(!page.html.includes(question.explanation), 'Explanations are hidden before completion');
            }
            assert.match(page.html, /data-quiz-streak="0"/);
            assert.ok(!page.html.includes('<svg onload=alert(1)>'));
        });

        await t.test('invalid CSRF, question IDs and answer shapes cannot save participation', async () => {
            for (const invalid of ['', 'wrong']) {
                assert.equal((await owner.request('/quiz', { ...fields, csrfToken: invalid })).status, 403);
            }
            const malformed = [
                { ...fields, quizId: '0' },
                { ...fields, quizId: '1.5' },
                { ...fields, quizId: '9223372036854775808' },
                { ...fields, ['answers[' + fixture.quiz.questions[0].id + ']']: '4' },
                { ...fields, ['answers[' + fixture.quiz.questions[0].id + ']']: '1.5' },
                { ...fields, 'answers[999999999]': '0' },
                { quizId: fixture.quiz.id, csrfToken: token, answers: 'forged' },
            ];
            const missing = { ...fields };
            delete missing['answers[' + fixture.quiz.questions[0].id + ']'];
            malformed.push(missing);
            const nested = { ...missing, ['answers[' + fixture.quiz.questions[0].id + '][]']: '1' };
            malformed.push(nested);
            const arrayQuizId = { ...fields, 'quizId[]': String(fixture.quiz.id) };
            delete arrayQuizId.quizId;
            malformed.push(arrayQuizId);
            for (const invalid of malformed) {
                assert.equal((await owner.request('/quiz', invalid)).status, 422);
            }
            const expired = await owner.request('/quiz', { ...fields, quizId: '9223372036854775807' });
            assert.equal(expired.status, 409);
            const partial = await owner.request('/quiz', {
                quizId: fixture.quiz.id, csrfToken: token,
                ['answers[' + fixture.quiz.questions[0].id + ']']: '2',
            });
            assert.equal(partial.status, 422);
            assert.match(partial.html, new RegExp('name="answers\\[' + fixture.quiz.questions[0].id + '\\]" value="2" checked'));
            const untouched = await owner.request('/quiz');
            assert.match(untouched.html, /data-quiz-form/);
            assert.match(untouched.html, /data-quiz-streak="0"/);
        });

        await t.test('server ignores a forged score, streak and owner, and zero points still earn participation', async () => {
            const submitted = await owner.request('/quiz', {
                ...quizFields(fixture, token, false), userId: fixture.other.id, user_id: fixture.other.id,
                score: '5', currentStreak: '100', completedAt: '2000-01-01',
            });
            assert.equal(submitted.status, 303);
            assert.equal(submitted.headers.get('location'), '/quiz');
            score(await owner.request('/quiz'), 0);
            assert.match((await other.request('/quiz')).html, /data-quiz-form/);
            const dashboard = await owner.request('/dashboard');
            assert.equal(dashboard.status, 200);
            assert.match(dashboard.html, /data-quiz-streak="1"/);
            assert.match((await owner.request('/profile')).html, /data-user-exp="0"/);
        });

        await t.test('repeated submissions and another session preserve the first result', async () => {
            const sameAccount = await login(fixture.owner, fixture.password);
            const anotherToken = csrf(await sameAccount.request('/quiz'));
            const responses = await Promise.all([
                owner.request('/quiz', fields),
                sameAccount.request('/quiz', quizFields(fixture, anotherToken)),
            ]);
            responses.forEach(response => assert.equal(response.status, 303));
            score(await owner.request('/quiz'), 0);
            score(await sameAccount.request('/quiz'), 0);
            const result = await owner.request('/quiz');
            assert.ok(result.html.includes(escapeHtml(fixture.quiz.questions[0].explanation)));
            assert.doesNotMatch(result.html, /quiz-source|Sprawdź źródło|sourceUrl/);
        });

        await t.test('the second account has an independent result and a fresh session retains participation', async () => {
            const otherToken = csrf(await other.request('/quiz'));
            assert.equal((await other.request('/quiz', quizFields(fixture, otherToken))).status, 303);
            score(await other.request('/quiz'), 5);
            const freshSession = await login(fixture.owner, fixture.password);
            score(await freshSession.request('/quiz'), 0);
        });

        await t.test('aggregate scores and both leaderboard tabs use completed answers only', async () => {
            const ownerPage = await owner.request('/quiz');
            assert.match(ownerPage.html, /data-quiz-correct="0" data-quiz-answered="5"/);
            const otherPage = await other.request('/quiz');
            assert.match(otherPage.html, /data-quiz-correct="5" data-quiz-answered="5"/);
            for (const period of ['overall', 'monthly']) {
                const route = '/quiz/ranking?period=' + period;
                const ranked = await other.request(route);
                assert.equal(ranked.status, 200);
                assert.match(ranked.html, new RegExp('data-quiz-ranking-period="' + period + '"'));
                const row = ranked.html.match(new RegExp('<li[^>]+data-quiz-ranking-user="' + fixture.other.id + '"[^>]*>'));
                if (row) assert.match(row[0], /data-quiz-ranking-correct="5"/);
                assert.match(ranked.html, /data-own-quiz-rank="\d+"/);
                assert.match(ranked.html, /data-own-quiz-correct="5" data-own-quiz-total="5"/);
                assert.ok(!ranked.html.includes(fixture.owner.email) && !ranked.html.includes(fixture.other.email));
                assert.ok(!ranked.html.includes('<svg onload=alert(1)>'));
                if (row) assert.ok(ranked.html.includes('Quiz other &lt;svg onload=alert(1)&gt;'));
            }
            assert.equal((await owner.request('/quiz/ranking?period=forged')).status, 400);
            assert.equal((await owner.request('/quiz/ranking?period[]=monthly')).status, 400);
        });
    } finally {
        assert.equal(cleanupQuizFixture(fixture).removed, 2);
    }
});
