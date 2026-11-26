'use strict';

document.addEventListener('error', event => {
    const image = event.target;
    if (image instanceof HTMLImageElement && image.hasAttribute('data-anime-cover')) {
        image.hidden = true;
    }
}, true);

document.querySelectorAll('img[data-anime-cover]').forEach(image => {
    if (image.complete && image.naturalWidth === 0) {
        image.hidden = true;
    }
});
