const assert = require('node:assert/strict');
const { randomBytes } = require('node:crypto');
const test = require('node:test');
const { browser, createQuizFixture, cleanupQuizFixture, csrf, login } = require('./helpers/quiz-fixtures.cjs');

const requestId = () => randomBytes(16).toString('hex');
const cards = html => [...html.matchAll(/class="anime-card" data-anime-id="(\d+)" [^>]*data-episodes="(\d*)"/g)]
    .map(([, id, episodes]) => ({ id, episodes: Number(episodes) }));

test('Catalogue chat requires an account and CSRF and rejects cancelled and invalid requests', async () => {
    const guest = browser();
    const catalogue = await guest.request('/chat');
    assert.equal(catalogue.status, 200);
    assert.match(catalogue.html, /Czat katalogowy/);
    assert.equal((await guest.request('/chat', { intent: 'send' })).headers.get('location'), '/login');
    assert.equal((await guest.request('/chat/1')).status, 404);
    assert.equal((await guest.request('/admin/personas')).status, 404);

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
        const dashboard = await client.request('/dashboard');
        assert.equal(dashboard.status, 200);
        assert.match(dashboard.html, /action="\/chat"/);
        assert.doesNotMatch(dashboard.html, /href="\/chat\/\d+/);
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
            assert.match(story, /Fragment opisu katalogowego|W opisie znaleziono wzmiankę|Brak dopasowań w opisach/);
            console.log('Character trait excerpts: ' + (story.match(/<q lang=/g)?.length ?? 0));

            assert.equal((await client.request('/chat', { _csrf: token, intent: 'reset' })).status, 303);
            const magic = await send('send', 'Znajdz anime z magia i ponad 20 odcinkow.');
            const magicCards = cards(magic);
            assert.ok(magicCards.length > 0 && magicCards.every(card => card.episodes >= 21));
            assert.match(magic, /Motywy w opisach · magia/);
            assert.equal((magic.match(/Wzmianka w opisie/g) ?? []).length, magicCards.length);
            assert.doesNotMatch(magic, /Katalog nie rozpoznaje tagu/);
            const withoutMagic = await send('send', 'Jednak bez magii.');
            assert.match(withoutMagic, /Bez wzmianek w opisach · magia/);
            assert.match(withoutMagic, /Co najmniej 21 odcinków/);
            assert.doesNotMatch(withoutMagic, /Motywy w opisach · magia/);

            assert.equal((await client.request('/chat', { _csrf: token, intent: 'reset' })).status, 303);
            const school = await send('send', 'Szukam anime o szkole magii, ale bez horroru.');
            assert.ok(cards(school).length > 0);
            assert.match(school, /Motywy w opisach · szkoła magii/);
            assert.match(school, /Bez tagu „Horror”/);
            assert.doesNotMatch(school, /<li>Horror<\/li>/);

            assert.equal((await client.request('/chat', { _csrf: token, intent: 'reset' })).status, 303);
            const fish = await send('send', 'anime o rybach');
            const fishCards = cards(fish);
            assert.ok(fishCards.length > 0);
            assert.match(fish, /Motywy w opisach · ryby/);
            assert.equal((fish.match(/Wzmianka w opisie/g) ?? []).length, fishCards.length);
            assert.doesNotMatch(fish, /Spełnia rozpoznane filtry katalogu/);
            const moreFish = await send('more');
            assert.ok(cards(moreFish).length > 0 && cards(moreFish).every(card => fishCards.every(seen => seen.id !== card.id)));
            assert.match(moreFish, /Wzmianka w opisie/);

            const longerFish = await send('send', 'anime o rybach, ponad 20 odcinków');
            assert.ok(cards(longerFish).length > 0 && cards(longerFish).every(card => card.episodes >= 21));
            assert.match(longerFish, /Motywy w opisach · ryby/);
            assert.match(longerFish, /Wzmianka w opisie/);

            const unusual = await send('send', 'anime o telepatycznych kaktusach');
            assert.equal(cards(unusual).length, 0);
            assert.match(unusual, /Brak dopasowań w opisach/);
            assert.doesNotMatch(unusual, /value="more"/);

            assert.equal((await client.request('/chat', { _csrf: token, intent: 'reset' })).status, 303);
            const unknownTag = await send('send', 'anime z tagiem BzdurnyTag123');
            assert.equal(cards(unknownTag).length, 0);
            assert.match(unknownTag, /Katalog nie rozpoznaje tagu „BzdurnyTag123”/);
            assert.doesNotMatch(unknownTag, /value="more"/);
        } finally {
            assert.equal(cleanupQuizFixture(fixture).removed, 2);
        }
    });
