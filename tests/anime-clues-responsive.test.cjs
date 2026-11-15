const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { baseUrl, login, csrf } = require('./helpers/quiz-fixtures.cjs');
const { createAnimeClueFixture, cleanupAnimeClueFixture, guessFields } = require('./helpers/anime-clue-fixtures.cjs');

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
        const result = await this.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert.equal(result.exceptionDetails, undefined, expression.slice(0, 100));
        return result.result.value;
    }
}

async function launchBrowser() {
    const executable = [process.env.ANIVIBE_BROWSER,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ].find(candidate => candidate && fs.existsSync(candidate));
    assert.ok(executable, 'Set ANIVIBE_BROWSER to an installed Chrome or Edge executable');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'anivibe-clue-browser-'));
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
        // This exact profile was created by this test under the OS temporary directory.
        assert.equal(path.dirname(profile), path.resolve(os.tmpdir()));
        assert.ok(path.basename(profile).startsWith('anivibe-clue-browser-'));
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

async function inspect(cdp, width, theme, expected) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
    const previousOrigin = await cdp.evaluate('performance.timeOrigin');
    const route = expected.ranking ? '/guess/ranking' : '/guess';
    const selector = expected.ranking ? '[data-guess-ranking]' : '[data-guess-page]';
    await cdp.send('Page.navigate', { url: new URL(route, baseUrl).href });
    await waitFor(cdp, `performance.timeOrigin !== ${previousOrigin} && document.readyState === 'complete' && Boolean(document.querySelector(${JSON.stringify(selector)}))`);
    await cdp.evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}`);
    await cdp.evaluate('document.fonts.ready');
    await key(cdp, 'Tab', 'Tab', 9);
    const metrics = await cdp.evaluate(`(() => {
        const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
        const fields = [...document.querySelectorAll('main input:not([type="hidden"]), main select, main textarea')].filter(element => visible(element) && !element.matches(':disabled'));
        const controls = [...document.querySelectorAll('main a[href], main button')].filter(element => visible(element) && !element.matches(':disabled'));
        const target = fields[0] || controls[0];
        if (target) target.focus();
        const style = target ? getComputedStyle(target) : null;
        const progress = [...document.querySelectorAll('main progress')].filter(visible);
        const score = document.querySelector('[data-guess-score]');
        const round = document.querySelector('[data-guess-round]');
        return {
            width: document.documentElement.scrollWidth, viewport: innerWidth,
            round: round?.dataset.guessRound, hints: round?.dataset.guessHints,
            clueCount: document.querySelectorAll('[data-guess-clue]').length,
            score: score?.dataset.guessScore, completed: score?.dataset.guessCompleted,
            ownRankingPoints: document.querySelector('[data-own-guess-rank]')?.dataset.ownGuessPoints,
            ownRankingRank: document.querySelector('[data-own-guess-rank]')?.dataset.ownGuessRank,
            currentRankingLink: Boolean(document.querySelector('.guess-ranking-nav a[aria-current="page"][href="/guess/ranking"]')),
            unlabelled: fields.filter(field => ![...field.labels ?? []].some(label => visible(label) && label.textContent.trim())).map(field => field.id),
            unnamed: controls.filter(control => !(control.textContent.trim() || control.getAttribute('aria-label'))).map(control => control.outerHTML),
            unnamedProgress: progress.filter(meter => ![...meter.labels ?? []].some(label => visible(label) && label.textContent.trim())).map(meter => meter.id),
            visibleFocus: !target || (document.activeElement === target && target.matches(':focus-visible') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0),
            errors: /SQLSTATE|PDOException|Fatal error|Warning:/.test(document.body.textContent),
            unsafeName: document.querySelectorAll('main svg[onload]').length,
            leakedKeys: /sourceUrl|"aliases"|data-anime-id=|"entries":/.test(document.querySelector('main').innerHTML),
        };
    })()`);
    assert.ok(metrics.width <= metrics.viewport + 1, 'No overflow: ' + JSON.stringify({ width, theme, metrics }));
    assert.deepEqual(metrics.unlabelled, []);
    assert.deepEqual(metrics.unnamed, []);
    assert.deepEqual(metrics.unnamedProgress, []);
    assert.ok(metrics.visibleFocus && !metrics.errors, JSON.stringify({ width, theme, metrics }));
    assert.equal(metrics.unsafeName, 0);
    assert.equal(metrics.leakedKeys, false);
    assert.deepEqual(cdp.errors, []);
    if (expected.ranking) {
        assert.equal(metrics.ownRankingPoints, String(expected.points));
        assert.match(metrics.ownRankingRank, /^\d+$/);
        assert.equal(metrics.currentRankingLink, true, 'The weekly leaderboard has a current navigation link');
    } else if (expected.guest) {
        assert.equal(metrics.clueCount, 0);
        assert.equal(metrics.round, undefined);
    } else {
        assert.equal(metrics.score, String(expected.score ?? 0));
        assert.equal(metrics.completed, expected.completed ? '1' : '0');
        if (expected.completed) assert.equal(metrics.round, undefined);
        else {
            assert.equal(metrics.round, String(expected.round ?? 0));
            assert.equal(metrics.hints, String(expected.hints));
            assert.equal(metrics.clueCount, expected.hints);
        }
    }
    return metrics;
}

test('anime clue rounds and weekly ranking fit all viewports and work with the keyboard without JavaScript', { timeout: 240000 }, async t => {
    const fixture = createAnimeClueFixture();
    let browser;
    try {
        const owner = await login(fixture.owner, fixture.password);
        const token = csrf(await owner.request('/guess'));
        browser = await launchBrowser();
        const { cdp } = browser;
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('guest / ' + theme + ' / ' + width, async () => inspect(cdp, width, theme, { guest: true }));
            }
        }
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: owner.session(), url: baseUrl, path: '/' });
        for (const hints of [1, 2, 3]) {
            if (hints > 1) {
                const response = await owner.request('/guess/reveal', guessFields(fixture, token, 0, { expectedHints: hints - 1 }));
                assert.equal(response.status, 303);
            }
            for (const theme of ['light', 'dark']) {
                for (const width of [320, 768, 1440]) {
                    await t.test('hint ' + hints + ' / ' + theme + ' / ' + width, async () => inspect(cdp, width, theme, { hints }));
                }
            }
        }

        await t.test('changing the language preserves all three server-recorded hints', async () => {
            await inspect(cdp, 768, 'light', { hints: 3 });
            for (const locale of ['en', 'pl']) {
                const previousOrigin = await cdp.evaluate('performance.timeOrigin');
                await cdp.evaluate(`(() => {
                    const form = document.querySelector('form[action="/language"]');
                    form.closest('details')?.setAttribute('open', '');
                    form.querySelector('[name="language"]').value = ${JSON.stringify(locale)};
                    form.requestSubmit();
                })()`);
                await waitFor(cdp, `performance.timeOrigin !== ${previousOrigin} && document.readyState === 'complete' && document.documentElement.lang === ${JSON.stringify(locale)} && document.querySelector('[data-guess-round]')?.dataset.guessHints === '3'`);
                const clues = await cdp.evaluate(`JSON.stringify([...document.querySelectorAll('[data-guess-clue] p')].map(clue => clue.textContent.trim()))`);
                assert.deepEqual(JSON.parse(clues), fixture.game.entries[0].clues[locale]);
            }
        });

        await inspect(cdp, 320, 'dark', { hints: 3 });
        const hintScreenshot = path.join(os.tmpdir(), 'anivibe-clue-hints-320-dark-' + fixture.marker + '.png');
        const hintImage = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        fs.writeFileSync(hintScreenshot, Buffer.from(hintImage.data, 'base64'));
        t.diagnostic('Hints screenshot: ' + hintScreenshot);

        await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
        await t.test('all five answers can be entered and submitted with the keyboard without JavaScript', async () => {
            for (let round = 0; round < 5; round++) {
                await cdp.evaluate(`(() => { const field = document.getElementById('guess-answer'); field.scrollIntoView({ block: 'center' }); field.focus(); })()`);
                await cdp.send('Input.insertText', { text: fixture.game.entries[round].title });
                await cdp.evaluate(`(() => { const button = document.querySelector('[data-guess-answer-form] button[type="submit"]'); button.scrollIntoView({ block: 'center' }); button.focus(); })()`);
                await key(cdp, ' ', 'Space', 32);
                const expected = round === 4 ? `document.querySelector('[data-guess-completed="1"]')` : `document.querySelector('[data-guess-round="${round + 1}"]')`;
                await waitFor(cdp, `document.readyState === 'complete' && Boolean(${expected})`);
            }
            assert.equal(await cdp.evaluate(`document.querySelector('[data-guess-score]').dataset.guessScore`), '13');
        });
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('completed / ' + theme + ' / ' + width, async () => inspect(cdp, width, theme, { completed: true, score: 13 }));
            }
        }
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('weekly ranking / ' + theme + ' / ' + width, async () => {
                    await inspect(cdp, width, theme, { ranking: true, points: 13 });
                    if ((theme === 'dark' && width === 320) || (theme === 'light' && width === 1440)) {
                        const screenshot = path.join(os.tmpdir(), 'anivibe-clue-ranking-' + width + '-' + theme + '-' + fixture.marker + '.png');
                        const captured = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
                        fs.writeFileSync(screenshot, Buffer.from(captured.data, 'base64'));
                        t.diagnostic('Weekly ranking screenshot: ' + screenshot);
                    }
                });
            }
        }
        await inspect(cdp, 1440, 'light', { completed: true, score: 13 });
        const resultScreenshot = path.join(os.tmpdir(), 'anivibe-clue-result-1440-light-' + fixture.marker + '.png');
        const resultImage = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
        fs.writeFileSync(resultScreenshot, Buffer.from(resultImage.data, 'base64'));
        t.diagnostic('Result screenshot: ' + resultScreenshot);
    } finally {
        try {
            if (browser) await browser.close();
        } finally {
            const cleaned = cleanupAnimeClueFixture(fixture);
            assert.equal(cleaned.removedUsers, 2);
            assert.equal(cleaned.removedDays, fixture.history.length);
        }
    }
});
