const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { baseUrl, csrf, login } = require('./helpers/quiz-fixtures.cjs');
const { createAchievementFixture, seedAchievementProgress, cleanupAchievementFixture } = require('./helpers/achievement-fixtures.cjs');

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
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'anivibe-showcase-browser-'));
    const child = spawn(executable, ['--headless=new', '--ignore-certificate-errors', '--remote-debugging-port=0', '--no-first-run',
        '--no-default-browser-check', '--disable-background-networking', '--disable-component-update',
        '--disable-default-apps', '--disable-sync', '--user-data-dir=' + profile, 'about:blank'], {
        windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
    });
    let socket;
    const close = async () => {
        if (socket) socket.close();
        if (child.exitCode === null) {
            const stopped = once(child, 'exit').catch(() => {});
            child.kill();
            await Promise.race([stopped, pause(3000)]);
        }
        assert.equal(path.dirname(profile), path.resolve(os.tmpdir()));
        assert.ok(path.basename(profile).startsWith('anivibe-showcase-browser-'));
        fs.rmSync(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
    };
    try {
        let output = '';
        const endpoint = await new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error('Browser startup timeout: ' + output.slice(-500))), 15000);
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

async function navigate(cdp, route) {
    const before = await cdp.evaluate('performance.timeOrigin');
    await cdp.send('Page.navigate', { url: new URL(route, baseUrl).href });
    await waitFor(cdp, `performance.timeOrigin !== ${before} && document.readyState === 'complete' && Boolean(document.querySelector('[data-profile-showcase], [data-showcase-editor]'))`);
    await cdp.evaluate('document.fonts.ready');
}

test('profile showcase stays usable across viewports, motion preferences and JavaScript availability', { timeout: 240000 }, async t => {
    const fixture = createAchievementFixture();
    let browser;
    try {
        seedAchievementProgress(fixture);
        const owner = await login(fixture.owner, fixture.password);
        await owner.request('/achievements');
        const token = csrf(await owner.request('/profile/showcase'));
        const wanted = fixture.animes.slice(0, 3).map(anime => anime.id);
        const form = new FormData();
        form.append('csrfToken', token);
        for (const id of wanted) form.append('animeIds[]', id);
        for (const code of ['quiz_perfect', 'anime_first', 'quiz_first']) form.append('badgeCodes[]', code);
        assert.equal((await owner.request('/profile/showcase', form)).status, 303);
        browser = await launchBrowser();
        const { cdp } = browser;
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: owner.session(), url: baseUrl, path: '/' });

        for (const route of ['/profile', '/users/' + fixture.owner.id, '/profile/showcase']) {
            for (const theme of ['light', 'dark']) {
                for (const width of [320, 375, 768, 1440]) {
                    await t.test(route + ' / ' + theme + ' / ' + width + 'px', async () => {
                        await cdp.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                        await navigate(cdp, route);
                        await cdp.evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}`);
                        for (const type of ['keyDown', 'keyUp']) await cdp.send('Input.dispatchKeyEvent', { type, key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
                        const metrics = await cdp.evaluate(`(() => {
                            const root = document.querySelector('[data-showcase-editor]') || document.querySelector('[data-profile-showcase]');
                            const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
                            const controls = [...root.querySelectorAll('a[href], button, select, input:not([type="hidden"])')].filter(element => visible(element) && !element.disabled);
                            const unnamed = controls.filter(element => !(element.getAttribute('aria-label') || '').trim()
                                && ![...element.labels ?? []].some(label => label.textContent.trim()) && !element.textContent.trim());
                            const first = controls[0];
                            if (first) first.focus();
                            const style = first ? getComputedStyle(first) : null;
                            return {
                                width: document.documentElement.scrollWidth, viewport: innerWidth,
                                unnamed: unnamed.map(element => element.outerHTML),
                                focus: !first || (document.activeElement === first && first.matches(':focus-visible') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0),
                                anime: [...document.querySelectorAll('[data-showcase-anime-id]')].map(element => Number(element.dataset.showcaseAnimeId)),
                                badgeCount: document.querySelectorAll('[data-showcase-badge-code]').length,
                                error: /SQLSTATE|PDOException|Fatal error|Warning:/.test(document.body.textContent),
                            };
                        })()`);
                        assert.ok(metrics.width <= metrics.viewport + 1, JSON.stringify({ route, theme, width, metrics }));
                        assert.deepEqual(metrics.unnamed, []);
                        assert.ok(metrics.focus && !metrics.error, JSON.stringify(metrics));
                        assert.deepEqual(metrics.anime, wanted);
                        assert.equal(metrics.badgeCount, 3);
                        assert.deepEqual(cdp.errors, []);
                        if (route.startsWith('/users/') && theme === 'dark' && [375, 1440].includes(width)) {
                            const clip = await cdp.evaluate(`(() => {
                                const rect = document.querySelector('[data-profile-showcase]').getBoundingClientRect();
                                return { x: rect.left + scrollX, y: rect.top + scrollY, width: rect.width, height: rect.height, scale: 1 };
                            })()`);
                            const screenshot = path.join(os.tmpdir(), 'anivibe-showcase-' + width + '-dark-' + fixture.marker + '.png');
                            const captured = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip });
                            fs.writeFileSync(screenshot, Buffer.from(captured.data, 'base64'));
                            t.diagnostic('Showcase screenshot: ' + screenshot);
                        }
                    });
                }
            }
        }

        await t.test('neon rotates normally and stops for system and account reduced motion', async () => {
            await navigate(cdp, '/profile');
            await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
            await cdp.evaluate('document.documentElement.dataset.reducedMotion = "0"');
            const animation = () => cdp.evaluate(`(() => {
                const style = getComputedStyle(document.querySelector('[data-profile-showcase] .showcase-neon'), '::before');
                return { name: style.animationName, duration: parseFloat(style.animationDuration) };
            })()`);
            const moving = await animation();
            assert.equal(moving.name, 'showcase-orbit');
            assert.ok(moving.duration > 0.1);
            await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
            assert.equal((await animation()).name, 'none');
            await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });
            await cdp.evaluate('document.documentElement.dataset.reducedMotion = "1"');
            assert.equal((await animation()).name, 'none');
        });

        await t.test('live catalog search preserves an unsaved selection excluded from the new results', async () => {
            await navigate(cdp, '/profile/showcase');
            const search = query => cdp.evaluate(`(() => {
                document.querySelector('#showcase-search').value = ${JSON.stringify(query)};
                document.querySelector('[data-showcase-search]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            })()`);
            await search(fixture.marker);
            await waitFor(cdp, `Boolean(document.querySelector('#showcase-slot-1 option[value="${fixture.animes[9].id}"]'))`);
            const unsaved = fixture.animes[5].id;
            await cdp.evaluate(`document.querySelector('#showcase-slot-1').value = '${unsaved}'`);
            await search(fixture.marker + ' - 9');
            await waitFor(cdp, `!document.querySelector('#showcase-slot-1 option[value="${fixture.animes[8].id}"]')`);
            assert.equal(await cdp.evaluate('document.querySelector("#showcase-slot-1").value'), String(unsaved));
            assert.deepEqual(cdp.errors, []);
        });

        await t.test('the complete editor form saves with JavaScript disabled', async () => {
            await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
            await navigate(cdp, '/profile/showcase');
            const before = await cdp.evaluate('performance.timeOrigin');
            await cdp.evaluate(`(() => {
                document.querySelector('#showcase-slot-2').value = '';
                document.querySelector('#showcase-slot-3').value = '';
                for (const input of document.querySelectorAll('input[name="badgeCodes[]"]')) input.checked = input.value === 'quiz_first';
                document.querySelector('[data-showcase-form]').submit();
            })()`);
            await waitFor(cdp, `performance.timeOrigin !== ${before} && document.readyState === 'complete' && location.pathname === '/profile'`);
            assert.deepEqual(await cdp.evaluate(`[...document.querySelectorAll('[data-showcase-anime-id]')].map(element => Number(element.dataset.showcaseAnimeId))`), [wanted[0]]);
            assert.deepEqual(await cdp.evaluate(`[...document.querySelectorAll('[data-showcase-badge-code]')].map(element => element.dataset.showcaseBadgeCode)`), ['quiz_first']);
        });
    } finally {
        try {
            if (browser) await browser.close();
        } finally {
            const removed = cleanupAchievementFixture(fixture);
            assert.equal(removed.removedUsers, 2);
            assert.equal(removed.removedAnime, 10);
            assert.equal(removed.removedHistoricalQuizzes, 30);
        }
    }
});
