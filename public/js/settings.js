(function () {
    'use strict';
    const form = document.querySelector('[data-appearance-form]');
    if (!form) return;
    const root = document.documentElement;
    const controls = form.querySelectorAll('[data-preference]');
    const theme = form.querySelector('[name="theme"]');
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let savedTheme = root.dataset.accountTheme;

    function preview() {
        for (const control of controls) {
            const keys = { font_size: 'fontSize', line_height: 'lineHeight', interface_density: 'density' };
            const key = keys[control.name];
            if (key) root.dataset[key] = control.value;
        }
        root.dataset.theme = theme.value === 'system' ? (system.matches ? 'dark' : 'light') : theme.value;
    }

    form.addEventListener('change', preview);
    system.addEventListener('change', () => { if (theme.value === 'system') preview(); });
    // Przełącznik w nawigacji i formularz współdzielą zapisany motyw konta.
    new MutationObserver(() => {
        if (savedTheme === root.dataset.accountTheme) return;
        if (theme.value === savedTheme) theme.value = root.dataset.accountTheme;
        savedTheme = root.dataset.accountTheme;
        preview();
    }).observe(root, { attributes: true, attributeFilter: ['data-account-theme'] });
})();
