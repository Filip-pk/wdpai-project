(function () {
    'use strict';

    const storageKey = 'theme';
    const root = document.documentElement;
    const themeToggle = document.querySelector('.theme-toggle');
    const themeSystem = document.querySelector('.theme-system');
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
    let userTheme = getSavedTheme();

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
            themeToggle.disabled = false;
        }

        if (themeSystem) {
            themeSystem.disabled = userTheme === null;
            themeSystem.title = userTheme === null
                ? 'Aktualnie używany jest motyw systemowy'
                : 'Przywróć motyw zgodny z ustawieniem systemowym';
        }
    }

    themeToggle?.addEventListener('click', () => {
        userTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';

        try {
            window.localStorage.setItem(storageKey, userTheme);
        } catch (error) {
            // Preferencja pozostaje w pamięci bieżącego dokumentu.
        }

        applyTheme(userTheme);
    });

    themeSystem?.addEventListener('click', () => {
        userTheme = null;

        try {
            window.localStorage.removeItem(storageKey);
        } catch (error) {
            // Powrót do ustawień systemu nadal działa w bieżącym dokumencie.
        }

        applyTheme(getSystemTheme());
    });

    colorSchemeQuery?.addEventListener('change', () => {
        if (userTheme === null) {
            applyTheme(getSystemTheme());
        }
    });

    window.addEventListener('storage', (event) => {
        if (event.key !== storageKey && event.key !== null) {
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
