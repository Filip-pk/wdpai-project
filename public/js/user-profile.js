'use strict';

(() => {
    const button = document.querySelector('[data-open-message]');
    const dialog = document.querySelector('#profile-message-dialog');
    const fallback = document.querySelector('[data-message-fallback]');

    if (!button || !dialog || typeof dialog.showModal !== 'function') {
        return;
    }

    dialog.hidden = false;
    button.hidden = false;
    if (fallback) {
        fallback.hidden = true;
    }

    button.addEventListener('click', () => {
        if (!dialog.open) {
            dialog.showModal();
        }
    });

    dialog.addEventListener('close', () => {
        button.focus();
    });
})();
