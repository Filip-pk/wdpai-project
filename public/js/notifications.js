(function () {
    'use strict';

    const badges = document.querySelectorAll('[data-unread-message-count]');
    const links = document.querySelectorAll('[data-notifications-link]');
    const replyBadges = document.querySelectorAll('[data-unread-reply-count]');
    const replyLinks = document.querySelectorAll('[data-notifications-comment-link]');
    const readForms = Array.from(document.querySelectorAll('form[data-mark-conversation-read]'));
    const acknowledgedForms = new WeakSet();
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const translate = (key, fallback, parameters = {}) => window.AniVibeI18n?.t(key, parameters)
        ?? fallback.replace(/\{([a-zA-Z0-9_]+)\}/g, (placeholder, name) =>
            Object.prototype.hasOwnProperty.call(parameters, name) ? String(parameters[name]) : placeholder);
    let timer = null;
    let stopped = false;
    let refreshing = false;

    if (badges.length === 0 && replyBadges.length === 0 && readForms.length === 0) {
        return;
    }

    function updateCount(value) {
        if (typeof value !== 'number') {
            return false;
        }

        const count = Number(value);

        if (!Number.isSafeInteger(count) || count < 0) {
            return false;
        }

        badges.forEach(function (badge) {
            badge.textContent = String(count);
            badge.hidden = count === 0;
        });
        links.forEach(function (link) {
            link.setAttribute('aria-label', translate(
                'js.notifications.messages_unread',
                'Wiadomości. Nieprzeczytane: {count}',
                { count },
            ));
        });

        return true;
    }

    function stopPolling() {
        stopped = true;
        window.clearTimeout(timer);
    }

    function updateReplyCount(value) {
        if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0) {
            return;
        }

        replyBadges.forEach(function (badge) {
            badge.textContent = String(value);
            badge.hidden = value === 0;
        });
        replyLinks.forEach(function (link) {
            link.setAttribute('aria-label', translate(
                'js.notifications.replies_unread',
                'Powiadomienia. Nieprzeczytane: {count}',
                { count: value },
            ));
        });
    }

    function updateConversationCount(conversationId, value) {
        if (typeof conversationId !== 'number' || typeof value !== 'number') {
            return;
        }

        const id = Number(conversationId);
        const count = Number(value);

        if (!Number.isSafeInteger(id) || id < 1 || !Number.isSafeInteger(count) || count < 0) {
            return;
        }

        document.querySelectorAll('[data-conversation-unread="' + id + '"]').forEach(function (badge) {
            badge.textContent = String(count);
            badge.hidden = count === 0;
            badge.setAttribute('aria-label', translate(
                'js.notifications.conversation_unread',
                'Nieprzeczytane wiadomości: {count}',
                { count },
            ));
        });
    }

    async function requestCount(url, options) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options,
        });

        if (response.redirected || response.status === 401 || response.status === 403) {
            stopPolling();
            return null;
        }

        if (!response.ok) {
            return null;
        }

        const result = await response.json();

        if (!updateCount(result.unreadCount)) {
            return null;
        }

        updateConversationCount(result.conversationId, result.conversationUnreadCount);
        updateReplyCount(result.unreadReplyCount);
        return result;
    }

    async function acknowledgeVisibleMessages() {
        for (const form of readForms) {
            if (stopped || document.visibilityState !== 'visible') {
                return;
            }

            if (acknowledgedForms.has(form)) {
                continue;
            }

            const action = new URL(form.action, window.location.href);

            if (action.origin !== window.location.origin || !/^\/messages\/\d+\/read$/.test(action.pathname)) {
                continue;
            }

            const body = new URLSearchParams(new FormData(form));

            if (!body.get('csrfToken') && csrfToken) {
                body.set('csrfToken', csrfToken);
            }

            const lastMessageId = Number(body.get('lastMessageId'));

            if (!Number.isSafeInteger(lastMessageId) || lastMessageId < 1 || !body.get('csrfToken')) {
                continue;
            }

            const result = await requestCount(action.href, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: body,
            });

            if (result !== null) {
                acknowledgedForms.add(form);
                form.hidden = true;
            }
        }
    }

    async function refresh() {
        window.clearTimeout(timer);

        if (stopped || refreshing || document.visibilityState !== 'visible') {
            return;
        }

        refreshing = true;

        try {
            await acknowledgeVisibleMessages();

            if (!stopped && document.visibilityState === 'visible') {
                await requestCount('/notifications', { headers: { Accept: 'application/json' } });
            }
        } catch (error) {
            // Brak sieci nie blokuje strony ani ręcznego oznaczenia odczytu.
        } finally {
            refreshing = false;

            if (!stopped && document.visibilityState === 'visible') {
                timer = window.setTimeout(refresh, 15000);
            }
        }
    }

    document.addEventListener('visibilitychange', function () {
        window.clearTimeout(timer);

        if (document.visibilityState === 'visible') {
            refresh();
        }
    });

    window.addEventListener('pagehide', function () {
        window.clearTimeout(timer);
    });

    refresh();
})();
