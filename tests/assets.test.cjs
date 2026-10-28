const assert = require('node:assert/strict');
const { createHash } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const publicDirectory = path.join(__dirname, '../public');

function hash(content) {
    return createHash('sha256').update(content).digest('hex');
}

for (const [route, expectedPaths] of [
    ['/', ['/css/style.css', '/js/theme.js']],
    ['/login', ['/css/login.css', '/css/style.css', '/js/theme.js']],
]) {
    test(`${route} serves assets with versions matching their current content`, async () => {
        const response = await fetch(new URL(route, baseUrl), {
            signal: AbortSignal.timeout(5000),
        });

        assert.equal(response.status, 200);

        const html = await response.text();
        const assetUrls = [...html.matchAll(/\b(?:src|href)="(\/(?:css|js)\/[^\"]+)"/g)]
            .map((match) => new URL(match[1].replaceAll('&amp;', '&'), baseUrl));

        assert.deepEqual(
            assetUrls.map((url) => url.pathname).sort(),
            expectedPaths,
            'The existing public asset paths must be preserved',
        );

        for (const url of assetUrls) {
            const localContent = fs.readFileSync(
                path.join(publicDirectory, url.pathname.slice(1)),
            );
            const expectedHash = hash(localContent);

            assert.equal(url.searchParams.get('v'), expectedHash.slice(0, 12), url.pathname);
            assert.deepEqual([...url.searchParams.keys()], ['v']);

            const assetResponse = await fetch(url, {
                signal: AbortSignal.timeout(5000),
            });

            assert.equal(assetResponse.status, 200, url.pathname);

            const servedContent = Buffer.from(await assetResponse.arrayBuffer());

            assert.equal(hash(servedContent), expectedHash, url.pathname);
        }
    });
}
