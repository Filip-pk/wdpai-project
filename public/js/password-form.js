// Checks a new password in the browser with the same rules as the server
// (AccountSecurityService::isPasswordStrong), so mistakes show before the form is sent.
// The server still validates every request; this only gives earlier feedback.
//
// Markup: the new password input has data-password-rules, its confirmation has
// data-password-confirm, and an e-mail input in the same form is used for the e-mail rule.
(() => {
    'use strict';

    const MIN_CHARACTERS = 8;
    const MAX_BYTES = 72;
    const encoder = new TextEncoder();
    const translate = key => window.AniVibeI18n?.t(key) ?? key;

    /** Returns the translation key of the first broken rule, or '' when the password is acceptable. */
    function passwordProblem(password, email = '') {
        if (password.includes('\0')) return 'js.password.invalid_character';
        if ([...password].length < MIN_CHARACTERS || encoder.encode(password).length > MAX_BYTES) return 'js.password.length';
        if (!/\p{L}/u.test(password) || !/\p{N}/u.test(password)) return 'js.password.letter_digit';
        const address = email.trim().toLowerCase();
        const lowered = password.toLowerCase();
        if (address !== '' && (lowered === address || lowered === address.split('@')[0])) return 'js.password.not_email';
        return '';
    }

    function confirmationProblem(password, confirmation) {
        return confirmation !== '' && confirmation !== password ? 'js.password.mismatch' : '';
    }

    /** Shows the message under the field and blocks submitting while it is not empty. */
    function report(input, key, visible) {
        const message = key === '' ? '' : translate(key);
        input.setCustomValidity(message);
        let note = document.getElementById(input.id + '-problem');
        if (!note) {
            note = document.createElement('p');
            note.id = input.id + '-problem';
            note.className = 'form-field-problem';
            note.setAttribute('aria-live', 'polite');
            input.after(note);
            const described = input.getAttribute('aria-describedby');
            input.setAttribute('aria-describedby', described ? described + ' ' + note.id : note.id);
        }
        note.textContent = visible ? message : '';
        note.hidden = !visible || message === '';
    }

    function bind(form) {
        const password = form.querySelector('[data-password-rules]');
        const confirmation = form.querySelector('[data-password-confirm]');
        const email = form.querySelector('input[type="email"]');
        const touched = new Set();
        const check = () => {
            // An empty field is left to the required attribute and its standard message.
            report(password, password.value === '' ? '' : passwordProblem(password.value, email?.value ?? ''), touched.has(password));
            if (confirmation) {
                report(confirmation, confirmationProblem(password.value, confirmation.value), touched.has(confirmation));
            }
        };
        for (const input of [password, confirmation, email].filter(Boolean)) {
            input.addEventListener('input', check);
            input.addEventListener('blur', () => {
                if (input !== email) touched.add(input);
                check();
            });
        }
        // A submit attempt reveals every message, including fields the user has not left yet.
        form.addEventListener('invalid', () => {
            touched.add(password);
            if (confirmation) touched.add(confirmation);
            check();
        }, true);
        check();
    }

    window.AniVibePasswordRules = Object.freeze({ passwordProblem, confirmationProblem });
    document.querySelectorAll('form').forEach(form => {
        if (form.querySelector('[data-password-rules]')) bind(form);
    });
})();
