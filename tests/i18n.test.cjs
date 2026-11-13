const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/js/i18n.js'), 'utf8');
function load(catalogue) {
    const window = {};
    vm.runInNewContext(source, {
        window,
        document: { getElementById: () => catalogue ? { textContent: JSON.stringify(catalogue) } : null },
    });
    return window.AniVibeI18n;
}

test('JavaScript uses its locale and substitutes complete parameters once', () => {
    const translator = load({ locale: 'ru', messages: { 'js.reply': '{user}: {count}', 'js.empty': '' } });
    assert.equal(translator.locale, 'ru');
    assert.equal(translator.t('js.reply', { user: '<Ada> {count}', count: 0 }), '<Ada> {count}: 0');
    assert.equal(translator.t('js.reply', { user: '$&' }), '$&: {count}');
    assert.equal(translator.t('js.reply', Object.create({ user: 'inherited' })), '{user}: {count}');
    assert.equal(translator.t('js.empty'), '');
    assert.equal(translator.t('js.missing'), 'js.missing');
});

test('JavaScript remains usable when no catalogue is embedded', () => {
    const translator = load(null);
    assert.equal(translator.locale, 'pl');
    assert.equal(translator.t('js.missing'), 'js.missing');
});
