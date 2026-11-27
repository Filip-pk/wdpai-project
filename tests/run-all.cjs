// Runs every test with one command: the PHP tests inside the php container, then the Node tests
// (HTTP, browser and JavaScript) one file at a time, because several files share demo content.
//
//   node tests/run-all.cjs                 all tests (the Docker stack must be running)
//   node tests/run-all.cjs --php           only the PHP tests
//   node tests/run-all.cjs --node          only the Node tests
//   node tests/run-all.cjs --no-browser    skip the *-responsive tests that need Chrome or Edge
'use strict';

const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const options = new Set(process.argv.slice(2));
if ([...options].some(option => !['--php', '--node', '--no-browser'].includes(option))) {
    console.error('Usage: node tests/run-all.cjs [--php] [--node] [--no-browser]');
    process.exit(2);
}
const runPhp = options.has('--php') || !options.has('--node');
const runNode = options.has('--node') || !options.has('--php');
const failed = [];

if (runPhp) {
    const files = fs.readdirSync(__dirname).filter(name => name.endsWith('.test.php')).sort();
    console.log(`PHP: ${files.length} plików w kontenerze php`);
    for (const name of files) {
        const started = Date.now();
        const result = spawnSync('docker', ['compose', 'exec', '-T', 'php', 'php', 'tests/' + name], {
            cwd: root, encoding: 'utf8', windowsHide: true, maxBuffer: 64 * 1024 * 1024,
        });
        const seconds = ((Date.now() - started) / 1000).toFixed(1);
        if (result.status === 0) {
            console.log(`  OK    ${name} (${seconds} s)`);
            continue;
        }
        failed.push(name);
        const output = `${result.stdout ?? ''}${result.stderr ?? ''}${result.error ? String(result.error) : ''}`.trim();
        console.log(`  BŁĄD  ${name} (${seconds} s)\n${output.split('\n').slice(-20).map(line => '        ' + line).join('\n')}`);
    }
}

if (runNode) {
    let files = fs.readdirSync(__dirname).filter(name => name.endsWith('.test.cjs')).sort();
    if (options.has('--no-browser')) {
        files = files.filter(name => !name.endsWith('-responsive.test.cjs'));
    }
    // The HTTP tests use HTTPS with the local certificate authority created by the server container.
    const environment = { ...process.env };
    const authority = path.join(root, 'docker', 'nginx', 'certs', 'anivibe-ca.crt');
    if (!environment.NODE_EXTRA_CA_CERTS && fs.existsSync(authority)) {
        environment.NODE_EXTRA_CA_CERTS = authority;
    }
    console.log(`\nNode: ${files.length} plików, kolejno`);
    const result = spawnSync(process.execPath, ['--test', '--test-concurrency=1', '--test-reporter=spec',
        ...files.map(name => path.join('tests', name))], { cwd: root, env: environment, stdio: 'inherit', windowsHide: true });
    if (result.status !== 0) {
        failed.push('testy Node (szczegóły powyżej)');
    }
}

console.log(failed.length === 0 ? '\nWszystkie testy przeszły.' : `\nNie przeszły: ${failed.join(', ')}`);
process.exit(failed.length === 0 ? 0 : 1);
