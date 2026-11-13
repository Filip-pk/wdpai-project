const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const { execFileSync, spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { once } = require('node:events');
const test = require('node:test');
const { createAnimeCommunityFixtures, cleanupAnimeCommunityFixtures } = require('./helpers/anime-community-fixtures.cjs');

const projectRoot = path.join(__dirname, '..');
const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Moderation-Browser-Fixture-2026!';
const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));

function php(source, input) {
    return JSON.parse(execFileSync('docker', ['compose', 'exec', '-T', 'php', 'php', '-r',
        "declare(strict_types=1); require 'vendor/autoload.php'; " + source, JSON.stringify(input)], {
        cwd: projectRoot, windowsHide: true, encoding: 'utf8', timeout: 30000,
    }));
}

function createAccounts(anime) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$marker = $input['marker'];
if (preg_match('/\A[a-f0-9-]{36}\z/', $marker) !== 1) throw new RuntimeException('Invalid fixture marker.');
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $users = new App\Repository\UserRepository($db);
    $result = [];
    foreach (['moderator', 'owner'] as $role) {
        $email = 'mod-browser-' . $role . '-' . $marker . '@example.test';
        $id = $users->create('Browser ' . $role . ' ' . $marker, $email, password_hash($input['password'], PASSWORD_DEFAULT));
        $stored = $users->findByEmail($email);
        if ($stored === null || !password_verify($input['password'], $stored['password_hash'])) {
            throw new RuntimeException('Fixture password could not be verified.');
        }
        $result[$role] = ['id' => $id, 'email' => $email];
    }
    $moderator = $result['moderator']['id'];
    $owner = $result['owner']['id'];
    $community = new App\Repository\CommunityRepository($db);
    $community->setModerator($moderator, true);
    $comments = new App\Repository\CommentRepository($db);
    $comment = $comments->createForUser($owner, $input['anime'], str_repeat('Długatreśćbezspacji', 80));
    $community->createReportForUser($moderator, 'comment', $comment, str_repeat('Powód zgłoszenia ', 12));
    $moderation = new App\Repository\ModerationRepository($db);
    $moderation->warnUser($moderator, $owner, str_repeat('Ostrzeżenie z uzasadnieniem. ', 12), bin2hex(random_bytes(16)), $comment);
    $moderation->banUser($moderator, $owner, str_repeat('Powód blokady ', 18), 3600, bin2hex(random_bytes(16)));
    $db->commit();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $db->rollBack();
    throw $exception;
}
`, { marker: randomUUID(), password, anime: anime.id });
}

function cleanupAccounts(accounts) {
    return php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$db = (new App\Database())->connect();
$db->beginTransaction();
try {
    $verify = $db->prepare('SELECT id FROM users WHERE id = :id AND email = :email FOR UPDATE');
    foreach ($input as $user) {
        if (!str_starts_with($user['email'], 'mod-browser-') || !str_ends_with($user['email'], '@example.test')) {
            throw new RuntimeException('Invalid fixture ownership.');
        }
        $verify->execute($user);
        if ($verify->fetchColumn() === false) throw new RuntimeException('Fixture ownership mismatch.');
    }
    $notices = $db->prepare('DELETE FROM moderation_notices WHERE user_id = :owner');
    $notices->execute(['owner' => $input['owner']['id']]);
    $actions = $db->prepare('DELETE FROM moderation_actions WHERE moderator_id = :moderator AND user_id = :owner');
    $actions->execute(['moderator' => $input['moderator']['id'], 'owner' => $input['owner']['id']]);
    $remove = $db->prepare('DELETE FROM users WHERE id = :id AND email = :email');
    foreach (array_reverse($input) as $user) $remove->execute($user);
    $db->commit();
    echo json_encode(['cleaned' => true], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $db->rollBack();
    throw $exception;
}
`, accounts);
}

async function login(user) {
    const page = await fetch(new URL('/login', baseUrl));
    let cookie = page.headers.getSetCookie().find(value => value.startsWith('PHPSESSID=')).split(';', 1)[0];
    const token = (await page.text()).match(/name="csrf-token" content="([a-f0-9]{64})"/)[1];
    const response = await fetch(new URL('/login', baseUrl), {
        method: 'POST', headers: { Cookie: cookie }, redirect: 'manual',
        body: new URLSearchParams({ email: user.email, password, csrfToken: token }),
    });
    assert.equal(response.status, 303);
    const regenerated = response.headers.getSetCookie().find(value => value.startsWith('PHPSESSID='));
    if (regenerated) cookie = regenerated.split(';', 1)[0];
    await response.arrayBuffer();
    return cookie.slice('PHPSESSID='.length);
}

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
    const candidates = [process.env.ANIVIBE_BROWSER,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'];
    const executable = candidates.find(candidate => candidate && fs.existsSync(candidate));
    assert.ok(executable, 'Set ANIVIBE_BROWSER to an installed Chrome or Edge executable');
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'anivibe-moderation-browser-'));
    const child = spawn(executable, ['--headless=new', '--remote-debugging-port=0', '--no-first-run',
        '--no-default-browser-check', '--disable-background-networking', '--disable-component-update',
        '--disable-default-apps', '--disable-sync', '--user-data-dir=' + profile, 'about:blank'], {
        windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'],
    });
    let output = '';
    const endpoint = await new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('Chrome startup timeout: ' + output.slice(-1000))), 15000);
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
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await once(socket, 'open');
    const cdp = new Cdp(socket);
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Network.enable');
    await cdp.send('Page.bringToFront');
    await cdp.send('Emulation.setFocusEmulationEnabled', { enabled: true });
    return {
        cdp,
        async close() {
            socket.close();
            const stopped = once(child, 'exit').catch(() => {});
            child.kill();
            await Promise.race([stopped, pause(3000)]);
            // Only remove the exact temporary profile created above, beneath the OS temp directory.
            assert.equal(path.dirname(profile), path.resolve(os.tmpdir()));
            assert.ok(path.basename(profile).startsWith('anivibe-moderation-browser-'));
            fs.rmSync(profile, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
        },
    };
}

async function inspect(cdp, route, width) {
    await cdp.send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 768 });
    await cdp.send('Page.navigate', { url: new URL(route, baseUrl).href });
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await cdp.evaluate('document.readyState === "complete" && Boolean(document.querySelector("main .moderation-page"))')) {
            ready = true;
            break;
        }
        await pause(100);
    }
    assert.ok(ready, 'Page rendered: ' + route);
    await cdp.evaluate('document.fonts.ready');
    await cdp.evaluate(`document.querySelector('main input:not([type="hidden"]), main textarea, main button, .account-restricted-navigation select')?.focus()`);
    await cdp.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await cdp.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    const metrics = await cdp.evaluate(`(() => {
        const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
        const fields = [...document.querySelectorAll('main input:not([type="hidden"]), main textarea, main select, .account-restricted-navigation select')].filter(visible);
        const unlabelled = fields.filter(element => ![...element.labels ?? []].some(label => visible(label) && label.textContent.trim()));
        const controls = [...document.querySelectorAll('main button, .account-restricted-navigation button')].filter(visible);
        const unnamed = controls.filter(element => !(element.textContent.trim() || element.getAttribute('aria-label')));
        const focusTarget = fields[0] || controls[0];
        if (focusTarget) focusTarget.focus();
        const style = focusTarget ? getComputedStyle(focusTarget) : null;
        return {
            viewport: window.innerWidth,
            documentWidth: document.documentElement.scrollWidth,
            unlabelled: unlabelled.map(element => element.id || element.name),
            unnamed: unnamed.map(element => element.outerHTML),
            heading: document.querySelector('main h1')?.textContent.trim(),
            focused: !focusTarget || document.activeElement === focusTarget,
            visibleFocus: !focusTarget || (focusTarget.matches(':focus-visible') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0),
            focusDiagnostics: focusTarget ? { tag: focusTarget.tagName, focusVisible: focusTarget.matches(':focus-visible'), outline: style.outline, documentFocus: document.hasFocus() } : null,
            errors: /SQLSTATE|PDOException|Fatal error|Warning:/.test(document.body.textContent),
        };
    })()`);
    assert.ok(metrics.documentWidth <= metrics.viewport + 1, route + ' at ' + width + ': horizontal overflow ' + JSON.stringify(metrics));
    assert.deepEqual(metrics.unlabelled, [], route + ' has visible field labels');
    assert.deepEqual(metrics.unnamed, [], route + ' has named buttons');
    assert.ok(metrics.heading && metrics.focused && metrics.visibleFocus && !metrics.errors, JSON.stringify(metrics));
    assert.deepEqual(cdp.errors, [], 'No JavaScript exceptions');
    return metrics;
}

test('moderation views fit phone, tablet and desktop with labelled controls and visible focus in a real browser', { timeout: 180000 }, async t => {
    const fixture = createAnimeCommunityFixtures();
    let accounts;
    let browser;
    try {
        accounts = createAccounts(fixture.animes[0]);
        const moderatorSession = await login(accounts.moderator);
        const ownerSession = await login(accounts.owner);
        browser = await launchBrowser();
        for (const [session, routes] of [
            [moderatorSession, ['reports', 'comments', 'users', 'history'].map(tab => '/moderation?tab=' + tab + '&author=' + accounts.owner.id)],
            [ownerSession, ['/account/banned', '/account/notices']],
        ]) {
            await browser.cdp.send('Network.setCookie', { name: 'PHPSESSID', value: session, url: baseUrl, path: '/' });
            for (const route of routes) {
                for (const width of [360, 768, 1440]) {
                    await t.test(route + ' / ' + width + 'px', async () => { await inspect(browser.cdp, route, width); });
                }
            }
        }
    } finally {
        if (browser) await browser.close();
        if (accounts) cleanupAccounts(accounts);
        cleanupAnimeCommunityFixtures(fixture);
    }
});
