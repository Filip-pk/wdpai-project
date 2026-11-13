(() => {
    'use strict';

    const data = document.getElementById('anivibe-translations');
    const catalogue = data ? JSON.parse(data.textContent) : { locale: 'pl', messages: {} };
    const t = (key, parameters = {}) => {
        const text = catalogue.messages[key] ?? key;
        return text.replace(/\{([a-zA-Z0-9_]+)\}/g, (placeholder, name) =>
            Object.prototype.hasOwnProperty.call(parameters, name) ? String(parameters[name]) : placeholder);
    };

    window.AniVibeI18n = Object.freeze({ ...catalogue, t });
})();
