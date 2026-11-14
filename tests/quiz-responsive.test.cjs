const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { baseUrl, createQuizFixture, cleanupQuizFixture, login } = require('./helpers/quiz-fixtures.cjs');

const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

class Cdp {
    constructor(socket) {
        this.socket = socket;
        this.sequence = 0;
        this.pending = new Map();
        this.errors = [];
        socket.addEventListener('message', event => {
            const message = JSON.parse(event.data);
            if (message.id) {
                const pending = this.pending.get(message.id);
                if (!pending) return;
                this.pending.delete(message.id);
                clearTimeout(pending.timer);
                if (message.error) pending.reject(new Error(message.error.message));
                else pending.resolve(message.result);
            } else if (message.method === 'Runtime.exceptionThrown') {
                this.errors.push(message.params.exceptionDetails.text);
            }
        });
    }

    send(method, params = {}) {
        const id = ++this.sequence;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                this.pending.delete(id);
                reject(new Error('CDP timeout: ' + method));
            }, 15000);
            this.pending.set(id, { resolve, reject, timer });
            this.socket.send(JSON.stringify({ id, method, params }));
        });
    }

    async evaluate(expression) {
        const response = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert.equal(response.exceptionDetails, undefined, expression.slice(0, 100));
        return response.result.value;
    }
}

async function launchBrowser() {
    const executable = [process.env.ANIVIBE_BROWSER,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ].find(candidate => candidate && fs.existsSync(candidate));
    assert.ok(executable, 'Set ANIVIBE_BROWSER to an installed Chrome or Edge executable');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'anivibe-quiz-browser-'));
    const child = spawn(executable, ['--headless=new', '--remote-debugging-port=0', '--no-first-run',
        '--no-default-browser-check', '--disable-background-networking', '--disable-component-update',
        '--disable-default-apps', '--disable-sync', '--user-data-dir=' + profile, 'about:blank'], {
        windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
    });
    let socket;
    async function close() {
        if (socket) socket.close();
        if (child.exitCode === null) {
            const stopped = once(child, 'exit').catch(() => {});
            child.kill();
            await Promise.race([stopped, pause(3000)]);
        }
        // Delete only this test's exact, freshly created profile inside the OS temp directory.
        assert.equal(path.dirname(profile), path.resolve(os.tmpdir()));
        assert.ok(path.basename(profile).startsWith('anivibe-quiz-browser-'));
        fs.rmSync(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    }
    try {
        let output = '';
        const endpoint = await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Browser startup timeout: ' + output.slice(-1000))), 15000);
            child.once('error', error => { clearTimeout(timer); reject(error); });
            child.stderr.on('data', data => {
                output += data.toString();
                const match = output.match(/DevTools listening on ws:\/\/127\.0\.0\.1:(\d+)\//);
                if (match) { clearTimeout(timer); resolve('http://127.0.0.1:' + match[1]); }
            });
        });
        const pages = await (await fetch(endpoint + '/json/list')).json();
        const target = pages.find(page => page.type === 'page');
        assert.ok(target);
        socket = new WebSocket(target.webSocketDebuggerUrl);
        await once(socket, 'open');
        const cdp = new Cdp(socket);
        for (const domain of ['Page', 'Runtime', 'Network']) await cdp.send(domain + '.enable');
        await cdp.send('Page.bringToFront');
        await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true });
        return { cdp, close };
    } catch (error) {
        await close();
        throw error;
    }
}

async function waitFor(cdp, expression) {
    for (let attempt = 0; attempt < 120; attempt++) {
        try {
            if (await cdp.evaluate(expression)) return;
        } catch (error) {
            if (!/context|navigat/i.test(error.message)) throw error;
        }
        await pause(100);
    }
    assert.fail('Page did not become ready: ' + expression);
}

async function key(cdp, value, code, virtualCode) {
    for (const type of ['keyDown', 'keyUp']) {
        await cdp.send('Input.dispatchKeyEvent', { type, key: value, code, windowsVirtualKeyCode: virtualCode });
    }
}

async function inspect(cdp, route, state, width, theme) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
    const previousOrigin = await cdp.evaluate('performance.timeOrigin');
    await cdp.send('Page.navigate', { url: new URL(route, baseUrl).href });
    const selector = route === '/dashboard' ? '.daily-quiz-card' : (route.startsWith('/quiz/ranking') ? '[data-quiz-ranking-period]' : '[data-quiz-page]');
    await waitFor(cdp, `performance.timeOrigin !== ${previousOrigin} && document.readyState === 'complete' && Boolean(document.querySelector(${JSON.stringify(selector)}))`);
    await cdp.evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}`);
    await cdp.evaluate('document.fonts.ready');
    await key(cdp, 'Tab', 'Tab', 9);
    const metrics = await cdp.evaluate(`(() => {
        const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
        const fields = [...document.querySelectorAll('main input:not([type="hidden"]), main select, main textarea')].filter(visible);
        const controls = [...document.querySelectorAll('main button, main a[href]')].filter(visible);
        const target = fields.find(field => !field.matches(':disabled')) || controls.find(control => !control.matches(':disabled'));
        if (target) target.focus();
        const style = target ? getComputedStyle(target) : null;
        const root = document.querySelector('[data-quiz-page]');
        const ranking = document.querySelector('[data-quiz-ranking-period]');
        const summary = document.querySelector('[data-quiz-correct][data-quiz-answered]');
        return {
            viewport: window.innerWidth, documentWidth: document.documentElement.scrollWidth,
            state: root?.dataset.quizState ?? (ranking ? 'ranking' : 'dashboard'),
            unlabelled: fields.filter(field => ![...field.labels ?? []].some(label => visible(label) && label.textContent.trim())).map(field => field.id),
            unnamed: controls.filter(control => !(control.textContent.trim() || control.getAttribute('aria-label'))).map(control => control.outerHTML),
            questionCount: document.querySelectorAll('[data-quiz-question-id]').length,
            legendCount: [...document.querySelectorAll('[data-quiz-question-id] legend')].filter(legend => legend.textContent.trim()).length,
            visibleFocus: !target || (document.activeElement === target && target.matches(':focus-visible') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0),
            focus: target ? { tag: target.tagName, id: target.id, outline: style.outline, active: document.activeElement === target, visible: target.matches(':focus-visible') } : null,
            errors: /SQLSTATE|PDOException|Fatal error|Warning:/.test(document.body.textContent),
            score: document.querySelector('[data-quiz-score]')?.dataset.quizScore,
            streak: document.querySelector('[data-quiz-streak]')?.dataset.quizStreak,
            correct: summary?.dataset.quizCorrect,
            answered: summary?.dataset.quizAnswered,
            period: ranking?.dataset.quizRankingPeriod,
            activeTab: document.querySelector('.quiz-ranking-tabs [aria-current="page"]')?.getAttribute('href'),
            ownRank: document.querySelector('[data-own-quiz-rank]')?.dataset.ownQuizRank,
            sourceLinks: document.querySelectorAll('.quiz-source, .quiz-review a[href^="http"]').length,
            leakedAnswerKey: /correctOption|data-correct-option|data-quiz-answer-key/.test(document.querySelector('main')?.innerHTML ?? ''),
        };
    })()`);
    assert.equal(metrics.state, state, route + ' state');
    assert.ok(metrics.documentWidth <= metrics.viewport + 1, 'No horizontal overflow: ' + JSON.stringify({ route, width, theme, metrics }));
    assert.deepEqual(metrics.unlabelled, [], 'All fields have visible labels');
    assert.deepEqual(metrics.unnamed, [], 'All controls have accessible names');
    assert.ok(metrics.visibleFocus, 'Keyboard focus is visible: ' + JSON.stringify({ route, width, theme, focus: metrics.focus }));
    assert.equal(metrics.errors, false, 'No runtime warnings in the page');
    assert.equal(metrics.sourceLinks, 0, 'Quiz views do not show source references');
    assert.equal(metrics.leakedAnswerKey, false, 'The browser does not receive the answer key');
    if (state === 'available') {
        assert.equal(metrics.questionCount, 5);
        assert.equal(metrics.legendCount, 5);
    }
    if (state === 'completed') {
        assert.equal(metrics.score, '5');
        assert.equal(metrics.correct, '5');
        assert.equal(metrics.answered, '5');
    }
    if (state === 'available') {
        assert.equal(metrics.correct, '0');
        assert.equal(metrics.answered, '0');
    }
    if (state === 'ranking') {
        const period = route.includes('period=monthly') ? 'monthly' : 'overall';
        assert.equal(metrics.period, period);
        assert.equal(metrics.activeTab, period === 'monthly' ? '/quiz/ranking?period=monthly' : '/quiz/ranking');
    }
    assert.deepEqual(cdp.errors, [], 'No JavaScript exceptions');
    return metrics;
}

test('daily quiz and rankings fit all viewports, preserve translated drafts and support completion without JavaScript', { timeout: 240000 }, async t => {
    const fixture = createQuizFixture();
    let browser;
    try {
        const client = await login(fixture.owner, fixture.password);
        browser = await launchBrowser();
        const { cdp } = browser;
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('guest / ' + theme + ' / ' + width + 'px', async () => {
                    await inspect(cdp, '/quiz', 'guest', width, theme);
                    await inspect(cdp, '/dashboard', 'dashboard', width, theme);
                });
            }
        }
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: client.session(), url: baseUrl, path: '/' });
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('form / ' + theme + ' / ' + width + 'px', async () => {
                    await inspect(cdp, '/quiz', 'available', width, theme);
                    await inspect(cdp, '/dashboard', 'dashboard', width, theme);
                });
            }
        }

        await t.test('changing the language preserves checked indices while translating questions and options', async () => {
            await inspect(cdp, '/quiz', 'available', 768, 'light');
            const readQuestions = `(() => [...document.querySelectorAll('[data-quiz-question-id]')].map(fieldset => ({
                id: Number(fieldset.dataset.quizQuestionId),
                question: fieldset.querySelector('legend > span:last-child').textContent.trim(),
                options: [...fieldset.querySelectorAll('.quiz-option > span:last-child')].map(option => option.textContent.trim()),
                values: [...fieldset.querySelectorAll('input[type="radio"]')].map(input => input.value),
                checked: fieldset.querySelector('input:checked')?.value ?? null,
            })))()`;
            const before = await cdp.evaluate(readQuestions);
            assert.equal(before.length, 5);
            const selectedId = before[0].id;
            await cdp.evaluate(`document.querySelector('input[name="answers[${selectedId}]"][value="2"]').click()`);
            const changeLanguage = async locale => {
                const previousOrigin = await cdp.evaluate('performance.timeOrigin');
                await cdp.evaluate(`(() => {
                    const form = document.querySelector('form[action="/language"]');
                    form.closest('details')?.setAttribute('open', '');
                    form.querySelector('[name="language"]').value = ${JSON.stringify(locale)};
                    form.requestSubmit();
                })()`);
                await waitFor(cdp, `performance.timeOrigin !== ${previousOrigin} && document.readyState === 'complete' && document.documentElement.lang === ${JSON.stringify(locale)} && Boolean(document.querySelector('[data-quiz-form]'))`);
            };
            await changeLanguage('en');
            const translated = await cdp.evaluate(readQuestions);
            assert.deepEqual(translated.map(question => question.id), before.map(question => question.id));
            assert.deepEqual(translated.map(question => question.values), before.map(question => question.values));
            assert.equal(translated.find(question => question.id === selectedId).checked, '2');
            assert.ok(translated.some((question, index) => question.question !== before[index].question), 'Question wording changes into English');
            for (const question of translated) {
                const expected = fixture.localized.en.questions.find(entry => entry.id === question.id);
                assert.equal(question.question, expected.question);
                assert.deepEqual(question.options, expected.options, 'Each translated option retains its original answer index');
            }
            await changeLanguage('pl');
            const restored = await cdp.evaluate(readQuestions);
            assert.deepEqual(restored.map(question => question.question), before.map(question => question.question));
            assert.deepEqual(restored.map(question => question.options), before.map(question => question.options));
            assert.equal(restored.find(question => question.id === selectedId).checked, '2');
        });

        await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
        await inspect(cdp, '/quiz', 'available', 320, 'dark');
        const formScreenshot = path.join(os.tmpdir(), 'anivibe-quiz-form-' + fixture.marker + '.png');
        const formImage = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        fs.writeFileSync(formScreenshot, Buffer.from(formImage.data, 'base64'));
        t.diagnostic('Mobile form screenshot: ' + formScreenshot);

        await t.test('all answers selected and submitted using only the keyboard, with JavaScript disabled', async () => {
            for (const question of fixture.quiz.questions) {
                const selector = 'input[name="answers[' + question.id + ']"][value="0"]';
                await cdp.evaluate(`(() => { const field = document.querySelector(${JSON.stringify(selector)}); field.scrollIntoView({ block: 'center' }); field.focus(); })()`);
                await key(cdp, ' ', 'Space', 32);
                for (let index = 0; index < question.correctOption; index++) await key(cdp, 'ArrowRight', 'ArrowRight', 39);
                const chosen = await cdp.evaluate(`document.querySelector(${JSON.stringify('input[name="answers[' + question.id + ']"]:checked')})?.value`);
                assert.equal(chosen, String(question.correctOption));
            }
            await cdp.evaluate(`(() => { const button = document.querySelector('[data-quiz-form] button[type="submit"]'); button.scrollIntoView({ block: 'center' }); button.focus(); })()`);
            await key(cdp, ' ', 'Space', 32);
            await waitFor(cdp, `document.readyState === 'complete' && document.querySelector('[data-quiz-state="completed"] [data-quiz-score]')?.dataset.quizScore === '5'`);
            assert.equal(await cdp.evaluate(`document.querySelector('[data-quiz-streak]').dataset.quizStreak`), '1');
        });

        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('result / ' + theme + ' / ' + width + 'px', async () => {
                    await inspect(cdp, '/quiz', 'completed', width, theme);
                    const dashboard = await inspect(cdp, '/dashboard', 'dashboard', width, theme);
                    assert.equal(dashboard.streak, '1', 'Dashboard displays the recorded streak');
                    assert.equal(dashboard.correct, '5');
                    assert.equal(dashboard.answered, '5');
                });
            }
        }

        for (const period of ['overall', 'monthly']) {
            const route = period === 'overall' ? '/quiz/ranking' : '/quiz/ranking?period=monthly';
            for (const theme of ['light', 'dark']) {
                for (const width of [320, 768, 1440]) {
                    await t.test('ranking / ' + period + ' / ' + theme + ' / ' + width + 'px', async () => {
                        const ranking = await inspect(cdp, route, 'ranking', width, theme);
                        assert.ok(Number(ranking.ownRank) >= 1, 'The participant has a position in the selected ranking');
                        const ownCorrect = await cdp.evaluate(`document.querySelector('[data-quiz-ranking-user="${fixture.owner.id}"]')?.dataset.quizRankingCorrect`);
                        // The own-rank card remains visible even when the account is outside the top 100.
                        if (ownCorrect !== undefined) assert.equal(ownCorrect, '5');
                        if (period === 'overall' && ((width === 320 && theme === 'dark') || (width === 1440 && theme === 'light'))) {
                            const screenshot = path.join(os.tmpdir(), 'anivibe-quiz-ranking-' + width + '-' + theme + '-' + fixture.marker + '.png');
                            const captured = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                            fs.writeFileSync(screenshot, Buffer.from(captured.data, 'base64'));
                            t.diagnostic('Ranking screenshot: ' + screenshot);
                        }
                    });
                }
            }
        }
        await inspect(cdp, '/quiz', 'completed', 1440, 'light');
        const resultScreenshot = path.join(os.tmpdir(), 'anivibe-quiz-result-' + fixture.marker + '.png');
        const resultImage = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        fs.writeFileSync(resultScreenshot, Buffer.from(resultImage.data, 'base64'));
        t.diagnostic('Desktop result screenshot: ' + resultScreenshot);
    } finally {
        try {
            if (browser) await browser.close();
        } finally {
            cleanupQuizFixture(fixture);
        }
    }
});
