import { test, expect } from 'playwright/test';
import { readFile } from 'node:fs/promises';

test.beforeEach(async ({ page }) => {
    test.skip(process.env.BROWSER_QA_SEED !== '1' || !process.env.BROWSER_PASSWORD,
        'Opt in with an isolated seeded database; see docs/build-audit.md.');
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.__qaErrors = errors;
    await page.goto('/login');
    await page.locator('[name=email]').fill('browser-qa@example.test');
    await page.locator('[name=password]').fill(process.env.BROWSER_PASSWORD);
    await page.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/$/);
});

test.afterEach(async ({ page }) => {
    expect(page.__qaErrors || []).toEqual([]);
});

test('authenticated pages, mobile layout and reduced motion', async ({ page }) => {
    for (const path of ['/', '/history', '/subtitles', '/account', '/admin', '/admin/users', '/guide']) {
        const response = await page.goto(path);
        expect(response.status(), path).toBe(200);
        await expect(page.locator('h1').first()).toBeVisible();
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    for (const path of ['/', '/projects/create', '/history', '/subtitles', '/account']) {
        await page.goto(path);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), path).toBe(true);
        await expect(page.locator('h1').first()).toBeVisible();
    }
});

async function createProject(page, contentForm) {
    await page.goto('/projects/create');
    const name = page.locator('[name=name]');
    await name.fill(`Browser audit ${contentForm} ${Date.now()}`);
    // Regression: scroll reveals must not hide navigation from the keyboard below the fold.
    await name.press('Tab');
    await expect(page.locator('[data-wizard-next]')).toBeFocused();
    for (let step = 0; step < 30; step++) {
        const current = page.locator('.wizard-step:visible');
        await expect(current).toHaveCount(1);
        const choice = current.locator(`label:has(input[name=content_form][value=${contentForm}])`);
        if (await choice.count()) await choice.click();
        if (await page.locator('[data-wizard-submit]').isVisible()) break;
        await page.locator('[data-wizard-next]').click();
    }
    await page.locator('[data-wizard-submit]').click();
    await expect(page).toHaveURL(/\/projects\/\d+$/);
    return page.url();
}

async function generate(page) {
    await page.locator('[name=topic]').fill('One cobalt wheel starts turning steadily');
    const response = page.waitForResponse(r => /\/generate$/.test(r.url()) && r.request().method() === 'POST');
    await page.locator('[data-submit-button]').click();
    const result = await response;
    expect(result.status(), result.url()).toBe(200);
    await expect(page.locator('[data-output-content]')).toContainText('COPY CLIP 1 PROMPT');
}

test('one-question wizard, short generation and long continuation/ending/sequel', async ({ page }) => {
    await createProject(page, 'short');
    await generate(page);
    await expect(page.locator('[data-story-intent=sequel]')).toBeVisible();

    await createProject(page, 'long');
    await generate(page);
    await expect(page.locator('[data-story-heading]')).toContainText('Story ongoing');
    for (const [intent, heading] of [['continue', 'Batch 2'], ['finish', 'Part complete'], ['sequel', 'Part 2']]) {
        const response = page.waitForResponse(r => /\/history\/\d+\/continue$/.test(r.url()) && r.request().method() === 'POST');
        await page.locator('[data-story-intent=' + intent + ']').click();
        const result = await response;
        expect(result.status(), result.url()).toBe(200);
        await expect(page.locator('[data-story-heading]')).toContainText(heading);
    }
    const source = page.locator('[data-story-form]');
    const runId = (await source.getAttribute('action')).match(/history\/(\d+)/)[1];
    await page.goto('/history/' + runId);
    await expect(page.locator('h1')).toBeVisible();
    await expect(page.locator('[data-story-heading]')).toContainText('Part 2');
});

test('one-time extension paste works in actual editable DOM without submitting', async ({ page }) => {
    const code = await readFile(new URL('../../chrome-extension/field-paste.js', import.meta.url), 'utf8');
    await page.route('**/__qa_field_paste.js', route => route.fulfill({ contentType: 'text/javascript', body: code }));
    await page.evaluate(async () => {
        const { armPasteOnNextField } = await import('/__qa_field_paste.js');
        const state = { activePrompt: { id: 'qa', nextIndex: 1, pendingPaste: { token: 'qa-token', index: 0, tabId: 17 } } };
        window.chrome = { storage: { local: {
            get: async () => structuredClone(state),
            set: async value => Object.assign(state, structuredClone(value)),
        } } };
        window.__qaPaste = state;
        window.__qaSubmits = 0;
        const form = document.createElement('form');
        form.addEventListener('submit', event => { event.preventDefault(); window.__qaSubmits++; });
        form.innerHTML = '<textarea id="qa-field" aria-label="QA paste field"></textarea>';
        document.querySelector('main').prepend(form);
        armPasteOnNextField('A deliberate local paste.', 'qa', 0, 'qa-token', 17);
    });
    const field = page.locator('#qa-field');
    await field.click();
    await expect(field).toHaveValue('A deliberate local paste.');
    await field.click();
    await expect(field).toHaveValue('A deliberate local paste.');
    expect(await page.evaluate(() => window.__qaPaste.activePrompt.pendingPaste)).toBeNull();
    expect(await page.evaluate(() => window.__qaSubmits)).toBe(0);
});

test('subtitle draft edits survive reload and explicit approval precedes rendering', async ({ page }) => {
    test.skip(!process.env.BROWSER_QA_MEDIA, 'Seed an optional synthetic MP4 and checked-in transcript.');
    await page.goto('/subtitles');
    await page.getByRole('link', { name: 'browser-review.mp4', exact: true }).first().click();
    const word = page.locator('[data-caption-word="0"]');
    await expect(word).toHaveValue('Every');
    await expect(page.locator('[data-job-output]')).toBeHidden();
    await word.fill('EVERY');
    await page.locator('[data-review-save]').click();
    await expect(page.locator('[data-review-feedback]')).toContainText('Rendering is still paused');
    await page.reload();
    await expect(word).toHaveValue('EVERY');
    await expect(page.locator('[data-review-confirm]')).not.toBeChecked();
    await expect(page.locator('[data-job-output]')).toBeHidden();
    await page.locator('[data-review-confirm]').check();
    const approval = page.waitForResponse(r => /\/approve$/.test(r.url()) && r.request().method() === 'POST');
    await page.locator('[data-review-approve]').click();
    expect((await approval).status()).toBe(200);
    await expect(page.locator('[data-transcript-review]')).toBeHidden();
    if (process.env.BROWSER_QA_RENDER === '1') {
        await expect(page.locator('[data-job-download]')).toBeVisible({ timeout: 45_000 });
        const download = await page.request.get(await page.locator('[data-job-download]').getAttribute('href'));
        expect(download.status()).toBe(200);
        expect(download.headers()['content-type']).toContain('video/mp4');
        expect((await download.body()).length).toBeGreaterThan(1000);
    }
});

test('a failed repeat generation retains the good kit, rejects duplicate submits and can recover', async ({ page }) => {
    await createProject(page, 'short');
    await generate(page);
    const output = page.locator('[data-output-content]');
    await expect(page.locator('[data-submit-button]')).toBeEnabled();
    const previous = await output.textContent();
    expect(previous).toContain('COPY CLIP 1 PROMPT');
    let calls = 0;
    let finish;
    let entered;
    const pending = new Promise(resolve => { entered = resolve; });
    await page.route('**/projects/*/generate', async route => {
        calls++;
        await new Promise(resolve => { finish = resolve; entered(); });
        await route.fulfill({ status: 502, contentType: 'text/html', body: '<h1>Bad Gateway</h1>' });
    });
    await page.locator('[data-submit-button]').evaluate(button => {
        button.form.requestSubmit();
        button.form.requestSubmit();
    });
    await pending;
    expect(calls).toBe(1);
    await expect(page.locator('[data-submit-button]')).toBeDisabled();
    expect(await output.textContent()).toBe(previous);
    finish();
    await expect(page.locator('[data-generation-message]')).toBeVisible();
    await expect(page.locator('[data-generation-message]')).toContainText('HTTP 502');
    await expect(page.locator('[data-submit-button]')).toBeEnabled();
    await expect(page.locator('[data-results]')).toBeVisible();
    expect(await page.locator('[data-results]').evaluate(e => e.inert)).toBe(false);
    expect(await output.textContent()).toBe(previous);
    await page.unroute('**/projects/*/generate');
    for (const failure of ['empty', 'provider', 'network']) {
        await page.route('**/projects/*/generate', route => failure === 'network'
            ? route.abort('failed')
            : route.fulfill({
                status: failure === 'provider' ? 502 : 200,
                contentType: 'application/json',
                body: JSON.stringify(failure === 'provider' ? { message: 'Provider quota exceeded.' } : { content: '' }),
            }));
        await page.locator('[data-submit-button]').click();
        await expect(page.locator('[data-generation-message]')).toBeVisible();
        await expect(page.locator('[data-submit-button]')).toBeEnabled();
        expect(await output.textContent()).toBe(previous);
        expect(await page.locator('[data-results]').evaluate(e => e.inert)).toBe(false);
        if (failure === 'provider') await expect(page.locator('[data-generation-message]')).toContainText('Provider quota exceeded.');
        await page.unroute('**/projects/*/generate');
    }
    await generate(page);
    await expect(page.locator('[data-generation-message]')).toBeHidden();
});

test('xKiro preset clears unrelated credentials and loads exact vendor model IDs with tier labels', async ({ page }) => {
    await page.goto('/admin');
    await page.locator('#api_key').fill('unrelated-provider-secret');
    await page.locator('[data-use-xkiro]').click();
    await expect(page.locator('[data-provider-select]')).toHaveValue('openai');
    await expect(page.locator('#base_url')).toHaveValue('https://api.xkiro.com/v1');
    await expect(page.locator('#api_key')).toHaveValue('');
    await expect(page.locator('[data-model-input]')).toHaveValue('');
    await page.route('**/admin/models', async route => {
        const body = route.request().postData();
        expect(body).toContain('https://api.xkiro.com/v1');
        expect(body).not.toContain('unrelated-provider-secret');
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ models: [
            { id: 'vendor/free-model', label: 'vendor/free-model — Free tier' },
            { id: 'vendor/paid-model', label: 'vendor/paid-model — Paid tier' },
        ] }) });
    });
    await page.locator('#api_key').fill('fake-xkiro-key');
    await page.locator('[data-load-models]').click();
    await expect(page.locator('[data-model-input]')).toHaveValue('vendor/free-model');
    await expect(page.locator('[data-model-input] option').first()).toHaveText('vendor/free-model — Free tier');
    await page.locator('[data-toggle-manual-model]').click();
    await page.locator('#manual_model').fill('another-vendor/model:free');
    await page.locator('[data-toggle-manual-model]').click();
    await expect(page.locator('[data-model-input]')).toHaveValue('another-vendor/model:free');
    await expect(page.locator('[data-model-feedback]')).toContainText('models found');
});

test('continuation works without randomUUID for short and long forms and retains IDs on retry', async ({ page }) => {
    await page.addInitScript(() => Object.defineProperty(globalThis.crypto, 'randomUUID', { value: undefined, configurable: true }));
    for (const form of ['short', 'long']) {
        await createProject(page, form);
        await generate(page);
        const requestId = page.locator('[data-story-form] [name=request_id]');
        const oldId = await requestId.inputValue();
        await page.locator('[data-story-form] [name=direction]').fill('Keep the same characters and continue from the saved ending.');
        const editedId = await requestId.inputValue();
        expect(editedId).not.toBe(oldId);
        expect(editedId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
        const intent = form === 'short' ? 'sequel' : 'continue';
        await page.route('**/history/*/continue', route => route.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({
            message: 'The source is unchanged.', code: 'continuation_opening_mismatch',
            opening_issues: [{ field: 'OPENING PRESENTATION STATE', reason: 'does not match the saved ending' }],
        }) }));
        await page.locator(`[data-story-intent=${intent}]`).click();
        await expect(page.locator('[data-story-message]')).toContainText('OPENING PRESENTATION STATE: does not match');
        await expect(page.locator(`[data-story-intent=${intent}]`)).toBeEnabled();
        expect(await requestId.inputValue()).toBe(editedId);
        await page.unroute('**/history/*/continue');
        const response = page.waitForResponse(r => /\/history\/\d+\/continue$/.test(r.url()) && r.request().method() === 'POST');
        await page.locator(`[data-story-intent=${intent}]`).click();
        expect((await response).status()).toBe(200);
        await expect(page.locator('[data-story-heading]')).toContainText(form === 'short' ? 'Part 2' : 'Batch 2');
    }
});
