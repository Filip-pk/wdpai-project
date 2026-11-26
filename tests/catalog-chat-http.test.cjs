const assert = require('node:assert/strict');
const { randomBytes } = require('node:crypto');
const test = require('node:test');
const { browser, createQuizFixture, cleanupQuizFixture, csrf, login } = require('./helpers/quiz-fixtures.cjs');

const requestId = () => randomBytes(16).toString('hex');
const cards = html => [...html.matchAll(/class="anime-card" data-anime-id="(\d+)" [^>]*data-episodes="(\d*)"/g)]
    .map(([, id, episodes]) => ({ id, episodes: Number(episodes) }));

test('Catalogue chat requires an account and CSRF and rejects cancelled and invalid requests', async () => {
    const guest = browser();
    assert.equal((await guest.request('/chat')).status, 200);
    assert.equal((await guest.request('/chat', { intent: 'send' })).headers.get('location'), '/login');
    const persona = await guest.request('/chat/1');
    assert.equal(persona.status, 200);
    assert.match(persona.html, /id="persona-demo-notice"/);

    const fixture = createQuizFixture();
    try {
        const client = await login(fixture.owner, fixture.password);
        const page = await client.request('/chat');
        assert.match(page.html, /id="assistant-message" name="message"/);
        const token = csrf(page);
        assert.equal((await client.request('/chat', { intent: 'reset' })).status, 403);
        assert.equal((await client.request('/chat', { _csrf: token, intent: 'send', requestId: requestId(), message: ' ' })).status, 422);
        const id = requestId();
        assert.equal((await client.request('/chat', { _csrf: token, intent: 'cancel', requestId: id })).status, 204);
        const cancelled = await client.request('/chat', { _csrf: token, intent: 'send', requestId: id, message: 'Szukam horroru.' });
        assert.equal(cancelled.status, 409);
        assert.match(cancelled.html, /Wyszukiwanie anulowano/);
        assert.equal((await client.request('/dashboard')).status, 200);
    } finally {
        assert.equal(cleanupQuizFixture(fixture).removed, 2);
    }
});

test('Live Ollama and imported catalogue keep filters across turns and skip displayed titles',
    { skip: process.env.ANIVIBE_TEST_OLLAMA !== '1' }, async () => {
        const fixture = createQuizFixture();
        try {
            const client = await login(fixture.owner, fixture.password);
            const token = csrf(await client.request('/chat'));
            const send = async (intent, message = '') => {
                const start = performance.now();
                const result = await client.request('/chat', { _csrf: token, intent, requestId: requestId(), message });
                assert.equal(result.status, 303);
                assert.equal(result.headers.get('location'), '/chat');
                const page = await client.request('/chat');
                assert.equal(page.status, 200);
                assert.doesNotMatch(page.html, /Ollama nie odpowiedziała|Spróbuj podać gatunek/);
                console.log('Chat ' + intent + ': ' + Math.round(performance.now() - start) + ' ms');
                return page.html;
            };
            const initial = await send('send', 'Chcę harem, ponad 20 odcinków, w stylu podobnym do No Game No Life.');
            const firstCards = cards(initial);
            assert.ok(firstCards.length > 0 && firstCards.length <= 5 && firstCards.every(card => card.episodes >= 21));
            assert.match(initial, /Co najmniej 21 odcinków/);
            assert.match(initial, /Z tagiem „Harem”/);
            assert.match(initial, /Podobne do „No Game No Life”/);

            const followUp = await send('send', 'Jednak bez haremu, bardziej zależy mi na grach i strategii.');
            const shown = cards(followUp);
            assert.equal(shown.length, 5);
            assert.ok(shown.every(card => card.episodes >= 21));
            assert.match(followUp, /Bez tagu „Harem”/);
            assert.match(followUp, /Co najmniej 21 odcinków/);
            assert.match(followUp, /Preferowane motywy/);
            assert.doesNotMatch(followUp, /<li>Harem<\/li>/);
            assert.match(followUp, /Fragment opisu katalogowego|Dostępny opis nie pozwala potwierdzić/);
            console.log('Game and strategy excerpts: ' + (followUp.match(/<q lang=/g)?.length ?? 0));
            const next = cards(await send('more'));
            assert.ok(next.length > 0 && next.every(card => card.episodes >= 21 && shown.every(seen => seen.id !== card.id)));

            const other = await login(fixture.other, fixture.password);
            assert.doesNotMatch((await other.request('/chat')).html, /Co najmniej 21 odcinków<\/li>/);
            assert.equal((await client.request('/chat', { _csrf: token, intent: 'reset' })).status, 303);
            const reset = await client.request('/chat');
            assert.equal(cards(reset.html).length, 0);
            assert.doesNotMatch(reset.html, /Co najmniej 21 odcinków<\/li>/);

            const story = await send('send', 'Krótka, zakończona seria TV z inteligentnym głównym bohaterem.');
            assert.match(story, /Tylko zakończone/);
            assert.match(story, /Format TV/);
            assert.match(story, /Fragment opisu katalogowego|Dostępny opis nie pozwala potwierdzić/);
            console.log('Character trait excerpts: ' + (story.match(/<q lang=/g)?.length ?? 0));
        } finally {
            assert.equal(cleanupQuizFixture(fixture).removed, 2);
        }
    });
