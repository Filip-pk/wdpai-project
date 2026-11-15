(() => {
    'use strict';

    const autocomplete = document.querySelector('[data-guess-autocomplete]');
    if (!autocomplete) return;

    const input = autocomplete.querySelector('input');
    const list = autocomplete.querySelector('[role="listbox"]');
    const status = document.getElementById('guess-suggestions-status');
    let suggestions = [];
    let activeIndex = -1;
    let timer;
    let controller;
    let requestId = 0;

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-describedby', 'guess-answer-help guess-suggestions-status');
    status.hidden = false;
    status.textContent = autocomplete.dataset.help;

    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
        for (const option of list.children) option.setAttribute('aria-selected', 'false');
    };

    const cancel = () => {
        clearTimeout(timer);
        controller?.abort();
        requestId += 1;
    };

    const select = (index) => {
        const suggestion = suggestions[index];
        if (!suggestion) return;
        cancel();
        input.value = suggestion.title;
        status.textContent = autocomplete.dataset.selected.replace('{title}', suggestion.title);
        close();
        input.focus();
    };

    const activate = (index) => {
        activeIndex = index;
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        for (const [position, option] of Array.from(list.children).entries()) {
            option.setAttribute('aria-selected', position === index ? 'true' : 'false');
        }
        const option = list.children[index];
        input.setAttribute('aria-activedescendant', option.id);
        option.scrollIntoView({block: 'nearest'});
    };

    const render = () => {
        list.replaceChildren();
        activeIndex = -1;
        suggestions.forEach((suggestion, index) => {
            const option = document.createElement('li');
            option.id = `guess-suggestion-${index}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            const title = document.createElement('span');
            title.textContent = suggestion.title;
            option.append(title);
            if (suggestion.matchedTitle && suggestion.matchedTitle !== suggestion.title) {
                const alias = document.createElement('small');
                alias.textContent = suggestion.matchedTitle;
                option.append(alias);
            }
            option.addEventListener('pointerdown', (event) => event.preventDefault());
            option.addEventListener('click', () => select(index));
            list.append(option);
        });
        list.hidden = suggestions.length === 0;
        input.setAttribute('aria-expanded', suggestions.length ? 'true' : 'false');
        input.removeAttribute('aria-activedescendant');
    };

    input.addEventListener('input', () => {
        cancel();
        close();
        suggestions = [];
        list.replaceChildren();
        status.textContent = autocomplete.dataset.help;
        const query = input.value.trim();
        if (Array.from(query).length < 2) return;
        const currentRequest = requestId;
        timer = setTimeout(async () => {
            controller = new AbortController();
            status.textContent = autocomplete.dataset.loading;
            try {
                const response = await fetch(`/guess/suggestions?q=${encodeURIComponent(query)}`, {
                    signal: controller.signal,
                    headers: {Accept: 'application/json'},
                    credentials: 'same-origin'
                });
                if (!response.ok) throw new Error('Suggestions unavailable');
                const data = await response.json();
                if (currentRequest !== requestId || input.value.trim() !== query) return;
                if (!Array.isArray(data.suggestions)) throw new Error('Invalid suggestions');
                suggestions = data.suggestions.filter((item) => item && typeof item.title === 'string'
                    && item.title.length <= 200 && typeof item.matchedTitle === 'string').slice(0, 10);
                render();
                status.textContent = suggestions.length
                    ? autocomplete.dataset.results.replace('{count}', String(suggestions.length))
                    : autocomplete.dataset.empty;
            } catch (error) {
                if (error.name === 'AbortError' || currentRequest !== requestId) return;
                close();
                status.textContent = autocomplete.dataset.error;
            }
        }, 180);
    });

    input.addEventListener('keydown', (event) => {
        if (event.isComposing) return;
        if (event.key === 'Escape') {
            cancel();
            close();
            status.textContent = '';
        } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && suggestions.length) {
            event.preventDefault();
            const direction = event.key === 'ArrowDown' ? 1 : -1;
            const index = activeIndex < 0 ? (direction === 1 ? 0 : suggestions.length - 1)
                : (activeIndex + direction + suggestions.length) % suggestions.length;
            activate(index);
        } else if (event.key === 'Enter' && !list.hidden && activeIndex >= 0) {
            event.preventDefault();
            select(activeIndex);
        } else if (event.key === 'Tab') {
            cancel();
            close();
        }
    });

    input.addEventListener('blur', () => {
        cancel();
        close();
    });
})();
