(function () {
    'use strict';

    const menu = document.querySelector('.navigation-menu');
    const toggle = menu?.querySelector('summary');
    const more = document.querySelector('[data-navigation-more]');
    const workspace = document.body.classList.contains('page-workspace');
    const desktop = window.matchMedia('(min-width: 64rem)');
    let opener = toggle;

    if (!menu || !toggle) {
        return;
    }

    document.body.classList.add('navigation-ready');
    if (more) {
        more.parentElement.hidden = false;
    }

    function isSidebar() {
        return workspace && desktop.matches;
    }

    function syncState() {
        const expanded = String(menu.open);
        toggle.setAttribute('aria-expanded', expanded);
        more?.setAttribute('aria-expanded', expanded);
        document.body.classList.toggle('navigation-open', menu.open && !isSidebar());
    }

    function closeMenu(returnFocus) {
        if (isSidebar() || !menu.open) {
            return;
        }
        menu.open = false;
        syncState();
        if (returnFocus) {
            opener?.focus();
        }
    }

    function updateLayout() {
        const focusedInside = menu.contains(document.activeElement);
        menu.open = isSidebar();
        syncState();
        if (focusedInside && !isSidebar()) {
            toggle.focus();
        }
    }

    toggle.addEventListener('click', () => { opener = toggle; });
    menu.addEventListener('toggle', syncState);
    more?.addEventListener('click', () => {
        opener = more;
        menu.open = !menu.open;
        syncState();
        if (menu.open) {
            menu.querySelector('.navigation-panel a')?.focus();
        }
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menu.open && !isSidebar()) {
            event.preventDefault();
            closeMenu(true);
        }
    });
    document.addEventListener('click', event => {
        if (!menu.contains(event.target) && !more?.contains(event.target)) {
            closeMenu(false);
        }
    });
    document.addEventListener('focusin', event => {
        if (!menu.contains(event.target) && !more?.contains(event.target)) {
            closeMenu(false);
        }
    });
    desktop.addEventListener('change', updateLayout);
    updateLayout();
})();
