'use strict';

document.querySelectorAll('img[data-anime-cover]').forEach((image) => {
    const showPlaceholder = () => {
        image.hidden = true;
    };

    image.addEventListener('error', showPlaceholder, { once: true });
    if (image.complete && image.naturalWidth === 0) {
        showPlaceholder();
    }
});
