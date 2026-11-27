const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const { php } = require('./helpers/admin-fixtures.cjs');

const source = fs.readFileSync(path.join(__dirname, '../public/js/password-form.js'), 'utf8');

function load() {
    const window = {};
    vm.runInNewContext(source, { window, TextEncoder, document: { querySelectorAll: () => [] } });
    return window.AniVibePasswordRules;
}

const email = 'Ola.Kowalska@example.test';
const samples = [
    ['Testowe-Haslo-2026!', ''],
    ['short1', 'js.password.length'],
    ['Tylko-Litery-Haslo', 'js.password.letter_digit'],
    ['2026-1234-5678', 'js.password.letter_digit'],
    ['ą'.repeat(36) + '1', 'js.password.length'],
    ['Haslo\0testowe1', 'js.password.invalid_character'],
    ['OLA.KOWALSKA@EXAMPLE.TEST', 'js.password.letter_digit'],
    ['ola.kowalska1@example.test', ''],
    ['Ola.Kowalska', 'js.password.letter_digit'],
    ['ola2026x', ''],
    ['żółw1234', ''],
];

test('the browser rules name the first broken rule', () => {
    const rules = load();
    for (const [password, expected] of samples) {
        assert.equal(rules.passwordProblem(password, email), expected, JSON.stringify(password));
    }
    assert.equal(rules.passwordProblem('kowalska1', 'kowalska1@example.test'), 'js.password.not_email');
    assert.equal(rules.passwordProblem('Kowalska1@Example.test', ' kowalska1@example.test '), 'js.password.not_email');
    assert.equal(rules.confirmationProblem('Haslo-2026', ''), '', 'An empty confirmation is left to required');
    assert.equal(rules.confirmationProblem('Haslo-2026', 'Haslo-2027'), 'js.password.mismatch');
    assert.equal(rules.confirmationProblem('Haslo-2026', 'Haslo-2026'), '');
});

test('the browser accepts exactly the passwords the server accepts', () => {
    const rules = load();
    const passwords = [...samples.map(([password]) => password), 'kowalska1', 'ą'.repeat(36), 'Ab1' + 'x'.repeat(69), 'Ab1' + 'x'.repeat(70)];
    const server = php(String.raw`
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
echo json_encode(array_map(static fn (string $password): bool =>
    App\Service\AccountSecurityService::isPasswordStrong($password, $input['email']), $input['passwords']), JSON_THROW_ON_ERROR);
`, { email, passwords });
    passwords.forEach((password, index) => {
        assert.equal(rules.passwordProblem(password, email) === '', server[index], JSON.stringify(password));
    });
});
