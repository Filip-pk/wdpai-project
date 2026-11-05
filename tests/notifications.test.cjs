const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/js/notifications.js'), 'utf8');

function element(count) {
    return {
        textContent: String(count),
        hidden: count === 0,
        attributes: new Map(),
        setAttribute(name, value) { this.attributes.set(name, value); },
    };
}

function response(data, options = {}) {
    const status = options.status ?? 200;
    return {
        status,
        ok: status >= 200 && status < 300,
        redirected: options.redirected ?? false,
        async json() { return data; },
    };
}

function createPage(options = {}) {
    const badges = [element(options.count ?? 8), element(options.count ?? 8)];
    const links = [element(0)];
    const conversations = new Map([['17', [element(4)]], ['99', [element(4)]]]);
    const form = {
        action: options.action ?? 'http://anivibe.test/messages/17/read',
        hidden: false,
        fields: new Map([
            ['csrfToken', options.formCsrf === undefined ? 'form-csrf' : options.formCsrf],
            ['lastMessageId', '42'],
            ['page', '2'],
        ]),
    };
    const forms = options.form === false ? [] : [form];
    const calls = [];
    const documentListeners = new Map();
    const windowListeners = new Map();
    const timers = new Map();
    let timerId = 0;
    const document = {
        visibilityState: options.visibility ?? 'visible',
        querySelectorAll(selector) {
            if (selector === '[data-unread-message-count]') return badges;
            if (selector === '[data-notifications-link]') return links;
            if (selector === 'form[data-mark-conversation-read]') return forms;
            const match = selector.match(/^\[data-conversation-unread="(\d+)"\]$/);
            return match ? conversations.get(match[1]) ?? [] : [];
        },
        querySelector(selector) {
            return selector === 'meta[name="csrf-token"]' ? { content: 'meta-csrf' } : null;
        },
        addEventListener(name, listener) { documentListeners.set(name, listener); },
    };
    const window = {
        location: { href: 'http://anivibe.test/messages/17', origin: 'http://anivibe.test' },
        setTimeout(callback, delay) {
            timers.set(++timerId, { callback, delay });
            return timerId;
        },
        clearTimeout(id) { timers.delete(id); },
        addEventListener(name, listener) { windowListeners.set(name, listener); },
    };
    class FormData {
        constructor(node) { this.fields = node.fields; }
        [Symbol.iterator]() { return this.fields[Symbol.iterator](); }
    }
    const fetch = async (url, settings = {}) => {
        const call = {
            url,
            method: settings.method ?? 'GET',
            body: settings.body ? Object.fromEntries(settings.body) : null,
            settings,
        };
        calls.push(call);
        return options.fetch ? options.fetch(call, calls.length) : response({
            unreadCount: 4,
            ...(call.method === 'POST' ? { conversationId: 17, conversationUnreadCount: 0 } : {}),
        });
    };
    vm.runInNewContext(source, { document, window, fetch, FormData, URL, URLSearchParams });

    return {
        badges, links, conversations, form, calls, timers,
        async settle() { await new Promise(setImmediate); },
        async visibility(value) {
            document.visibilityState = value;
            documentListeners.get('visibilitychange')?.();
            await this.settle();
        },
        async poll() {
            const pending = [...timers.values()];
            timers.clear();
            assert.equal(pending.length, 1, 'one polling timer is scheduled');
            assert.equal(pending[0].delay, 15000);
            await pending[0].callback();
            await this.settle();
        },
    };
}

test('a hidden tab sends no requests and acknowledges only its rendered snapshot after becoming visible', async () => {
    const page = createPage({ visibility: 'hidden' });
    await page.settle();
    assert.equal(page.calls.length, 0);
    assert.equal(page.timers.size, 0);

    await page.visibility('visible');
    assert.deepEqual(page.calls.map(call => call.method), ['POST', 'GET']);
    assert.equal(page.calls[0].url, 'http://anivibe.test/messages/17/read');
    assert.deepEqual(page.calls[0].body, { csrfToken: 'form-csrf', lastMessageId: '42', page: '2' });
    assert.equal(page.calls[0].settings.credentials, 'same-origin');
    assert.equal(page.calls[0].settings.headers.Accept, 'application/json');
    assert.equal(page.calls[1].url, '/notifications');
    assert.equal(page.calls[1].settings.cache, 'no-store');

    await page.visibility('hidden');
    assert.equal(page.timers.size, 0);
    assert.equal(page.calls.length, 2);
});

test('a successful read updates only its conversation while preserving unread messages elsewhere', async () => {
    const page = createPage({ fetch: () => response({ unreadCount: 7, conversationId: 17, conversationUnreadCount: 0 }) });
    await page.settle();
    assert.equal(page.form.hidden, true);
    assert.deepEqual(page.badges.map(badge => badge.textContent), ['7', '7']);
    assert.equal(page.links[0].attributes.get('aria-label'), 'Wiadomości. Nieprzeczytane: 7');
    assert.equal(page.conversations.get('17')[0].hidden, true);
    assert.equal(page.conversations.get('99')[0].hidden, false);
    assert.equal(page.conversations.get('99')[0].textContent, '4');
});

test('later polling does not acknowledge the same snapshot again or consume newly arriving messages', async () => {
    let count = 4;
    const page = createPage({ fetch: call => response({ unreadCount: count,
        ...(call.method === 'POST' ? { conversationId: 17, conversationUnreadCount: 0 } : {}) }) });
    await page.settle();
    count = 5;
    await page.poll();
    assert.deepEqual(page.calls.map(call => call.method), ['POST', 'GET', 'GET']);
    assert.equal(page.badges[0].textContent, '5');
    await page.visibility('hidden');
    await page.visibility('visible');
    assert.equal(page.calls.filter(call => call.method === 'POST').length, 1);
});

test('authentication redirects and rejected sessions stop polling without hiding the fallback form', async t => {
    for (const options of [{ redirected: true }, { status: 401 }, { status: 403 }]) {
        await t.test(JSON.stringify(options), async () => {
            const page = createPage({ fetch: () => response({}, options) });
            await page.settle();
            assert.equal(page.calls.length, 1);
            assert.equal(page.form.hidden, false);
            assert.equal(page.timers.size, 0);
            await page.visibility('hidden');
            await page.visibility('visible');
            assert.equal(page.calls.length, 1);
        });
    }
});

test('a temporary read failure retains manual fallback and retries the same snapshot', async t => {
    for (const networkError of [false, true]) {
        await t.test(networkError ? 'network failure' : 'HTTP 503', async () => {
            let attempts = 0;
            const page = createPage({ formCsrf: '', fetch: call => {
                if (call.method === 'POST' && ++attempts === 1) {
                    if (networkError) throw new Error('offline');
                    return response({}, { status: 503 });
                }
                return response({ unreadCount: 4, conversationId: 17, conversationUnreadCount: 0 });
            } });
            await page.settle();
            assert.equal(page.form.hidden, false);
            assert.equal(page.calls[0].body.csrfToken, 'meta-csrf');
            await page.poll();
            const posts = page.calls.filter(call => call.method === 'POST');
            assert.equal(posts.length, 2);
            assert.deepEqual(posts[1].body, posts[0].body);
            assert.equal(page.form.hidden, true);
        });
    }
});

test('malformed counters preserve existing display and leave the read fallback available', async t => {
    for (const unreadCount of [undefined, null, false, '', '3', -1, 1.5, NaN, Infinity]) {
        await t.test(String(unreadCount), async () => {
            const page = createPage({ fetch: () => response({ unreadCount, conversationId: 17, conversationUnreadCount: 0 }) });
            await page.settle();
            assert.equal(page.badges[0].textContent, '8');
            assert.equal(page.badges[0].hidden, false);
            assert.equal(page.form.hidden, false);
            assert.equal(page.conversations.get('17')[0].textContent, '4');
        });
    }
});

test('malformed conversation counters do not clear a valid conversation badge', async () => {
    const page = createPage({ fetch: () => response({ unreadCount: 4, conversationId: 17, conversationUnreadCount: null }) });
    await page.settle();
    assert.equal(page.conversations.get('17')[0].textContent, '4');
    assert.equal(page.conversations.get('17')[0].hidden, false);
});

test('the read endpoint must belong to this origin and a conversation read route', async t => {
    for (const action of ['https://elsewhere.test/messages/17/read', 'http://anivibe.test/users/17/block']) {
        await t.test(action, async () => {
            const page = createPage({ action });
            await page.settle();
            assert.deepEqual(page.calls.map(call => call.method), ['GET']);
            assert.equal(page.form.hidden, false);
        });
    }
});
