const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const { once } = require('node:events');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { baseUrl, csrf, login } = require('./helpers/quiz-fixtures.cjs');
const { createAdminFixture, cleanupAdminFixture, fixtureState } = require('./helpers/admin-fixtures.cjs');

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
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'anivibe-admin-browser-'));
    const child = spawn(executable, ['--headless=new', '--remote-debugging-port=0', '--no-first-run',
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
        assert.ok(path.basename(profile).startsWith('anivibe-admin-browser-'));
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
    await waitFor(cdp, `performance.timeOrigin !== ${before} && document.readyState === 'complete' && Boolean(document.querySelector('[data-admin-panel]'))`);
    await cdp.evaluate('document.fonts.ready');
}

test('administrator views fit phone, tablet and desktop, support keyboard focus and work without JavaScript', { timeout: 240000 }, async t => {
    const fixture = createAdminFixture();
    let browser;
    try {
        const admin = await login(fixture.admin, fixture.password);
        const initial = await admin.request('/admin?tab=diagnostics');
        assert.equal(initial.status, 200);
        assert.equal((await admin.request('/admin/diagnostics', { csrfToken: csrf(initial) })).status, 303);
        browser = await launchBrowser();
        const { cdp } = browser;
        await cdp.send('Network.setCookie', { name: 'PHPSESSID', value: admin.session(), url: baseUrl, path: '/' });

        const routes = [
            '/admin?tab=overview',
            '/admin?tab=diagnostics',
            '/admin?tab=errors',
            '/admin?tab=users&query=' + encodeURIComponent(fixture.target.email),
            '/admin?tab=catalog&query=' + fixture.anime.id,
            '/admin?tab=history',
        ];
        for (const route of routes) {
            for (const theme of ['light', 'dark']) {
                for (const width of [320, 375, 768, 1440]) {
                    await t.test(route + ' / ' + theme + ' / ' + width + 'px', async () => {
                        await cdp.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
                        await navigate(cdp, route);
                        await cdp.evaluate(`document.documentElement.dataset.theme = ${JSON.stringify(theme)}`);
                        for (const type of ['keyDown', 'keyUp']) await cdp.send('Input.dispatchKeyEvent', { type, key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
                        const metrics = await cdp.evaluate(`(() => {
                            const root = document.querySelector('[data-admin-panel]');
                            const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
                            const fields = [...root.querySelectorAll('input:not([type="hidden"]), textarea, select')].filter(visible);
                            const controls = [...root.querySelectorAll('a[href], button, select, textarea, input:not([type="hidden"])')].filter(element => visible(element) && !element.disabled);
                            const unnamed = controls.filter(element => !(element.getAttribute('aria-label') || '').trim()
                                && ![...element.labels ?? []].some(label => label.textContent.trim()) && !element.textContent.trim());
                            const unlabelled = fields.filter(element => ![...element.labels ?? []].some(label => label.textContent.trim()));
                            const first = controls[0];
                            if (first) first.focus();
                            const style = first ? getComputedStyle(first) : null;
                            return {
                                width: document.documentElement.scrollWidth, viewport: innerWidth,
                                unnamed: unnamed.map(element => element.outerHTML),
                                unlabelled: unlabelled.map(element => element.outerHTML),
                                focus: !first || (document.activeElement === first && first.matches(':focus-visible') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0),
                                heading: Boolean(root.querySelector('h1')),
                                activeTabs: root.querySelectorAll('.admin-tabs [aria-current="page"]').length,
                                services: root.querySelectorAll('[data-service]').length,
                                unsafeUserMarkup: Boolean(root.querySelector('svg[onload], img[onerror]')),
                                translationKey: /admin\\.[a-z_]+(?:\\.[a-z_]+)*/.test(root.textContent),
                                error: /SQLSTATE|PDOException|Fatal error|Warning:/.test(document.body.textContent),
                            };
                        })()`);
                        assert.ok(metrics.width <= metrics.viewport + 1, JSON.stringify({ route, theme, width, metrics }));
                        assert.deepEqual(metrics.unnamed, [], 'Visible controls have accessible names');
                        assert.deepEqual(metrics.unlabelled, [], 'Visible fields have labels');
                        assert.ok(metrics.focus && metrics.heading && !metrics.error && !metrics.unsafeUserMarkup && !metrics.translationKey, JSON.stringify(metrics));
                        assert.equal(metrics.activeTabs, 1);
                        if (route.includes('tab=diagnostics')) assert.equal(metrics.services, 4);
                        assert.deepEqual(cdp.errors, []);
                        if (route.includes('tab=diagnostics') && theme === 'dark' && [375, 1440].includes(width)) {
                            const clip = await cdp.evaluate(`(() => {
                                const rect = document.querySelector('[data-admin-panel]').getBoundingClientRect();
                                return { x: rect.left + scrollX, y: rect.top + scrollY, width: rect.width, height: rect.height, scale: 1 };
                            })()`);
                            const screenshot = path.join(os.tmpdir(), 'anivibe-admin-' + width + '-dark-' + fixture.marker + '.png');
                            const captured = await cdp.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip });
                            fs.writeFileSync(screenshot, Buffer.from(captured.data, 'base64'));
                            t.diagnostic('Administrator screenshot: ' + screenshot);
                        }
                    });
                }
            }
        }

        await t.test('GET filters and role changes submit with JavaScript disabled', async () => {
            await cdp.send('Emulation.setScriptExecutionDisabled', { value: true });
            await navigate(cdp, '/admin?tab=users');
            let before = await cdp.evaluate('performance.timeOrigin');
            await cdp.evaluate(`(() => {
                document.querySelector('#admin-query').value = ${JSON.stringify(fixture.target.email)};
                document.querySelector('.admin-filters').submit();
            })()`);
            await waitFor(cdp, `performance.timeOrigin !== ${before} && document.readyState === 'complete' && location.search.includes('query=')`);
            assert.deepEqual(await cdp.evaluate('[...document.querySelectorAll("[data-user-id]")].map(element => Number(element.dataset.userId))'), [fixture.target.id]);
            before = await cdp.evaluate('performance.timeOrigin');
            await cdp.evaluate(`(() => {
                const form = document.querySelector('[data-user-id="${fixture.target.id}"] form');
                form.querySelector('textarea[name="reason"]').value = 'Administrator responsive browser fixture';
                form.submit();
            })()`);
            await waitFor(cdp, `performance.timeOrigin !== ${before} && document.readyState === 'complete' && Boolean(document.querySelector('[data-admin-panel="users"]'))`);
            assert.equal(fixtureState(fixture).isModerator, true);
            assert.deepEqual(cdp.errors, []);
        });
    } finally {
        try {
            if (browser) await browser.close();
        } finally {
            cleanupAdminFixture(fixture);
        }
    }
});
