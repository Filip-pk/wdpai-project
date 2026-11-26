(() => {
    const selector = '[data-catalog-chat]';
    let pending = null;

    function feedback(root, intent) {
        const results = intent === 'more';
        return {
            progress: root.querySelector(results ? '[data-chat-results-progress]' : '[data-chat-progress]'),
            cancel: root.querySelector(results ? '[data-chat-results-cancel]' : '[data-chat-cancel]'),
        };
    }

    function scrollToLatest(root) {
        const messages = root.querySelector('[data-chat-messages]');
        if (messages) {
            messages.scrollTop = messages.scrollHeight;
        }
    }

    function updatePending(root, intent, busy) {
        const { progress, cancel } = feedback(root, intent);
        progress.textContent = busy ? root.dataset.waiting : '';
        root.toggleAttribute('data-chat-pending', busy);
        if (busy) {
            root.querySelector('[data-chat-error]')?.remove();
        }
        root.querySelectorAll('button[type="submit"], [data-chat-example]').forEach(button => {
            button.disabled = busy;
        });
        root.querySelector('textarea').readOnly = busy;
        cancel.hidden = !busy || intent === 'reset';
        cancel.disabled = false;
    }

    function initialize(root) {
        const examples = root.querySelector('[data-chat-examples]');
        if (examples) {
            examples.hidden = false;
        }
        scrollToLatest(root);
    }

    document.addEventListener('submit', async event => {
        const form = event.target.closest('[data-chat-form]');
        const root = form?.closest(selector);
        if (!root) {
            return;
        }
        event.preventDefault();
        if (pending) {
            return;
        }

        const data = new FormData(form);
        const intent = data.get('intent');
        pending = { action: form.action, csrf: data.get('_csrf'), id: data.get('requestId'), intent };
        updatePending(root, intent, true);
        let networkError = false;
        try {
            const response = await fetch(form.action, { method: 'POST', body: data });
            const url = new URL(response.url);
            if (url.pathname !== '/chat') {
                window.location.assign(url);
                return;
            }
            const documentFromResponse = new DOMParser().parseFromString(await response.text(), 'text/html');
            const replacement = documentFromResponse.querySelector(selector);
            if (!replacement) {
                networkError = true;
                return;
            }

            const draft = replacement.querySelector('textarea');
            if (!response.ok && draft && !draft.value && intent === 'send') {
                draft.value = data.get('message');
            }
            const error = replacement.querySelector('[data-chat-error]');
            if (error && intent === 'more') {
                feedback(replacement, intent).progress.after(error);
            }
            root.replaceWith(replacement);
            initialize(replacement);
            const focus = error
                ?? (intent === 'more' ? replacement.querySelector('#assistant-results-title') : null)
                ?? [...replacement.querySelectorAll('[data-chat-reply]')].pop()
                ?? draft;
            focus?.focus();
        } catch {
            networkError = true;
        } finally {
            pending = null;
            if (root.isConnected) {
                updatePending(root, intent, false);
                if (networkError) {
                    feedback(root, intent).progress.textContent = root.dataset.networkError;
                }
            }
        }
    });

    document.addEventListener('click', async event => {
        const example = event.target.closest('[data-chat-example]');
        if (example && !pending) {
            const draft = example.closest(selector).querySelector('textarea');
            draft.value = example.dataset.chatExample;
            draft.focus();
            return;
        }

        const button = event.target.closest('[data-chat-cancel], [data-chat-results-cancel]');
        if (!button || !pending?.id) {
            return;
        }
        const root = button.closest(selector);
        const request = pending;
        const { progress } = feedback(root, request.intent);
        button.disabled = true;
        progress.textContent = root.dataset.stopping;
        try {
            const data = new URLSearchParams({ _csrf: request.csrf, requestId: request.id, intent: 'cancel' });
            const response = await fetch(request.action, { method: 'POST', body: data });
            if (response.status !== 204) {
                throw new Error('Cancellation failed');
            }
        } catch {
            if (pending === request && root.isConnected) {
                button.disabled = false;
                progress.textContent = root.dataset.networkError;
            }
        }
    });

    const root = document.querySelector(selector);
    if (root) {
        initialize(root);
    }
})();
