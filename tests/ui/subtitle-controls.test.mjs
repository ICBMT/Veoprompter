// Real Chromium controls against local HTTP fixtures, not live transcription/rendering.
// BROWSER_EXECUTABLE=/path/to/chromium node --test tests/ui/subtitle-controls.test.mjs
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';
import { parsePrompt } from '../../chrome-extension/prompt-parser.js';

const enabled = Boolean(process.env.BROWSER_EXECUTABLE);
let server, browser, origin;
const root = new URL('../../', import.meta.url);
before(async () => {
    if (!enabled) return;
    const blade = await readFile(new URL('resources/views/subtitles/show.blade.php', root), 'utf8');
    const review = blade.match(/<section class="subtitle-review"[\s\S]*?<\/section>/)[0]
        .replace(/data-(review|save|approve|original)-url="[^"]*"/g, (_, key) => `data-${key}-url="/api/${key}"`)
        .replace(/@if\([^\n]*?\) hidden @endif/g, '')
        .replace(/\{\{.*?\}\}/g, 'fixture expiry');
    const html = `<meta name="csrf-token" content="fixture"><section data-subtitle-job data-status="awaiting_review" data-status-url="/api/status"><h2 data-job-heading></h2><p data-job-feedback></p>${review}<div data-job-output hidden><video data-job-preview></video><a data-job-download>Download</a></div></section><script type="module" src="/js/subtitles.js"></script>`;
    server = createServer(async (req, res) => {
        try {
            if (req.url === '/review') { res.setHeader('Content-Type', 'text/html'); res.end(html); return; }
            const pathname = new URL(req.url, 'http://fixture').pathname;
            const relative = pathname.startsWith('/extension/') ? 'chrome-extension/'+pathname.slice(11) : 'public'+pathname;
            if (relative.includes('..')) throw new Error('Invalid path');
            const body = await readFile(new URL(relative, root));
            res.setHeader('Content-Type', relative.endsWith('.js') ? 'text/javascript' : relative.endsWith('.css') ? 'text/css' : 'text/html');
            res.end(body);
        } catch { res.writeHead(404); res.end(); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
});
after(async () => { await browser?.close(); if (server) await new Promise(resolve => server.close(resolve)); });

async function reviewPage() {
    const page = await browser.newPage();
    let approved = false, calls = [];
    await page.route('**/api/**', async route => {
        const path = new URL(route.request().url()).pathname;
        let body;
        if (path === '/api/review') body = { editable: true, revision: 1, words: [{ start: 0, end: 1, original: 'Every', text: 'Every' }] };
        else if (path === '/api/approve') { calls.push(route.request().postDataJSON()); approved = true; body = { status: 'approved', revision: 1 }; }
        else if (path === '/api/status') body = { status: approved ? 'completed' : 'awaiting_review', preview_url: '/preview.mp4', download_url: '/download.mp4' };
        else { await route.fulfill({ status: 404 }); return; }
        await route.fulfill({ json: body });
    });
    await page.goto(origin+'/review');
    await page.locator('[data-caption-word="0"]').waitFor();
    return { page, calls };
}

test('real approval form reports missing confirmation/invalid words, then advances to output', { skip: !enabled }, async () => {
    const { page, calls } = await reviewPage();
    try {
        await page.locator('[data-review-approve]').click();
        assert.match(await page.locator('[data-review-feedback]').textContent(), /Explicitly confirm/);
        assert.equal(calls.length, 0);
        await page.locator('[data-caption-word="0"]').fill('');
        await page.locator('[data-review-confirm]').check();
        await page.locator('[data-review-approve]').click();
        assert.match(await page.locator('[data-review-feedback]').textContent(), /Correct caption word 1/);
        assert.equal(calls.length, 0);
        await page.locator('[data-caption-word="0"]').fill('EVERY');
        await page.locator('[data-review-confirm]').check();
        await page.locator('[data-review-approve]').click();
        await page.locator('[data-job-output]:not([hidden])').waitFor();
        assert.equal(calls.length, 1);
        assert.deepEqual(calls[0], { revision: 1, words: ['EVERY'], confirmed: true });
        assert.equal(await page.locator('[data-transcript-review]').isVisible(), false);
        assert.equal(await page.locator('[data-job-download]').getAttribute('href'), '/download.mp4');
    } finally { await page.close(); }
});

test('lost approval response reconciles server state without another approval', { skip: !enabled }, async () => {
    const { page, calls } = await reviewPage();
    try {
        await page.route('**/api/approve', async route => { calls.push(route.request().postDataJSON()); await route.abort(); });
        await page.route('**/api/status', route => route.fulfill({ json: { status: 'completed', preview_url: '/preview.mp4', download_url: '/download.mp4' } }));
        await page.locator('[data-review-confirm]').check();
        await page.locator('[data-review-approve]').click();
        await page.locator('[data-job-output]:not([hidden])').waitFor();
        assert.equal(calls.length, 1);
    } finally { await page.close(); }
});

test('stored prompt no longer hides reconnect after app-site permission is lost', { skip: !enabled }, async () => {
    const page = await browser.newPage();
    try {
        const parsed = parsePrompt('A complete prompt remains unchanged.');
        await page.addInitScript(({ origin, chunks }) => {
            const state = { connectedAppOrigins: [origin], activePrompt: { id: 'saved', title: 'Preserved prompt', sourceOrigin: origin, nextIndex: 0, chunks } };
            let permission = false;
            window.__fixture = { state, injections: [] };
            window.chrome = {
                tabs: { query: async () => [{ id: 17, url: origin+'/subtitles', title: 'VeoPrompter Subtitle Studio' }] },
                storage: { local: { get: async () => structuredClone(state), set: async value => Object.assign(state, structuredClone(value)) } },
                permissions: { contains: async () => permission, request: async () => (permission = true) },
                scripting: { getRegisteredContentScripts: async () => [], registerContentScripts: async () => {}, executeScript: async value => { window.__fixture.injections.push(value.files); return []; } },
            };
        }, { origin, chunks: parsed.chunks });
        await page.goto(origin+'/extension/popup.html');
        await page.locator('[data-connect-site]:not([hidden])').waitFor();
        assert.equal(await page.locator('[data-prompt-title]').textContent(), 'Preserved prompt');
        await page.locator('[data-connect-site]').click();
        await page.locator('[data-status][data-kind="success"]').waitFor();
        assert.equal(await page.locator('[data-connect-site]').isVisible(), false);
        const state = await page.evaluate(() => window.__fixture);
        assert.equal(state.state.activePrompt.id, 'saved');
        assert.equal(state.state.activePrompt.nextIndex, 0);
        assert.deepEqual(state.injections, [['app-bridge.js']]);
        assert.equal(await page.locator('[data-main-action]').isEnabled(), true);
    } finally { await page.close(); }
});

test('approval validation error keeps review editable and a corrected retry can complete', { skip: !enabled }, async () => {
    const { page, calls } = await reviewPage();
    try {
        let first = true;
        await page.route('**/api/approve', async route => {
            if (!first) { await route.fallback(); return; }
            first = false;
            await route.fulfill({ status: 422, json: { errors: { words: ['Correct the caption wording before approving.'] } } });
        });
        await page.locator('[data-review-confirm]').check();
        await page.locator('[data-review-approve]').click();
        await page.waitForFunction(() => document.querySelector('[data-review-feedback]').textContent.includes('Correct the caption wording'));
        assert.equal(await page.locator('[data-transcript-review]').isVisible(), true);
        assert.equal(await page.locator('[data-review-approve]').isEnabled(), true);
        await page.locator('[data-caption-word="0"]').fill('Corrected');
        await page.locator('[data-review-confirm]').check();
        await page.locator('[data-review-approve]').click();
        await page.locator('[data-job-output]:not([hidden])').waitFor();
        assert.equal(calls.length, 1);
        assert.equal(calls[0].words[0], 'Corrected');
    } finally { await page.close(); }
});
