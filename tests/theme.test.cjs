const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
    path.join(__dirname, '../public/js/theme.js'),
    'utf8',
);
const header = fs.readFileSync(
    path.join(__dirname, '../public/views/partials/header.html.php'),
    'utf8',
);
const bootstrap = header.match(/<script>\s*([\s\S]*?)\s*<\/script>/);

function createButton() {
    const listeners = new Map();

    return {
        attributes: new Map(),
        disabled: true,
        title: '',
        setAttribute(name, value) {
            this.attributes.set(name, value);
        },
        addEventListener(name, listener) {
            listeners.set(name, listener);
        },
        click() {
            if (!this.disabled) {
                return listeners.get('click')?.();
            }
        },
    };
}

function createPage(options = {}) {
    const storage = new Map();
    const windowListeners = new Map();
    const queryListeners = new Map();
    const toggle = options.buttons === false ? null : createButton();
    const system = options.buttons === false ? null : createButton();
    const root = { dataset: { theme: options.initialTheme ?? 'light' } };
    const saveStatus = { hidden: true, textContent: '' };
    const csrfMeta = { content: 'test-csrf-token' };
    const profileTheme = options.profileTheme === undefined ? null : { value: options.profileTheme };

    if (options.accountTheme !== undefined) {
        root.dataset.accountId = '123';
        root.dataset.accountTheme = options.accountTheme;
    }

    if (options.saved !== undefined) {
        storage.set('theme', options.saved);
    }

    const localStorage = {
        getItem(key) {
            if (options.readFails) {
                throw new Error('Storage blocked');
            }

            return storage.get(key) ?? null;
        },
        setItem(key, value) {
            if (options.writeFails) {
                throw new Error('Storage full');
            }

            storage.set(key, value);
        },
        removeItem(key) {
            if (options.removeFails) {
                throw new Error('Storage blocked');
            }

            storage.delete(key);
        },
    };
    const query = {
        matches: options.dark ?? false,
        addEventListener(name, listener) {
            queryListeners.set(name, listener);
        },
    };
    const window = {
        fetch: options.fetch,
        get localStorage() {
            if (options.storageFails) {
                throw new Error('Storage unavailable');
            }

            return localStorage;
        },
        addEventListener(name, listener) {
            windowListeners.set(name, listener);
        },
    };

    if (options.matchMedia !== false) {
        window.matchMedia = (media) => {
            if (options.matchMediaFails) {
                throw new Error('Media query unavailable');
            }

            assert.equal(media, '(prefers-color-scheme: dark)');
            return query;
        };
    }

    const context = vm.createContext({
        window,
        URLSearchParams,
        get localStorage() {
            return window.localStorage;
        },
        document: {
            documentElement: root,
            querySelector(selector) {
                return {
                    '.theme-toggle': toggle,
                    '.theme-system': system,
                    '.theme-save-status': saveStatus,
                    'meta[name="csrf-token"]': csrfMeta,
                    '#profile-theme': profileTheme,
                }[selector] ?? null;
            },
        },
    });

    if (options.bootstrap) {
        assert.ok(bootstrap, 'The header must contain the initial theme script');
        vm.runInContext(bootstrap[1], context);
    }

    const bootstrapTheme = root.dataset.theme;

    vm.runInContext(source, context);

    return {
        root,
        toggle,
        system,
        storage,
        localStorage,
        bootstrapTheme,
        saveStatus,
        profileTheme,
        changeSystem(dark) {
            query.matches = dark;
            queryListeners.get('change')?.({ matches: dark });
        },
        storageEvent(key, newValue, storageArea = localStorage) {
            windowListeners.get('storage')?.({ key, newValue, storageArea });
        },
    };
}

test('system preference is the default and follows subsequent changes', () => {
    const page = createPage({ dark: true });

    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.toggle.attributes.get('aria-pressed'), 'true');
    assert.equal(page.toggle.attributes.get('aria-label'), 'Włącz jasny motyw');
    assert.equal(page.toggle.title, 'Włącz jasny motyw');
    assert.equal(page.system.disabled, true);
    assert.equal(page.storage.has('theme'), false);

    page.changeSystem(false);

    assert.equal(page.root.dataset.theme, 'light');
    assert.equal(page.toggle.attributes.get('aria-pressed'), 'false');
    assert.equal(page.toggle.attributes.get('aria-label'), 'Włącz ciemny motyw');
    assert.equal(page.storage.has('theme'), false);
});

test('only valid saved preferences override the system', () => {
    for (const saved of ['light', 'dark']) {
        const page = createPage({ saved, dark: saved === 'light' });

        assert.equal(page.root.dataset.theme, saved);
        assert.equal(page.system.disabled, false);
        page.changeSystem(saved !== 'dark');
        assert.equal(page.root.dataset.theme, saved);
    }

    for (const saved of ['', 'sepia', 'DARK', 'null']) {
        const page = createPage({ saved, dark: true });

        assert.equal(page.root.dataset.theme, 'dark');
        assert.equal(page.system.disabled, true);
        page.changeSystem(false);
        assert.equal(page.root.dataset.theme, 'light');
    }
});

test('toggle persists the choice across page loads and keeps accessibility in sync', () => {
    const page = createPage();

    page.toggle.click();

    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.storage.get('theme'), 'dark');
    assert.equal(page.toggle.attributes.get('aria-pressed'), 'true');
    assert.equal(page.toggle.attributes.get('aria-label'), 'Włącz jasny motyw');
    assert.equal(page.system.disabled, false);
    page.changeSystem(false);
    assert.equal(page.root.dataset.theme, 'dark');

    const nextPage = createPage({ saved: page.storage.get('theme'), dark: false });

    assert.equal(nextPage.root.dataset.theme, 'dark');
    nextPage.toggle.click();
    assert.equal(nextPage.root.dataset.theme, 'light');
    assert.equal(nextPage.storage.get('theme'), 'light');
});

test('system button removes the choice and resumes following the system', () => {
    const page = createPage({ saved: 'light', dark: true });

    page.system.click();

    assert.equal(page.storage.has('theme'), false);
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.system.disabled, true);
    page.changeSystem(false);
    assert.equal(page.root.dataset.theme, 'light');
});

test('storage failures preserve the current document choice', () => {
    for (const options of [
        { readFails: true, writeFails: true, removeFails: true },
        { storageFails: true },
    ]) {
        const page = createPage(options);

        page.toggle.click();
        assert.equal(page.root.dataset.theme, 'dark');
        assert.equal(page.system.disabled, false);
        page.changeSystem(true);
        page.changeSystem(false);
        assert.equal(page.root.dataset.theme, 'dark');

        page.system.click();
        assert.equal(page.root.dataset.theme, 'light');
        assert.equal(page.system.disabled, true);
        page.changeSystem(true);
        assert.equal(page.root.dataset.theme, 'dark');
    }
});

test('blocked removal still returns the current document to the system preference', () => {
    const page = createPage({ saved: 'dark', removeFails: true });

    page.system.click();

    assert.equal(page.root.dataset.theme, 'light');
    assert.equal(page.system.disabled, true);
    assert.equal(page.storage.get('theme'), 'dark');
    page.changeSystem(true);
    assert.equal(page.root.dataset.theme, 'dark');
    page.changeSystem(false);
    assert.equal(page.root.dataset.theme, 'light');
});

test('storage events synchronize tabs, clearing resumes the system, unrelated storage is ignored', () => {
    const page = createPage({ dark: false });

    page.storageEvent('other-key', 'dark');
    assert.equal(page.root.dataset.theme, 'light');
    page.storageEvent('theme', 'dark', {});
    assert.equal(page.root.dataset.theme, 'light');

    page.storageEvent('theme', 'dark');
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.system.disabled, false);
    page.changeSystem(false);
    assert.equal(page.root.dataset.theme, 'dark');

    page.storageEvent('theme', null);
    assert.equal(page.root.dataset.theme, 'light');
    assert.equal(page.system.disabled, true);
    page.changeSystem(true);
    assert.equal(page.root.dataset.theme, 'dark');

    page.storageEvent('theme', 'light');
    assert.equal(page.root.dataset.theme, 'light');
    page.storageEvent(null, null);
    assert.equal(page.root.dataset.theme, 'dark');
    page.storageEvent('theme', 'invalid');
    assert.equal(page.system.disabled, true);
});

test('missing controls or matchMedia do not prevent the theme from working', () => {
    const noButtons = createPage({ buttons: false, dark: true });

    assert.equal(noButtons.root.dataset.theme, 'dark');
    noButtons.changeSystem(false);
    assert.equal(noButtons.root.dataset.theme, 'light');

    const noQuery = createPage({ matchMedia: false, initialTheme: 'dark' });

    assert.equal(noQuery.root.dataset.theme, 'dark');
    noQuery.toggle.click();
    assert.equal(noQuery.root.dataset.theme, 'light');
    noQuery.system.click();
    assert.equal(noQuery.root.dataset.theme, 'light');
});

test('initial theme script runs synchronously before the first stylesheet', () => {
    assert.ok(bootstrap);
    assert.ok(bootstrap.index < header.indexOf('<link rel="stylesheet"'));
    assert.ok(header.indexOf('</script>', bootstrap.index) < header.indexOf('<link rel="stylesheet"'));
    assert.match(
        header,
        /<script src="<\?= \$assetUrl\('\/js\/theme\.js'\) \?>" defer><\/script>/,
    );
});

test('the initial script and runtime agree on saved, system and unavailable preferences', () => {
    const cases = [
        [{ dark: true }, 'dark'],
        [{ dark: false }, 'light'],
        [{ saved: 'light', dark: true }, 'light'],
        [{ saved: 'dark', dark: false }, 'dark'],
        [{ saved: 'invalid', dark: true }, 'dark'],
        [{ saved: '', dark: false }, 'light'],
        [{ storageFails: true, dark: true }, 'dark'],
        [{ readFails: true, dark: true }, 'dark'],
        [{ matchMedia: false }, 'light'],
        [{ saved: 'dark', matchMedia: false }, 'dark'],
        [{ matchMediaFails: true, dark: true }, 'light'],
        [{ saved: 'dark', matchMediaFails: true }, 'dark'],
    ];

    for (const [options, expected] of cases) {
        const page = createPage({ ...options, bootstrap: true });

        assert.equal(page.bootstrapTheme, expected, JSON.stringify(options));
        assert.equal(page.root.dataset.theme, expected, JSON.stringify(options));
    }
});

test('a failing matchMedia still allows manual selection and returning to the default', () => {
    const page = createPage({ matchMediaFails: true });

    page.toggle.click();
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.storage.get('theme'), 'dark');
    page.system.click();
    assert.equal(page.root.dataset.theme, 'light');
    assert.equal(page.storage.has('theme'), false);
});

test('account themes override anonymous storage and ignore its cross-tab events', () => {
    for (const [accountTheme, dark, expected] of [
        ['dark', false, 'dark'], ['light', true, 'light'], ['system', true, 'dark'],
    ]) {
        const page = createPage({ accountTheme, dark, saved: expected === 'dark' ? 'light' : 'dark', bootstrap: true });
        assert.equal(page.bootstrapTheme, expected);
        assert.equal(page.root.dataset.theme, expected);
        page.storageEvent('theme', expected === 'dark' ? 'light' : 'dark');
        page.storageEvent(null, null);
        assert.equal(page.root.dataset.theme, expected);
    }
});

test('account changes send CSRF, prevent overlapping writes and preserve anonymous storage', async () => {
    let finish;
    const requests = [];
    const page = createPage({
        accountTheme: 'light', saved: 'light',
        fetch: (url, options) => {
            requests.push({ url, options });
            return new Promise((resolve) => { finish = resolve; });
        },
    });
    const pending = page.toggle.click();
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.toggle.disabled, true);
    assert.equal(page.system.disabled, true);
    page.toggle.click();
    page.system.click();
    assert.equal(requests.length, 1);
    const { url, options } = requests[0];
    assert.equal(url, '/profile/theme');
    assert.equal(options.method, 'POST');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.redirect, 'error');
    assert.match(options.headers['Content-Type'], /application\/x-www-form-urlencoded/);
    assert.equal(options.body.get('csrfToken'), 'test-csrf-token');
    assert.equal(options.body.get('theme'), 'dark');
    finish({ ok: true, json: async () => ({ theme: 'dark' }) });
    await pending;
    assert.equal(page.root.dataset.accountTheme, 'dark');
    assert.equal(page.toggle.disabled, false);
    assert.equal(page.storage.get('theme'), 'light');
    assert.equal(page.saveStatus.hidden, true);
});

test('account reset persists system preference and resumes following the system', async () => {
    const page = createPage({
        accountTheme: 'dark', dark: false, saved: 'dark',
        fetch: async (url, options) => {
            assert.equal(options.body.get('theme'), 'system');
            return { ok: true, json: async () => ({ theme: 'system' }) };
        },
    });
    await page.system.click();
    assert.equal(page.root.dataset.accountTheme, 'system');
    assert.equal(page.root.dataset.theme, 'light');
    assert.equal(page.system.disabled, true);
    page.changeSystem(true);
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.storage.get('theme'), 'dark');
});

test('failed account saves roll back the theme and expose an accessible retry message', async () => {
    for (const fetch of [
        async () => { throw new Error('Network unavailable'); },
        async () => ({ ok: false }),
        async () => ({ ok: true, json: async () => ({ theme: 'system' }) }),
    ]) {
        const page = createPage({ accountTheme: 'dark', saved: 'light', fetch });
        await page.toggle.click();
        assert.equal(page.root.dataset.theme, 'dark');
        assert.equal(page.root.dataset.accountTheme, 'dark');
        assert.equal(page.toggle.disabled, false);
        assert.equal(page.saveStatus.hidden, false);
        assert.match(page.saveStatus.textContent, /Nie udało się zapisać motywu/);
        assert.equal(page.storage.get('theme'), 'light');
    }
});

test('a successful navbar change synchronizes the profile select while preserving an unsaved selection', async () => {
    for (const [profileTheme, expected] of [['light', 'dark'], ['system', 'system']]) {
        const page = createPage({
            accountTheme: 'light', profileTheme,
            fetch: async () => ({ ok: true, json: async () => ({ theme: 'dark' }) }),
        });
        await page.toggle.click();
        assert.equal(page.profileTheme.value, expected);
    }
});
