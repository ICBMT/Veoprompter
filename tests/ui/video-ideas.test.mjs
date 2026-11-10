// BROWSER_EXECUTABLE=/path/to/chromium node --test tests/ui/video-ideas.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

test('real browser prefill preserves manual answers/preferences and waits for explicit generation', { skip: !process.env.BROWSER_EXECUTABLE }, async () => {
    const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    try {
        const page = await browser.newPage();
        const script = await readFile(new URL('../../public/js/idea-prefill.js', import.meta.url), 'utf8');
        const draft = JSON.stringify({ title: 'An energy idea', answers: { topic: 'AI topic', learning_objective: 'Understand a turbine', creative_direction: '<b>Plain editable text</b>', character_idea: 'One cobalt wheel' } }).replaceAll('<', '\\u003c');
        await page.route('http://studio.test/**', route => {
            if (route.request().url().endsWith('/js/idea-prefill.js')) return route.fulfill({ contentType: 'text/javascript', body: script });
            return route.fulfill({ contentType: 'text/html', body: `<form id="prompt-form"><input name="project_title"><input name="topic" value="My manual topic"><textarea name="learning_objective"></textarea><textarea name="creative_direction"></textarea><input name="character_idea"><select name="video_type"><option value="documentary">Documentary</option></select><input name="clip_duration" value="auto"><button>Generate</button></form><script>window.submits=0;document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.submits++})</script><script type="application/json" data-idea-prefill>${draft}</script><script type="module" src="/js/idea-prefill.js"></script>` });
        });
        await page.goto('http://studio.test/review');
        await page.waitForFunction(() => document.querySelector('[name=project_title]').value === 'An energy idea');
        assert.equal(await page.locator('[name=topic]').inputValue(), 'My manual topic');
        assert.equal(await page.locator('[name=creative_direction]').inputValue(), '<b>Plain editable text</b>');
        assert.equal(await page.locator('b').count(), 0);
        assert.equal(await page.locator('[name=video_type]').inputValue(), 'documentary');
        assert.equal(await page.locator('[name=clip_duration]').inputValue(), 'auto');
        assert.equal(await page.evaluate(() => window.submits), 0);
        await page.locator('[name=topic]').fill('Edited by the user');
        await page.getByRole('button', { name: 'Generate' }).click();
        assert.equal(await page.evaluate(() => window.submits), 1);
        assert.equal(await page.locator('[name=topic]').inputValue(), 'Edited by the user');
    } finally { await browser.close(); }
});

test('extension exposes a connected-site ideas shortcut without a stored prompt or network action', { skip: !process.env.BROWSER_EXECUTABLE }, async () => {
    const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE, args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    try {
        const page = await browser.newPage();
        const requests = [];
        await page.addInitScript(() => {
            const state = { connectedAppOrigins: ['http://studio.test'], activePrompt: null };
            window.chrome = { tabs: { query: async () => [{ id: 17, url: 'https://labs.google/fx/tools/flow', title: 'Flow' }] },
                storage: { local: { get: async () => structuredClone(state) } }, permissions: { contains: async () => false } };
        });
        await page.route('http://studio.test/**', async route => {
            const path = new URL(route.request().url()).pathname;
            requests.push(path);
            if (!/^\/extension\/[a-z-]+\.(?:html|css|js)$/.test(path)) return route.fulfill({ status: 404 });
            const file = path.slice('/extension/'.length);
            return route.fulfill({ contentType: file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'text/html', body: await readFile(new URL('../../chrome-extension/'+file, import.meta.url), 'utf8') });
        });
        await page.goto('http://studio.test/extension/popup.html');
        await page.locator('[data-ideas-shortcut]:not([hidden])').waitFor();
        assert.equal(await page.locator('[data-ideas-link]').getAttribute('href'), 'http://studio.test/ideas');
        assert.equal(await page.locator('[data-main-action]').isDisabled(), true);
        assert.equal(requests.includes('/ideas'), false);
    } finally { await browser.close(); }
});
