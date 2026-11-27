const assert = require('node:assert/strict');
const test = require('node:test');
const { browser, csrf, login } = require('./helpers/quiz-fixtures.cjs');
const {
    createAdminFixture, cleanupAdminFixture, setFixtureAdmin, fixtureState, createMessageReports, messageReportState,
} = require('./helpers/admin-fixtures.cjs');

const mailpitUrl = process.env.ANIVIBE_MAILPIT_URL ?? 'http://127.0.0.1:8025';
const tabs = ['overview', 'diagnostics', 'errors', 'users', 'reports', 'catalog', 'history'];

// Open reports are listed oldest first, so the fixture reports may be on a later page.
async function reportsPageWith(client, reportId) {
    for (let page = 1; page <= 50; page++) {
        const response = await client.request('/admin?tab=reports&page=' + page);
        assert.equal(response.status, 200);
        if (response.html.includes('data-report-id="' + reportId + '"')) return response;
        if (!response.html.includes('rel="next"')) return null;
    }
    return null;
}

function reportCard(html, reportId) {
    const start = html.indexOf('data-report-id="' + reportId + '"');
    assert.ok(start >= 0, 'Report ' + reportId + ' is listed');
    return html.slice(start, html.indexOf('</article>', start));
}

async function messagesTo(email) {
    const response = await fetch(new URL('/api/v1/search?' + new URLSearchParams({ query: 'to:' + email }), mailpitUrl),
        { signal: AbortSignal.timeout(5000) });
    assert.equal(response.status, 200);
    return response.json();
}

test('administrator access, diagnostics, mail and role changes are protected and audited', { timeout: 180000 }, async t => {
    const fixture = createAdminFixture();
    try {
        const reports = createMessageReports(fixture);
        const reportRoutes = ['/admin/reports/' + reports.member.reportId + '/dismiss', '/admin/reports/' + reports.member.reportId + '/hide'];
        const guest = browser();
        const member = await login(fixture.member, fixture.password);
        const moderator = await login(fixture.moderator, fixture.password);
        const admin = await login(fixture.admin, fixture.password);
        let token;

        await t.test('guests cannot read admin tabs or submit admin operations', async () => {
            for (const tab of tabs) {
                const response = await guest.request('/admin?tab=' + tab);
                assert.equal(response.status, 303);
                assert.equal(response.headers.get('location'), '/login');
            }
            for (const route of ['/admin/diagnostics', '/admin/mail-test', '/admin/users/' + fixture.target.id + '/moderator', ...reportRoutes]) {
                assert.equal((await guest.request(route, { csrfToken: '' })).status, 303);
            }
            assert.doesNotMatch((await guest.request('/')).html, /href="\/admin"/);
        });

        await t.test('ordinary members and moderators cannot access administration', async () => {
            for (const client of [member, moderator]) {
                for (const tab of tabs) assert.equal((await client.request('/admin?tab=' + tab)).status, 403);
                const denied = await client.request('/admin');
                assert.match(denied.html, /<a href="\/" class="error-button error-button-primary">/, 'Denied users are sent home');
                assert.doesNotMatch(denied.html, /href="\/admin"|error-button-secondary/, 'No link back to the page that was denied');
                assert.doesNotMatch((await client.request('/profile')).html, /href="\/admin"/);
                for (const route of ['/admin/diagnostics', '/admin/mail-test', '/admin/users/' + fixture.target.id + '/moderator', ...reportRoutes]) {
                    assert.equal((await client.request(route, { csrfToken: csrf(await client.request('/profile')), reason: 'QA' })).status, 403);
                }
            }
            assert.equal(messageReportState(reports).member.status, 'open', 'Moderators cannot decide message reports');
            assert.equal((await admin.request('/moderation')).status, 403, 'Admin role does not silently grant moderator privileges');
            assert.equal(fixtureState(fixture).actions.length, 0);
        });

        await t.test('all tabs render and escape user controlled account names and anime titles', async () => {
            for (const tab of tabs) {
                const response = await admin.request('/admin?tab=' + tab + '&query=' + fixture.marker);
                assert.equal(response.status, 200, tab + ': ' + response.html.slice(-300));
                assert.match(response.html, /data-admin-panel/);
                assert.match(response.html, /href="\/admin"/);
                assert.doesNotMatch(response.html, /<svg onload=alert\(1\)>|<img src=x onerror=alert\(1\)>/);
                assert.doesNotMatch(response.html, />admin\.[a-z_.]+[<\s]/);
                assert.doesNotMatch(response.html, /SQLSTATE|Fatal error|Warning:|Stack trace/);
                token = csrf(response);
            }
        });

        await t.test('CSRF rejection produces no audit entries, role changes or email', async () => {
            const before = fixtureState(fixture);
            const reportsBefore = messageReportState(reports);
            for (const csrfToken of ['', 'f'.repeat(64)]) {
                for (const route of ['/admin/diagnostics', '/admin/mail-test', '/admin/users/' + fixture.target.id + '/moderator', ...reportRoutes]) {
                    assert.equal((await admin.request(route, { csrfToken, enabled: '1', reason: 'QA' })).status, 403);
                }
            }
            assert.deepEqual(fixtureState(fixture), before);
            assert.deepEqual(messageReportState(reports), reportsBefore);
            assert.equal((await messagesTo(fixture.admin.email)).messages_count, 0);
            const rejected = await admin.request('/admin/diagnostics', { csrfToken: '' });
            assert.match(rejected.html, /<a href="\/admin" class="error-button error-button-primary">/, 'Admin errors lead back to the panel');
            assert.doesNotMatch(rejected.html, /Wróć do dyskusji|wrócić do rozmowy/i, 'Admin errors do not mention discussions');
        });

        await t.test('readiness probes are manual, independently reported and audited without sending mail', async () => {
            const response = await admin.request('/admin/diagnostics', { csrfToken: token });
            assert.equal(response.status, 303);
            const page = await admin.request(response.headers.get('location'));
            for (const service of ['database', 'mail', 'ollama', 'environment']) {
                assert.ok(page.html.includes('data-service="' + service + '"'), service + ' has a diagnostic result');
            }
            assert.match(page.html, /data-service="environment" data-status="ok"/);
            assert.match(page.html, /data-service="database" data-status="ok"/);
            assert.match(page.html, /role="status"/);
            assert.equal(fixtureState(fixture).actions.filter(row => row.action === 'diagnostics_checked').length, 1);
            assert.equal((await messagesTo(fixture.admin.email)).messages_count, 0, 'SMTP readiness probe does not send mail');
            const limited = await admin.request('/admin/diagnostics', { csrfToken: token });
            assert.equal(limited.status, 429);
            assert.ok(Number(limited.headers.get('retry-after')) > 0);
        });

        await t.test('mail test ignores a forged recipient, sends to the administrator and is rate limited', async () => {
            const response = await admin.request('/admin/mail-test', {
                csrfToken: token, to: fixture.member.email, email: fixture.member.email, userId: fixture.member.id,
            });
            assert.equal(response.status, 303);
            const sent = await messagesTo(fixture.admin.email);
            assert.equal(sent.messages_count, 1);
            assert.equal((await messagesTo(fixture.member.email)).messages_count, 0);
            const message = await (await fetch(new URL('/api/v1/message/' + encodeURIComponent(sent.messages[0].ID), mailpitUrl))).json();
            assert.deepEqual(message.To.map(recipient => recipient.Address), [fixture.admin.email]);
            assert.doesNotMatch(message.Text, /password|csrfToken/i);
            const mailAudit = fixtureState(fixture).actions.find(row => row.action === 'mail_test');
            assert.equal(JSON.parse(mailAudit.details).result, 'accepted');
            assert.equal((await admin.request('/admin/mail-test', { csrfToken: token })).status, 429);
            assert.equal((await messagesTo(fixture.admin.email)).messages_count, 1);
        });

        await t.test('moderator changes validate targets and reason and preserve audit history', async () => {
            const route = '/admin/users/' + fixture.target.id + '/moderator';
            for (const reason of ['', ' ', 'x'.repeat(1001)]) {
                assert.equal((await admin.request(route, { csrfToken: token, enabled: '1', reason })).status, 422);
            }
            assert.equal((await admin.request(route, { csrfToken: token, enabled: 'admin', reason: 'QA' })).status, 422);
            assert.equal((await admin.request('/admin/users/' + fixture.admin.id + '/moderator', { csrfToken: token, enabled: '1', reason: 'QA' })).status, 409);
            assert.equal((await admin.request(route, { csrfToken: token, enabled: '1', reason: 'QA <script>alert(1)</script>' })).status, 303);
            assert.equal(fixtureState(fixture).isModerator, true);
            const count = fixtureState(fixture).actions.length;
            assert.equal((await admin.request(route, { csrfToken: token, enabled: '1', reason: 'Repeat QA' })).status, 409);
            assert.equal(fixtureState(fixture).actions.length, count);
            assert.equal((await admin.request(route, { csrfToken: token, enabled: '0', reason: 'QA\nKoniec testu' })).status, 303);
            assert.equal(fixtureState(fixture).isModerator, false);
            const history = await admin.request('/admin?tab=history');
            assert.doesNotMatch(history.html, /<script>alert\(1\)<\/script>/);
            assert.match(history.html, /&lt;script&gt;/);
        });

        await t.test('message reports show only the reported copy and are decided with a reason', async () => {
            const memberCard = reportCard((await reportsPageWith(admin, reports.member.reportId)).html, reports.member.reportId);
            const adminCard = reportCard((await reportsPageWith(admin, reports.admin.reportId)).html, reports.admin.reportId);
            assert.ok(memberCard.includes('Admin QA message from member ' + fixture.marker + ' &lt;svg onload=alert(1)&gt;'));
            assert.ok(memberCard.includes('Admin QA report member'));
            assert.ok(memberCard.includes('formaction="/admin/reports/' + reports.member.reportId + '/hide"'));
            assert.ok(adminCard.includes('action="/admin/reports/' + reports.admin.reportId + '/dismiss"'));
            assert.ok(!adminCard.includes('/hide"'), 'Messages from an administrator cannot be hidden');

            const memberRoute = '/admin/reports/' + reports.member.reportId;
            const adminRoute = '/admin/reports/' + reports.admin.reportId;
            for (const reason of ['', ' ', 'x'.repeat(1001)]) {
                assert.equal((await admin.request(memberRoute + '/hide', { csrfToken: token, reason })).status, 422);
            }
            assert.equal((await admin.request(adminRoute + '/hide', { csrfToken: token, reason: 'QA' })).status, 409);
            assert.equal((await admin.request('/admin/reports/999999999/dismiss', { csrfToken: token, reason: 'QA' })).status, 409);
            assert.deepEqual(messageReportState(reports), { member: { status: 'open', hidden: false }, admin: { status: 'open', hidden: false } });

            const hidden = await admin.request(memberRoute + '/hide', { csrfToken: token, reason: 'QA <script>alert(1)</script>' });
            assert.equal(hidden.status, 303);
            assert.equal(hidden.headers.get('location'), '/admin?tab=reports');
            assert.equal((await admin.request(memberRoute + '/hide', { csrfToken: token, reason: 'QA' })).status, 409);
            assert.equal((await admin.request(adminRoute + '/dismiss', { csrfToken: token, reason: 'Zgłoszenie sprzed nadania roli' })).status, 303);
            assert.deepEqual(messageReportState(reports), { member: { status: 'removed', hidden: true }, admin: { status: 'dismissed', hidden: false } });
            assert.equal(await reportsPageWith(admin, reports.member.reportId), null, 'Decided reports leave the list');

            const audit = fixtureState(fixture).actions.filter(row => row.action.startsWith('message_'));
            assert.deepEqual(audit.map(row => [row.action, JSON.parse(row.details).reportId]),
                [['message_hidden', reports.member.reportId], ['message_report_dismissed', reports.admin.reportId]]);
            assert.ok(audit.every(row => !row.details.includes('Admin QA message')), 'The audit does not copy the message');
            const history = await admin.request('/admin?tab=history');
            assert.doesNotMatch(history.html, /<script>alert\(1\)<\/script>/);

            const target = await login(fixture.target, fixture.password);
            const hiddenConversation = await target.request('/messages/' + reports.member.conversationId);
            assert.equal(hiddenConversation.status, 200);
            assert.ok(!hiddenConversation.html.includes('Admin QA message from member'), 'The hidden message is no longer shown');
            assert.ok((await target.request('/messages/' + reports.admin.conversationId)).html.includes('Admin QA message from admin'),
                'A dismissed report leaves the message visible');
        });

        await t.test('filters and pagination handle literal SQL metacharacters and malformed input', async () => {
            for (const tab of ['users', 'catalog']) {
                for (const query of [fixture.marker + '%', 'Ż'.repeat(200), "' OR 1=1 --"]) {
                    assert.equal((await admin.request('/admin?' + new URLSearchParams({ tab, query, page: '999999' }))).status, 200);
                }
                assert.equal((await admin.request('/admin?tab=' + tab + '&query[]=x')).status, 200);
                for (const query of ['bad\nvalue', '\u0000']) {
                    assert.equal((await admin.request('/admin?' + new URLSearchParams({ tab, query }))).status, 422);
                }
            }
            const modules = ['application', 'database', 'mail', 'smtp', 'ollama', 'environment', 'admin', 'auth', 'diagnostics'];
            const errorsPage = await admin.request('/admin?tab=errors&module=auth');
            assert.equal(errorsPage.status, 200);
            assert.match(errorsPage.html, /<select id="admin-module" name="module">/, 'Modules are chosen from a fixed list');
            assert.match(errorsPage.html, /<option value="auth" selected>auth<\/option>/);
            for (const module of modules) assert.ok(errorsPage.html.includes('<option value="' + module + '"'), module);
            for (const module of ['nieznany', 'AUTH', '<script>']) {
                const page = await admin.request('/admin?' + new URLSearchParams({ tab: 'errors', module }));
                assert.equal(page.status, 200);
                assert.match(page.html, /<option value="" selected>/, 'An unknown module falls back to all modules');
                assert.ok(!page.html.includes('value="' + module + '"') && !page.html.includes('&lt;script&gt;'),
                    'An unknown module is not reflected');
            }
            assert.equal((await admin.request('/admin?tab=errors&module[]=auth')).status, 200);
            for (const missing of ['all', 'description', 'image']) {
                const page = await admin.request('/admin?' + new URLSearchParams({ tab: 'catalog', query: fixture.marker, missing }));
                assert.equal(page.status, 200);
                assert.match(page.html, new RegExp('/anime/' + fixture.anime.id));
            }
        });

        await t.test('all six languages resolve administration labels', async () => {
            for (const language of ['en', 'de', 'fr', 'es', 'ru', 'pl']) {
                await admin.request('/language', { csrfToken: token, language, returnTo: '/admin' });
                for (const tab of tabs) {
                    const response = await admin.request('/admin?tab=' + tab);
                    assert.equal(response.status, 200);
                    assert.match(response.html, new RegExp('<html lang="' + language + '"'));
                    assert.doesNotMatch(response.html, />admin\.[a-z_.]+[<\s]/);
                }
            }
        });

        await t.test('role revocation takes effect for an existing session and prevents new actions', async () => {
            const before = fixtureState(fixture);
            assert.equal(setFixtureAdmin(fixture, false).updated, 1);
            assert.equal((await admin.request('/admin')).status, 403);
            assert.equal((await admin.request('/admin/mail-test', { csrfToken: token })).status, 403);
            assert.equal((await admin.request('/admin/users/' + fixture.target.id + '/moderator', { csrfToken: token, enabled: '1', reason: 'QA' })).status, 403);
            assert.equal((await admin.request('/admin/reports/' + reports.admin.reportId + '/dismiss', { csrfToken: token, reason: 'QA' })).status, 403);
            assert.doesNotMatch((await admin.request('/profile')).html, /href="\/admin"/);
            assert.deepEqual(fixtureState(fixture), before);
        });
    } finally {
        cleanupAdminFixture(fixture);
    }
});
