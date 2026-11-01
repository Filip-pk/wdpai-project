(function () {
    'use strict';

    const storageKey = 'theme';
    const root = document.documentElement;
    const themeToggle = document.querySelector('.theme-toggle');
    const themeSystem = document.querySelector('.theme-system');
    const saveStatus = document.querySelector('.theme-save-status');
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const hasAccount = /^[1-9]\d*$/.test(root.dataset.accountId ?? '');
    const profileTheme = hasAccount ? document.querySelector('#profile-theme') : null;
    let saving = false;
    let colorSchemeQuery = null;

    try {
        colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)');
    } catch (error) {
        // Brak preferencji przeglądarki nie blokuje ręcznego wyboru motywu.
    }

    function parseTheme(theme) {
        return theme === 'light' || theme === 'dark' ? theme : null;
    }

    function getSavedTheme() {
        try {
            return parseTheme(window.localStorage.getItem(storageKey));
        } catch (error) {
            return null;
        }
    }

    // Wybór działa w bieżącej karcie również wtedy, gdy zapis jest zablokowany.
    let userTheme = hasAccount ? parseTheme(root.dataset.accountTheme) : getSavedTheme();

    function getSystemTheme() {
        return colorSchemeQuery?.matches ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        root.dataset.theme = theme;

        const isDark = theme === 'dark';
        const toggleLabel = isDark ? 'Włącz jasny motyw' : 'Włącz ciemny motyw';

        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', String(isDark));
            themeToggle.setAttribute('aria-label', toggleLabel);
            themeToggle.title = toggleLabel;
            themeToggle.disabled = saving;
        }

        if (themeSystem) {
            themeSystem.disabled = saving || userTheme === null;
            themeSystem.title = userTheme === null
                ? 'Aktualnie używany jest motyw systemowy'
                : 'Przywróć motyw zgodny z ustawieniem systemowym';
        }
    }

    async function savePreference(preference) {
        if (saving) {
            return;
        }

        const previousTheme = userTheme;
        userTheme = preference;

        if (!hasAccount) {
            try {
                if (preference === null) {
                    window.localStorage.removeItem(storageKey);
                } else {
                    window.localStorage.setItem(storageKey, preference);
                }
            } catch (error) {
                // Wybór nadal działa w bieżącym dokumencie.
            }

            applyTheme(userTheme ?? getSystemTheme());
            return;
        }

        saving = true;
        if (saveStatus) {
            saveStatus.hidden = true;
            saveStatus.textContent = '';
        }
        applyTheme(userTheme ?? getSystemTheme());

        try {
            if (!csrfMeta?.content) {
                throw new Error('Missing CSRF token');
            }

            const response = await window.fetch('/profile/theme', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: new URLSearchParams({ csrfToken: csrfMeta.content, theme: preference ?? 'system' }),
                credentials: 'same-origin',
                redirect: 'error',
            });
            if (!response.ok) {
                throw new Error('Theme save failed');
            }

            const result = await response.json();
            if (result.theme !== (preference ?? 'system')) {
                throw new Error('Unexpected theme response');
            }
            root.dataset.accountTheme = result.theme;
            if (profileTheme && profileTheme.value === (previousTheme ?? 'system')) {
                profileTheme.value = result.theme;
            }
        } catch (error) {
            userTheme = previousTheme;
            if (saveStatus) {
                saveStatus.textContent = 'Nie udało się zapisać motywu. Spróbuj ponownie.';
                saveStatus.hidden = false;
            }
        } finally {
            saving = false;
            applyTheme(userTheme ?? getSystemTheme());
        }
    }

    themeToggle?.addEventListener('click', () =>
        savePreference(root.dataset.theme === 'dark' ? 'light' : 'dark'));
    themeSystem?.addEventListener('click', () => savePreference(null));

    colorSchemeQuery?.addEventListener('change', () => {
        if (userTheme === null) {
            applyTheme(getSystemTheme());
        }
    });

    window.addEventListener('storage', (event) => {
        if (hasAccount || (event.key !== storageKey && event.key !== null)) {
            return;
        }

        try {
            if (event.storageArea && event.storageArea !== window.localStorage) {
                return;
            }
        } catch (error) {
            return;
        }

        userTheme = event.key === storageKey ? parseTheme(event.newValue) : null;
        applyTheme(userTheme ?? getSystemTheme());
    });

    const initialTheme = userTheme ?? (colorSchemeQuery
        ? getSystemTheme()
        : parseTheme(root.dataset.theme) ?? 'light');

    applyTheme(initialTheme);
})();
