const fs = require('node:fs');
const path = require('node:path');

const source = 'https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime';
const api = 'https://datasets-server.huggingface.co';
const dataset = 'TheRealVigilante/Processed_Top_15k_Anime';
const legacyIds = [20, 1535, 42310, 9253, 16498, 44511];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let lastRequestAt = 0;

async function request(route, fields) {
    const url = api + route + '?' + new URLSearchParams({ dataset, config: 'default', split: 'train', ...fields });
    for (let attempt = 0; attempt < 4; attempt++) {
        await delay(Math.max(0, 1100 - (Date.now() - lastRequestAt)));
        lastRequestAt = Date.now();
        let response;
        try {
            response = await fetch(url, { signal: AbortSignal.timeout(20000), redirect: 'error' });
        } catch (error) {
            if (attempt === 3) throw new Error('Nie można połączyć się ze źródłem anime.');
        }
        if (response?.ok) {
            const body = await response.text();
            if (Buffer.byteLength(body) > 8 * 1024 * 1024) throw new Error('Odpowiedź przekracza 8 MB.');
            const payload = JSON.parse(body);
            if (!Array.isArray(payload.rows) || payload.rows.length > 100) throw new Error('Nieprawidłowe dane źródłowe.');
            for (const entry of payload.rows) {
                if (!entry.row || !Array.isArray(entry.truncated_cells) || entry.truncated_cells.length > 0) {
                    throw new Error('Źródło zwróciło niekompletny rekord anime.');
                }
            }
            return payload;
        }
        if (response && response.status !== 429 && response.status < 500) {
            throw new Error('Źródło zwróciło HTTP ' + response.status + '.');
        }
        if (attempt === 3) throw new Error('Nie udało się pobrać próbki anime.');
        const retryAfter = Number(response?.headers.get('retry-after'));
        await delay(Math.max(2 ** (attempt + 1), Math.min(15, Number.isFinite(retryAfter) ? retryAfter : 0)) * 1000);
    }
}

async function main() {
    let limit = 200;
    let output;
    for (const argument of process.argv.slice(2)) {
        if (/^--limit=\d+$/.test(argument)) limit = Number(argument.slice(8));
        else if (argument.startsWith('--output=') && argument.length > 9) output = argument.slice(9);
        else throw new Error('Użycie: node scripts/download-anime.cjs [--limit=200] [--output=plik.json]');
    }
    if (!Number.isInteger(limit) || limit < legacyIds.length || limit > 5000) {
        throw new Error('Limit musi mieścić się w zakresie 6–5000.');
    }
    output ??= path.join('storage', 'imports', 'anime-source-' + limit + '.json');
    const entries = new Map();
    for (let offset = 0; entries.size < limit; offset += 100) {
        const payload = await request('/rows', { offset: String(offset), length: '100' });
        for (const entry of payload.rows) {
            if (!Number.isInteger(entry.row.anime_id) || entry.row.anime_id <= 0) throw new Error('Nieprawidłowy identyfikator anime.');
            entries.set(entry.row.anime_id, entry.row);
        }
        if (entries.size < limit && (payload.rows.length === 0 || offset + payload.rows.length >= payload.num_rows_total || offset > limit * 100)) {
            throw new Error('Zbiór nie zawiera wymaganej liczby anime.');
        }
        console.log('[OK] Pobrano unikalnych anime: ' + entries.size + '.');
    }
    for (const id of legacyIds) {
        if (entries.has(id)) continue;
        const payload = await request('/filter', { where: '"anime_id"=' + id, length: '1' });
        if (payload.rows.length !== 1 || payload.rows[0].row.anime_id !== id) throw new Error('Brakuje dotychczasowego anime MAL ' + id + '.');
        entries.set(id, payload.rows[0].row);
    }
    const selected = new Map(legacyIds.map(id => [id, entries.get(id)]));
    for (const [id, row] of entries) {
        if (selected.size >= limit) break;
        selected.set(id, row);
    }
    fs.mkdirSync(path.dirname(output), { recursive: true });
    if (fs.existsSync(output) && fs.lstatSync(output).isSymbolicLink()) throw new Error('Plik docelowy jest dowiązaniem.');
    fs.writeFileSync(output, JSON.stringify({ source, fetchedAt: new Date().toISOString(), data: [...selected.values()] }));
    console.log('[OK] Zapisano ' + selected.size + ' anime w ' + output + '. Baza pozostała bez zmian.');
}

main().catch(error => {
    console.error('[BŁĄD] ' + error.message);
    process.exitCode = 1;
});
