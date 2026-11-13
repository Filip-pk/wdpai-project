(function () {
    'use strict';
    const form = document.querySelector('[data-appearance-form]');
    if (!form) return;
    const root = document.documentElement;
    const controls = document.querySelectorAll('[data-preference]');
    const theme = form.querySelector('[name="theme"]');
    const translate = (key, fallback) => window.AniVibeI18n?.t(key) ?? fallback;
    let system = null;
    try {
        system = window.matchMedia('(prefers-color-scheme: dark)');
    } catch (error) {
        // Ręczny podgląd działa również bez preferencji systemowych.
    }
    let savedTheme = root.dataset.accountTheme;

    function preview() {
        for (const control of controls) {
            const keys = {
                font_size: 'fontSize', line_height: 'lineHeight', interface_density: 'density',
                high_contrast: 'highContrast', reduced_motion: 'reducedMotion', strong_focus: 'strongFocus',
                large_controls: 'largeControls', message_text_size: 'messageTextSize', comment_text_size: 'commentTextSize',
            };
            const key = keys[control.name];
            if (key) root.dataset[key] = control.value;
        }
        root.dataset.theme = theme.value === 'system' ? (system?.matches ? 'dark' : 'light') : theme.value;
        const toggle = document.querySelector('.theme-toggle');
        if (toggle) {
            const isDark = root.dataset.theme === 'dark';
            toggle.setAttribute('aria-pressed', String(isDark));
            toggle.setAttribute('aria-label', isDark
                ? translate('js.theme.enable_light', 'Włącz jasny motyw')
                : translate('js.theme.enable_dark', 'Włącz ciemny motyw'));
            toggle.title = toggle.getAttribute('aria-label');
        }
    }

    form.addEventListener('change', preview);
    document.querySelector('[data-accessibility-form]')?.addEventListener('change', preview);
    system?.addEventListener('change', preview);
    // Przełącznik w nawigacji i formularz współdzielą zapisany motyw konta.
    new MutationObserver(() => {
        if (savedTheme === root.dataset.accountTheme) return;
        if (theme.value === savedTheme) theme.value = root.dataset.accountTheme;
        savedTheme = root.dataset.accountTheme;
        preview();
    }).observe(root, { attributes: true, attributeFilter: ['data-account-theme'] });
})();
