const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const test = require('node:test');
const { deflateSync } = require('node:zlib');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
const password = 'Awatar-Testowy-2026!';
const pngSignature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);

function png(width, height, color) {
    function chunk(type, data) {
        const bytes = Buffer.concat([Buffer.from(type), data]);
        let crc = 0xffffffff;
        for (const byte of bytes) {
            crc ^= byte;
            for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
        }
        const length = Buffer.alloc(4);
        const checksum = Buffer.alloc(4);
        length.writeUInt32BE(data.length);
        checksum.writeUInt32BE((crc ^ 0xffffffff) >>> 0);
        return Buffer.concat([length, bytes, checksum]);
    }

    const header = Buffer.alloc(13);
    header.writeUInt32BE(width, 0);
    header.writeUInt32BE(height, 4);
    header[8] = 8;
    header[9] = 2;
    const pixels = Buffer.alloc((width * 3 + 1) * height);
    for (let row = 0; row < height; row++) {
        for (let column = 0; column < width; column++) {
            const offset = row * (width * 3 + 1) + column * 3 + 1;
            pixels[offset] = color[0];
            pixels[offset + 1] = color[1];
            pixels[offset + 2] = color[2];
        }
    }
    return Buffer.concat([pngSignature, chunk('IHDR', header), chunk('IDAT', deflateSync(pixels)), chunk('IEND', Buffer.alloc(0))]);
}

// A complete grayscale 8 x 8 baseline JPEG: a zero DC coefficient and AC end-of-block.
function jpeg() {
    function segment(marker, bytes) {
        const size = Buffer.alloc(2);
        size.writeUInt16BE(bytes.length + 2);
        return Buffer.concat([Buffer.from([0xff, marker]), size, Buffer.from(bytes)]);
    }
    const counts = [1, ...Array(15).fill(0)];
    return Buffer.concat([
        Buffer.from([0xff, 0xd8]),
        segment(0xdb, [0, ...Array(64).fill(1)]),
        segment(0xc0, [8, 0, 8, 0, 8, 1, 1, 0x11, 0]),
        segment(0xc4, [0, ...counts, 0]),
        segment(0xc4, [0x10, ...counts, 0]),
        segment(0xda, [1, 1, 0, 0, 63, 0]),
        Buffer.from([0x3f, 0xff, 0xd9]),
    ]);
}

function browser() {
    let cookie = '';
    return {
        async request(route, fields) {
            const response = await fetch(new URL(route, baseUrl), {
                method: fields === undefined ? 'GET' : 'POST',
                headers: cookie ? { Cookie: cookie } : {},
                body: fields === undefined ? undefined : fields instanceof FormData ? fields : new URLSearchParams(fields),
                redirect: 'manual',
                signal: AbortSignal.timeout(10000),
            });
            for (const value of response.headers.getSetCookie()) {
                if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
            }
            const bytes = Buffer.from(await response.arrayBuffer());
            const html = response.headers.get('content-type')?.startsWith('image/') ? '' : bytes.toString('utf8');
            assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i);
            return { status: response.status, headers: response.headers, bytes, html };
        },
    };
}

function csrf(page) {
    const match = page.html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(match, 'The page contains a CSRF token');
    return match[1];
}

function redirected(page, location) {
    assert.equal(page.status, 303);
    assert.equal(page.headers.get('location'), location);
}

function avatarUrls(page) {
    return [...page.html.matchAll(/<img\b[^>]*\bsrc="(\/avatars\/\d+\?v=[a-f0-9]{32})"/g)].map(match => match[1]);
}

function upload(token, bytes = png(480, 320, [70, 110, 180]), options = {}) {
    const form = new FormData();
    if (token !== undefined) form.append('csrfToken', token);
    if (bytes !== null) form.append(options.field ?? 'avatar', new Blob([bytes], { type: options.type ?? 'image/png' }), options.name ?? 'avatar.png');
    for (const [name, value] of Object.entries(options.fields ?? {})) form.append(name, value);
    return form;
}

async function account(name) {
    const client = browser();
    const email = `avatar-${randomUUID()}@example.test`;
    const token = csrf(await client.request('/register'));
    redirected(await client.request('/register', { name, email, password, passwordConfirmation: password, csrfToken: token }), '/login?registered=1&mail=sent');
    redirected(await client.request('/login', { email, password, csrfToken: token }), '/projects');
    const profile = await client.request('/profile');
    assert.equal(profile.status, 200);
    return { client, email, name, token: csrf(profile) };
}

test('avatar uploads are validated, persistent and assigned to the signed-in account', async (t) => {
    const guest = browser();
    const first = await account('Awatar A');
    const second = await account('Awatar B');
    let firstUrl;
    let secondUrl;

    await t.test('guests cannot upload and new accounts show the existing fallback', async () => {
        redirected(await guest.request('/profile/avatar', upload('wrong')), '/login');
        for (const user of [first, second]) {
            const page = await user.client.request('/profile');
            assert.deepEqual(avatarUrls(page), []);
            assert.match(page.html, /<form\b[^>]*action="\/profile\/avatar"[^>]*enctype="multipart\/form-data"/);
            assert.match(page.html, /<label\b[^>]*for="profile-avatar"/);
            assert.doesNotMatch(page.html, /<input\b[^>]*name="avatar"[^>]*disabled/);
        }
    });

    await t.test('missing, incorrect and array CSRF values do not change the avatar', async () => {
        for (const form of [
            upload(undefined),
            upload('wrong'),
            upload(undefined, png(8, 8, [10, 20, 30]), { fields: { 'csrfToken[]': first.token } }),
        ]) {
            const page = await first.client.request('/profile/avatar', form);
            assert.equal(page.status, 403);
            assert.match(page.html, /<link\b[^>]*href="\/css\/profile\.css(?:\?[^"]*)?"/);
        }
        assert.deepEqual(avatarUrls(await first.client.request('/profile')), []);
    });

    await t.test('missing, oversized, malformed and non-raster files are rejected', async () => {
        for (const form of [
            upload(first.token, null),
            upload(first.token, Buffer.from('not an image')),
            upload(first.token, Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), { type: 'image/svg+xml', name: 'avatar.svg' }),
            upload(first.token, pngSignature),
            upload(first.token, png(4097, 1, [10, 20, 30])),
            upload(first.token, png(8, 8, [10, 20, 30]), { field: 'avatar[]' }),
            upload(first.token, Buffer.alloc(4 * 1024 * 1024 + 1)),
        ]) {
            const page = await first.client.request('/profile/avatar', form);
            assert.equal(page.status, 422);
            assert.match(page.html, /<link\b[^>]*href="\/css\/profile\.css(?:\?[^"]*)?"/);
        }
        assert.deepEqual(avatarUrls(await first.client.request('/profile')), []);
    });

    await t.test('valid PNG is re-encoded, cropped and shown in both profile and navigation', async () => {
        const maliciousTail = '<?php AVATAR_TEST_UNTRUSTED_TAIL ?>';
        const original = Buffer.concat([png(480, 320, [70, 110, 180]), Buffer.from(maliciousTail)]);
        redirected(await first.client.request('/profile/avatar', upload(first.token, original, {
            fields: { userId: '999999999', user_id: '0' },
        })), '/profile?avatarSaved=1');
        const page = await first.client.request('/profile?avatarSaved=1');
        const urls = avatarUrls(page);
        assert.ok(urls.length >= 2, 'Both the account menu and profile summary render the avatar');
        assert.equal(new Set(urls).size, 1);
        firstUrl = urls[0];
        const image = await guest.request(firstUrl);
        assert.equal(image.status, 200);
        assert.equal(image.headers.get('content-type'), 'image/png');
        assert.equal(image.headers.get('x-content-type-options'), 'nosniff');
        assert.deepEqual(image.bytes.subarray(0, 8), pngSignature);
        assert.equal(image.bytes.readUInt32BE(16), 256);
        assert.equal(image.bytes.readUInt32BE(20), 256);
        assert.equal(image.bytes.includes(Buffer.from(maliciousTail)), false);
        assert.deepEqual(avatarUrls(await second.client.request('/profile')), []);
    });

    await t.test('JPEG is accepted without replacing another account image', async () => {
        const firstId = firstUrl.match(/\/avatars\/(\d+)/)[1];
        redirected(await second.client.request('/profile/avatar', upload(second.token, jpeg(), {
            type: 'image/jpeg', name: 'portrait.jpg', fields: { userId: firstId, user_id: firstId },
        })), '/profile?avatarSaved=1');
        const urls = avatarUrls(await second.client.request('/profile'));
        assert.ok(urls.length >= 2);
        secondUrl = urls[0];
        assert.notEqual(secondUrl, firstUrl);
        assert.ok(avatarUrls(await first.client.request('/profile')).every(url => url === firstUrl));
        const image = await guest.request(secondUrl);
        assert.equal(image.status, 200);
        assert.equal(image.headers.get('content-type'), 'image/png');
        assert.deepEqual(image.bytes.subarray(0, 8), pngSignature);
    });

    await t.test('the saved image appears throughout the app and survives another login', async () => {
        for (const route of ['/', '/anime', '/dashboard', '/watchlist', '/projects']) {
            const page = await first.client.request(route);
            assert.equal(page.status, 200, route);
            assert.ok(avatarUrls(page).includes(firstUrl), route);
            assert.ok(!avatarUrls(page).includes(secondUrl), route);
        }
        redirected(await first.client.request('/logout', { csrfToken: first.token }), '/login');
        const token = csrf(await first.client.request('/login'));
        redirected(await first.client.request('/login', { email: first.email, password, csrfToken: token }), '/projects');
        const profile = await first.client.request('/profile');
        assert.ok(avatarUrls(profile).includes(firstUrl));
        first.token = csrf(profile);
        assert.ok(avatarUrls(await first.client.request('/anime')).includes(firstUrl));
    });

    await t.test('replacement uses a new URL and invalid uploads preserve the saved image', async () => {
        redirected(await first.client.request('/profile/avatar', upload(first.token, png(320, 480, [180, 80, 40]))), '/profile?avatarSaved=1');
        const urls = avatarUrls(await first.client.request('/profile'));
        assert.ok(urls.length >= 2);
        const replacement = urls[0];
        assert.notEqual(replacement, firstUrl);
        assert.equal((await guest.request(replacement)).status, 200);
        assert.equal((await guest.request(firstUrl)).status, 404);
        const invalid = await first.client.request('/profile/avatar', upload(first.token, Buffer.from('invalid image')));
        assert.equal(invalid.status, 422);
        assert.match(invalid.html, /<link\b[^>]*href="\/css\/profile\.css(?:\?[^"]*)?"/);
        assert.ok(avatarUrls(await first.client.request('/profile')).every(url => url === replacement));
        assert.ok(avatarUrls(await second.client.request('/profile')).every(url => url === secondUrl));
        assert.equal((await guest.request(replacement.replace(/v=[a-f0-9]{32}/, 'v=wrong'))).status, 404);
        assert.equal((await guest.request('/avatars/0?v=' + '0'.repeat(32))).status, 404);
    });
});
