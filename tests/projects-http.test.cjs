const assert = require('node:assert/strict');
const { createHash, randomUUID } = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const baseUrl = process.env.ANIVIBE_TEST_URL ?? 'http://127.0.0.1:8080';
let cookie = '';

async function request(route, fields) {
    const response = await fetch(new URL(route, baseUrl), {
        method: fields === undefined ? 'GET' : 'POST',
        headers: cookie ? { Cookie: cookie } : {},
        body: fields === undefined ? undefined : new URLSearchParams(fields),
        cache: 'no-store',
        redirect: 'manual',
        signal: AbortSignal.timeout(5000),
    });
    for (const value of response.headers.getSetCookie()) {
        if (value.startsWith('PHPSESSID=')) cookie = value.split(';', 1)[0];
    }
    const html = await response.text();
    assert.doesNotMatch(html, /SQLSTATE|PDOException|Stack trace|Fatal error|Warning:/i, route);

    return { status: response.status, headers: response.headers, html };
}

async function getPage(route, expectedStatus = 200) {
    const page = await request(route);
    assert.equal(page.status, expectedStatus, route);

    return page.html;
}

test.before(async () => {
    const html = await getPage('/register');
    const token = html.match(/name="csrfToken"\s+value="([a-f0-9]{64})"/);
    assert.ok(token, 'Registration has a CSRF token');
    const email = `projects-${randomUUID()}@example.test`;
    const password = 'Testowe-Projekty-2026!';
    const registered = await request('/register', {
        name: 'Tester projektów', email, password,
        passwordConfirmation: password, csrfToken: token[1],
    });
    assert.equal(registered.status, 303);
    assert.equal(registered.headers.get('location'), '/login?registered=1&mail=sent');
    const loggedIn = await request('/login', { email, password, csrfToken: token[1] });
    assert.equal(loggedIn.status, 303);
    assert.equal(loggedIn.headers.get('location'), '/projects');
});

function projectsFrom(html) {
    return [...html.matchAll(/data-project-id="(\d+)"\s+data-project-status="([^"]+)"/g)]
        .map((match) => ({ id: match[1], status: match[2] }));
}

test('the project list displays persisted projects in all three statuses', async () => {
    const html = await getPage('/projects');
    const projects = projectsFrom(html);

    assert.ok(projects.length >= 3, 'Run scripts/seed-projects.php before the HTTP tests');
    assert.deepEqual([...new Set(projects.map((project) => project.status))].sort(), [
        'active', 'completed', 'planned',
    ]);
    for (const label of ['Planowany', 'W trakcie', 'Ukończony']) {
        assert.ok(html.includes(label));
    }
});

test('refreshing the list preserves project IDs, statuses and record count', async () => {
    const first = projectsFrom(await getPage('/projects'));
    const second = projectsFrom(await getPage('/projects?source=refresh-test'));
    const third = projectsFrom(await getPage('/projects'));

    assert.ok(first.length >= 3);
    assert.deepEqual(second, first);
    assert.deepEqual(third, first);
});

test('project links open details with the correct ID, status and both dates', async () => {
    const projects = projectsFrom(await getPage('/projects'));

    assert.ok(projects.length >= 3);
    for (const project of projects.slice(0, 3)) {
        const html = await getPage('/projects/' + project.id);

        assert.deepEqual(projectsFrom(html), [project]);
        assert.ok(html.includes('class="project-details"'));
        assert.ok(html.includes('href="/projects"'));
        assert.equal((html.match(/<time\s/g) ?? []).length, 2);
    }
});

test('invalid or missing project addresses return a safe 404', async () => {
    for (const route of ['/projects/0', '/projects/not-a-number', '/projects/1/extra']) {
        const html = await getPage(route, 404);

        assert.ok(html.includes('404'));
        assert.deepEqual(projectsFrom(html), []);
    }
});

test('the existing AniVibe routes remain available', async () => {
    const routes = [
        '/', '/dashboard', '/anime', '/anime/1', '/chat',
        '/chat/1', '/profile', '/watchlist', '/admin',
    ];

    for (const route of routes) {
        assert.ok((await getPage(route)).includes('AniVibe'));
    }
    for (const route of ['/login', '/register']) {
        const page = await request(route);
        assert.equal(page.status, 303);
        assert.equal(page.headers.get('location'), '/');
    }
});

test('project pages load the current version of their theme-aware stylesheet', async () => {
    const html = await getPage('/projects');
    const stylesheet = html.match(/href="(\/css\/projects\.css\?v=([a-f0-9]{12}))"/);

    assert.ok(stylesheet);
    const localContent = fs.readFileSync(path.join(__dirname, '../public/css/projects.css'));
    const expectedHash = createHash('sha256').update(localContent).digest('hex').slice(0, 12);
    assert.equal(stylesheet[2], expectedHash);

    const response = await fetch(new URL(stylesheet[1], baseUrl), {
        signal: AbortSignal.timeout(5000),
    });

    assert.equal(response.status, 200);
    assert.deepEqual(Buffer.from(await response.arrayBuffer()), localContent);
});
