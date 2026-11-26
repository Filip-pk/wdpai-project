(() => {
    const selector = '[data-catalog-chat]';
    let pending = null;

    function scrollToLatest(root) {
        const messages = root.querySelector('[data-chat-messages]');
        if (messages) {
            messages.scrollTop = messages.scrollHeight;
        }
    }

    function updatePending(root, intent, busy) {
        const progress = root.querySelector('[data-chat-progress]');
        progress.textContent = busy ? root.dataset.waiting : '';
        root.querySelectorAll('button[type="submit"]').forEach(button => {
            button.disabled = busy;
        });
        root.querySelector('textarea').readOnly = busy;
        const cancel = root.querySelector('[data-chat-cancel]');
        cancel.hidden = !busy || intent === 'reset';
        cancel.disabled = false;
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
        pending = { action: form.action, csrf: data.get('_csrf'), id: data.get('requestId') };
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
            root.replaceWith(replacement);
            scrollToLatest(replacement);
            const focus = replacement.querySelector('[data-chat-error]')
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
                    root.querySelector('[data-chat-progress]').textContent = root.dataset.networkError;
                }
            }
        }
    });

    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-chat-cancel]');
        if (!button || !pending?.id) {
            return;
        }
        const root = button.closest(selector);
        const progress = root.querySelector('[data-chat-progress]');
        const request = pending;
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
        scrollToLatest(root);
    }
})();
