(() => {
    'use strict';

    const editor = document.querySelector('[data-showcase-editor]');
    if (!editor) return;

    const search = editor.querySelector('[data-showcase-search]');
    const searchInput = search.querySelector('input[name="q"]');
    const status = search.querySelector('[data-showcase-search-status]');
    const selects = [...editor.querySelectorAll('select[name="animeIds[]"]')];
    const badges = [...editor.querySelectorAll('input[name="badgeCodes[]"]')];
    const badgeCount = editor.querySelector('[data-showcase-badge-count]');
    let controller;

    const updateBadges = () => {
        const count = badges.filter(input => input.checked).length;
        badgeCount.textContent = count + ' / 3';
        for (const input of badges) input.disabled = count >= 3 && !input.checked;
    };

    for (const input of badges) input.addEventListener('change', updateBadges);
    updateBadges();

    search.addEventListener('submit', async event => {
        event.preventDefault();
        controller?.abort();
        const request = new AbortController();
        controller = request;
        status.textContent = search.dataset.loading;
        search.setAttribute('aria-busy', 'true');

        try {
            const url = new URL('/profile/showcase/anime', window.location.origin);
            url.searchParams.set('q', searchInput.value.trim());
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: request.signal,
            });
            if (!response.ok || response.redirected) throw new Error('Search unavailable');
            const data = await response.json();
            if (!Array.isArray(data.animes)) throw new Error('Invalid search response');

            const selectedOptions = new Map();
            for (const select of selects) {
                const option = select.selectedOptions[0];
                if (option?.value) selectedOptions.set(option.value, option.textContent);
            }
            const choices = new Map(selectedOptions);
            for (const anime of data.animes) {
                if (Number.isSafeInteger(anime.id) && anime.id > 0 && typeof anime.title === 'string') {
                    choices.set(String(anime.id), anime.title);
                }
            }

            for (const select of selects) {
                const selectedValue = select.value;
                const emptyOption = select.options[0].cloneNode(true);
                emptyOption.selected = selectedValue === '';
                const options = [emptyOption];
                for (const [id, title] of choices) {
                    const option = document.createElement('option');
                    option.value = id;
                    option.textContent = title;
                    option.selected = id === selectedValue;
                    options.push(option);
                }
                select.replaceChildren(...options);
            }

            editor.querySelector('[data-showcase-query]').value = searchInput.value.trim();
            status.textContent = data.animes.length === 0
                ? search.dataset.empty
                : search.dataset.results.replace('{count}', String(data.animes.length));
        } catch (error) {
            if (error.name !== 'AbortError') status.textContent = search.dataset.error;
        } finally {
            if (controller === request) search.removeAttribute('aria-busy');
        }
    });
})();
