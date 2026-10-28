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
                listeners.get('click')?.();
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
        get localStorage() {
            return window.localStorage;
        },
        document: {
            documentElement: root,
            querySelector(selector) {
                return selector === '.theme-toggle' ? toggle : system;
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
