(() => {
    'use strict';

    const account = document.documentElement.dataset.accountId;
    const form = document.querySelector('[data-quiz-form]');
    const quizId = form?.elements.namedItem('quizId')?.value;
    if (!account) return;
    const prefix = 'anivibe-quiz-draft:' + account + ':';
    try {
        if (!form || !quizId) {
            if (document.querySelector('[data-quiz-state="completed"]')) {
                for (let index = sessionStorage.length - 1; index >= 0; index--) {
                    const key = sessionStorage.key(index);
                    if (key?.startsWith(prefix)) sessionStorage.removeItem(key);
                }
            }
            return;
        }
        const key = prefix + quizId;
        for (let index = sessionStorage.length - 1; index >= 0; index--) {
            const oldKey = sessionStorage.key(index);
            if (oldKey?.startsWith(prefix) && oldKey !== key) sessionStorage.removeItem(oldKey);
        }
        const saved = JSON.parse(sessionStorage.getItem(key) || '{}');
        for (const fieldset of form.querySelectorAll('[data-quiz-question-id]')) {
            const radios = [...fieldset.querySelectorAll('input[type="radio"]')];
            if (!radios.some(radio => radio.checked)) {
                const answer = radios.find(radio => radio.value === saved[radio.name]);
                if (answer) answer.checked = true;
            }
        }
        form.addEventListener('change', () => {
            const answers = {};
            for (const radio of form.querySelectorAll('input[type="radio"]:checked')) answers[radio.name] = radio.value;
            try { sessionStorage.setItem(key, JSON.stringify(answers)); } catch { /* The form also works without browser storage. */ }
        });
    } catch { /* Browser storage is optional; scoring always happens on the server. */ }
})();
