const fs = require('node:fs');
const path = require('node:path');
const { createHash, randomUUID } = require('node:crypto');
const { Readable, Transform } = require('node:stream');
const { pipeline } = require('node:stream/promises');
const zlib = require('node:zlib');

const source = 'https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime';
const api = 'https://datasets-server.huggingface.co';
const dataset = 'TheRealVigilante/Processed_Top_15k_Anime';
const legacyIds = [20, 1535, 42310, 9253, 16498, 44511];
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let lastRequestAt = 0;
const fullMetadataSource = 'https://huggingface.co/datasets/moviebrain01/anime-dataset-2025';
const fullDownloadLimit = 100 * 1024 * 1024;

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

async function downloadSample(limit, output) {
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

function prepareOutput(output) {
    fs.mkdirSync(path.dirname(output), { recursive: true });
    if (fs.existsSync(output) && fs.lstatSync(output).isSymbolicLink()) {
        throw new Error('Plik docelowy jest dowiązaniem: ' + path.basename(output) + '.');
    }
}

async function writeAtomicFile(input, output, maximum, options = {}) {
    prepareOutput(output);
    const temporary = output + '.' + randomUUID() + '.part';
    const hash = createHash('sha256');
    let bytes = 0;
    const meter = new Transform({
        transform(chunk, encoding, callback) {
            bytes += chunk.length;
            if (bytes > maximum) {
                callback(new Error('Plik przekracza dopuszczalny rozmiar: ' + path.basename(output) + '.'));
                return;
            }
            hash.update(chunk);
            callback(null, chunk);
        },
    });

    try {
        await pipeline(input, ...(options.transforms ?? []), meter, fs.createWriteStream(temporary, { flags: 'wx' }));
        const sha256 = hash.digest('hex');
        if (bytes === 0 || (options.expectedSize !== undefined && bytes !== options.expectedSize)
            || (options.expectedHash !== undefined && sha256 !== options.expectedHash)) {
            throw new Error('Rozmiar lub suma kontrolna pliku nie zgadzają się ze źródłem: ' + path.basename(output) + '.');
        }
        fs.renameSync(temporary, output);
        return { sha256, bytes };
    } catch (error) {
        if (fs.existsSync(temporary)) fs.unlinkSync(temporary);
        throw error;
    }
}

async function downloadFile(url, output, options = {}) {
    for (let attempt = 0; attempt < 4; attempt++) {
        let retryable = true;
        let retryAfter = 0;
        try {
            const response = await fetch(url, {
                signal: AbortSignal.timeout(60000),
                redirect: 'follow',
                headers: { 'User-Agent': 'AniVibe-Catalog-Importer/1.0' },
            });
            if (!response.ok || response.body === null) {
                retryable = response.status === 429 || response.status >= 500;
                const value = Number(response.headers.get('retry-after'));
                retryAfter = Number.isFinite(value) ? Math.min(15, Math.max(0, value)) : 0;
                await response.body?.cancel();
                throw new Error('Pobieranie ' + path.basename(output) + ' zakończyło się HTTP ' + response.status + '.');
            }

            retryable = false;
            let result;
            try {
                result = await writeAtomicFile(Readable.fromWeb(response.body), output, fullDownloadLimit, options);
            } catch (error) {
                retryable = ['AbortError', 'TimeoutError'].includes(error.name)
                    || ['UND_ERR_SOCKET', 'ECONNRESET', 'ERR_STREAM_PREMATURE_CLOSE'].includes(error.code)
                    || ['UND_ERR_SOCKET', 'ECONNRESET'].includes(error.cause?.code);
                throw error;
            }
            console.log('[OK] Pobrano ' + path.basename(output) + ': ' + result.bytes + ' bajtów.');
            return result;
        } catch (error) {
            if (!retryable || attempt === 3) throw error;
            console.log('[INFO] Ponawiam pobieranie ' + path.basename(output) + '.');
            await delay(Math.max(2 ** (attempt + 1), retryAfter) * 1000);
        }
    }
}

async function materializeFile(url, output, reuseCache, candidates, options = {}) {
    if (reuseCache) {
        for (const candidate of [output, ...candidates]) {
            if (!fs.existsSync(candidate)) continue;
            const stat = fs.lstatSync(candidate);
            if (!stat.isFile() || stat.isSymbolicLink()) continue;
            const result = await writeAtomicFile(fs.createReadStream(candidate), output, fullDownloadLimit, options);
            console.log('[OK] Użyto lokalnego pliku ' + path.basename(candidate) + ': ' + result.bytes + ' bajtów.');
            return result;
        }
    }
    return downloadFile(url, output, options);
}

async function latestAodRelease() {
    const url = 'https://api.github.com/repos/cedya77/anime-offline-database/releases/latest';
    for (let attempt = 0; attempt < 4; attempt++) {
        let retryable = true;
        try {
            const response = await fetch(url, {
                signal: AbortSignal.timeout(60000), redirect: 'error',
                headers: { Accept: 'application/vnd.github+json', 'User-Agent': 'AniVibe-Catalog-Importer/1.0' },
            });
            if (!response.ok) {
                retryable = response.status === 429 || response.status >= 500;
                await response.body?.cancel();
                throw new Error('Nie można odczytać wydania AOD (HTTP ' + response.status + ').');
            }
            retryable = false;
            const content = await response.text();
            if (Buffer.byteLength(content) > 2 * 1024 * 1024) throw new Error('Metadane wydania AOD są zbyt duże.');
            const release = JSON.parse(content);
            if (typeof release.tag_name !== 'string' || !/^\d{4}-\d{2}$/.test(release.tag_name)
                || !Array.isArray(release.assets)) throw new Error('Nieprawidłowe metadane wydania AOD.');
            return release;
        } catch (error) {
            if (!retryable || attempt === 3) throw error;
            await delay(2 ** (attempt + 1) * 1000);
        }
    }
}

async function downloadFull(output, reuseCache) {
    output ??= path.join('storage', 'imports', 'anime-full', 'manifest.json');
    output = path.resolve(output);
    const directory = path.dirname(output);
    const cacheDirectory = path.resolve('storage', 'imports');
    prepareOutput(output);
    const release = await latestAodRelease();
    const compressed = typeof zlib.createZstdDecompress === 'function';
    const assetName = 'anime-offline-database.jsonl' + (compressed ? '.zst' : '');
    const asset = release.assets.find(entry => entry.name === assetName);
    const prefix = 'https://github.com/cedya77/anime-offline-database/releases/download/' + release.tag_name + '/';
    if (!asset || asset.browser_download_url !== prefix + assetName || !Number.isSafeInteger(asset.size) || asset.size <= 0) {
        throw new Error('Wydanie AOD nie zawiera poprawnego pliku ' + assetName + '.');
    }

    const archive = path.join(directory, assetName);
    const archiveHash = typeof asset.digest === 'string' && /^sha256:[a-f0-9]{64}$/.test(asset.digest)
        ? asset.digest.slice(7) : undefined;
    const downloaded = await materializeFile(asset.browser_download_url, archive, reuseCache, [
        path.join(cacheDirectory, 'anime-offline-database-' + release.tag_name + '.jsonl' + (compressed ? '.zst' : '')),
    ], { expectedSize: asset.size, expectedHash: archiveHash });

    const aodPath = path.join(directory, 'anime-offline-database.jsonl');
    const aod = compressed
        ? await writeAtomicFile(fs.createReadStream(archive), aodPath, 512 * 1024 * 1024, { transforms: [zlib.createZstdDecompress()] })
        : downloaded;
    console.log('[OK] Gotowy plik JSONL AOD ' + release.tag_name + ': ' + aod.bytes + ' bajtów.');

    const files = [];
    for (const split of ['train', 'test']) {
        const filename = 'anime-metadata-' + split + '-2025.csv';
        const url = fullMetadataSource + '/resolve/main/' + split + '%20%281%29.csv?download=true';
        const result = await materializeFile(url, path.join(directory, filename), reuseCache, [path.join(cacheDirectory, filename)]);
        files.push({ path: filename, sha256: result.sha256 });
    }

    const manifest = {
        version: 1,
        sources: {
            aod: { path: 'anime-offline-database.jsonl', url: asset.browser_download_url, release: release.tag_name, sha256: aod.sha256 },
            metadata: { dataset: 'moviebrain01/anime-dataset-2025', url: fullMetadataSource, files },
        },
        fetchedAt: new Date().toISOString(),
    };
    const temporary = output + '.' + randomUUID() + '.part';
    try {
        fs.writeFileSync(temporary, JSON.stringify(manifest, null, 4) + '\n', { flag: 'wx' });
        fs.renameSync(temporary, output);
    } catch (error) {
        if (fs.existsSync(temporary)) fs.unlinkSync(temporary);
        throw error;
    }
    console.log('[OK] Zapisano manifest pełnego katalogu: ' + output + '. Baza pozostała bez zmian.');
}

async function main() {
    let limit = 200;
    let output;
    let all = false;
    let reuseCache = false;
    let limitProvided = false;
    for (const argument of process.argv.slice(2)) {
        if (/^--limit=\d+$/.test(argument)) { limit = Number(argument.slice(8)); limitProvided = true; }
        else if (argument.startsWith('--output=') && argument.length > 9) output = argument.slice(9);
        else if (argument === '--all') all = true;
        else if (argument === '--reuse-cache') reuseCache = true;
        else if (argument === '--help') {
            console.log('node scripts/download-anime.cjs [--limit=200] [--output=plik.json]');
            console.log('node scripts/download-anime.cjs --all [--output=katalog/manifest.json] [--reuse-cache]');
            return;
        } else throw new Error('Nieznany argument. Użyj --help, aby zobaczyć składnię.');
    }
    if (all && limitProvided) throw new Error('Nie łącz --all z --limit.');
    if (reuseCache && !all) throw new Error('--reuse-cache jest dostępne tylko z --all.');
    if (all) return downloadFull(output, reuseCache);
    return downloadSample(limit, output);
}

main().catch(error => {
    console.error('[BŁĄD] ' + error.message);
    process.exitCode = 1;
});
