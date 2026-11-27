const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { baseUrl, browser: httpBrowser, login } = require('./helpers/quiz-fixtures.cjs');
const { createAchievementFixture, seedAchievementProgress, cleanupAchievementFixture,
    ownedAchievementRecords, setAchievementPrivacy } = require('./helpers/achievement-fixtures.cjs');

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
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'anivibe-achievements-browser-'));
    const child = spawn(executable, ['--headless=new', '--ignore-certificate-errors', '--remote-debugging-port=0', '--no-first-run',
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
        // This exact profile was created by the test inside the OS temporary directory.
        assert.equal(path.dirname(profile), path.resolve(os.tmpdir()));
        assert.ok(path.basename(profile).startsWith('anivibe-achievements-browser-'));
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

async function inspect(cdp, route, width, theme, expected, hidden = false) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
    const previousOrigin = await cdp.evaluate('performance.timeOrigin');
    await cdp.send('Page.navigate', { url: new URL(route, baseUrl).href });
    const selector = route === '/achievements' ? '[data-achievements-page]' : (route === '/profile' ? '.profile-account' : '[data-profile-user-id]');
    await waitFor(cdp, `performance.timeOrigin !== ${previousOrigin} && document.readyState === 'complete' && Boolean(document.querySelector(${JSON.stringify(selector)}))`);
    await cdp.evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}`);
    await cdp.evaluate('document.fonts.ready');
    for (const type of ['keyDown', 'keyUp']) await cdp.send('Input.dispatchKeyEvent', { type, key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    const metrics = await cdp.evaluate(`(() => {
        const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
        const controls = [...document.querySelectorAll('main a[href], main button, main input:not([type="hidden"]), main select, main textarea')].filter(element => visible(element) && !element.matches(':disabled'));
        const target = controls[0];
        if (target) target.focus();
        const style = target ? getComputedStyle(target) : null;
        const progress = [...document.querySelectorAll('main progress')].filter(visible);
        const labelled = element => Boolean((element.getAttribute('aria-label') || '').trim()
            || (element.getAttribute('aria-labelledby') || '').split(/\s+/).some(id => document.getElementById(id)?.textContent.trim())
            || [...element.labels ?? []].some(label => visible(label) && label.textContent.trim()));
        const cards = [...document.querySelectorAll('[data-achievement-code]')];
        return {
            viewport: innerWidth, width: document.documentElement.scrollWidth,
            cards: cards.map(card => ({ code: card.dataset.achievementCode, unlocked: card.dataset.achievementUnlocked,
                progress: card.dataset.achievementProgress, target: card.dataset.achievementTarget })),
            section: Boolean(document.querySelector('[data-profile-achievements]')),
            unlabelledProgress: progress.filter(element => !labelled(element)).map(element => element.id),
            invalidProgress: progress.filter(element => element.value < 0 || element.max <= 0 || element.value > element.max).length,
            unnamedControls: controls.filter(element => !labelled(element) && !element.textContent.trim()).map(element => element.outerHTML),
            focusVisible: !target || (document.activeElement === target && target.matches(':focus-visible') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0),
            hasHeading: Boolean(document.querySelector('main h1')?.textContent.trim()),
            error: /SQLSTATE|PDOException|Fatal error|Warning:/.test(document.body.textContent),
            unsafeName: document.querySelectorAll('main svg[onload]').length,
        };
    })()`);
    assert.ok(metrics.width <= metrics.viewport + 1, 'No horizontal overflow: ' + JSON.stringify({ route, width, theme, metrics }));
    assert.deepEqual(metrics.unlabelledProgress, [], 'Progress indicators have accessible names');
    assert.equal(metrics.invalidProgress, 0);
    assert.deepEqual(metrics.unnamedControls, [], 'Controls have accessible names');
    assert.ok(metrics.focusVisible && metrics.hasHeading && !metrics.error, JSON.stringify({ route, width, theme, metrics }));
    assert.equal(metrics.unsafeName, 0);
    assert.deepEqual(cdp.errors, [], 'No JavaScript exceptions');
    if (route === '/achievements') {
        assert.equal(metrics.cards.length, 15);
        assert.equal(metrics.cards.filter(card => card.unlocked === '1').length, expected);
    } else {
        assert.equal(metrics.section, !hidden);
        assert.equal(metrics.cards.length, expected);
        assert.ok(metrics.cards.every(card => card.unlocked === '1'), 'Public profiles show earned badges only');
    }
    return metrics;
}

test('achievements and earned profile badges fit all viewports and respect private statistics without JavaScript', { timeout: 240000 }, async t => {
    const fixture = createAchievementFixture();
    let browser;
    try {
        const guest = await httpBrowser().request('/achievements');
        assert.equal(guest.status, 303);
        assert.equal(guest.headers.get('location'), '/login');
        const owner = await login(fixture.owner, fixture.password);
        const other = await login(fixture.other, fixture.password);
        browser = await launchBrowser();
        const { cdp } = browser;
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: owner.session(), url: baseUrl, path: '/' });
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('locked badges / ' + theme + ' / ' + width + 'px', async () => {
                    const state = await inspect(cdp, '/achievements', width, theme, 0);
                    assert.ok(state.cards.every(card => card.progress === '0'));
                });
            }
        }
        seedAchievementProgress(fixture);
        assert.deepEqual(ownedAchievementRecords(fixture), [], 'Seeding activity does not directly grant badges');
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: other.session(), url: baseUrl, path: '/' });
        await t.test('viewing another profile does not award achievements', async () => {
            await inspect(cdp, '/users/' + fixture.owner.id, 320, 'dark', 0);
            assert.deepEqual(ownedAchievementRecords(fixture), [], 'Public profile is a read-only view');
        });
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: owner.session(), url: baseUrl, path: '/' });
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('earned badges and partial progress / ' + theme + ' / ' + width + 'px', async () => {
                    const state = await inspect(cdp, '/achievements', width, theme, 7);
                    const byCode = new Map(state.cards.map(card => [card.code, card]));
                    assert.equal(byCode.get('quiz_correct_100').progress, '35');
                    assert.equal(byCode.get('quiz_streak_30').progress, '7');
                    assert.equal(byCode.get('anime_completed_50').progress, '10');
                });
            }
        }
        assert.equal(ownedAchievementRecords(fixture).length, 7);
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: other.session(), url: baseUrl, path: '/' });
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('public earned badges / ' + theme + ' / ' + width + 'px', async () => {
                    await inspect(cdp, '/users/' + fixture.owner.id, width, theme, 7);
                });
            }
        }
        setAchievementPrivacy(fixture, false);
        await t.test('private statistics remove the badge section and its data from the profile DOM', async () => {
            await inspect(cdp, '/users/' + fixture.owner.id, 320, 'dark', 0, true);
        });
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: owner.session(), url: baseUrl, path: '/' });
        await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
        await t.test('owner can read all badges and progress without JavaScript or public statistics', async () => {
            await inspect(cdp, '/achievements', 320, 'dark', 7);
            await inspect(cdp, '/users/' + fixture.owner.id, 320, 'dark', 7);
        });
        for (const theme of ['light', 'dark']) {
            for (const width of [320, 768, 1440]) {
                await t.test('private account profile badges / ' + theme + ' / ' + width + 'px', async () => {
                    await inspect(cdp, '/profile', width, theme, 7);
                });
            }
        }
        for (const [width, theme] of [[320, 'dark'], [1440, 'light']]) {
            await inspect(cdp, '/achievements', width, theme, 7);
            const screenshot = path.join(os.tmpdir(), 'anivibe-achievements-' + width + '-' + theme + '-' + fixture.marker + '.png');
            const captured = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
            fs.writeFileSync(screenshot, Buffer.from(captured.data, 'base64'));
            t.diagnostic('Achievements screenshot: ' + screenshot);
        }
    } finally {
        try {
            if (browser) await browser.close();
        } finally {
            const cleaned = cleanupAchievementFixture(fixture);
            assert.equal(cleaned.removedUsers, 2);
            assert.equal(cleaned.removedAnime, 10);
            assert.equal(cleaned.removedHistoricalQuizzes, 30);
        }
    }
});
