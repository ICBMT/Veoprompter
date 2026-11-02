import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const code = fs.readFileSync(new URL('../../public/js/story-continuation.js', import.meta.url), 'utf8');
function harness(fetchImpl = async () => ({ ok: true, json: async () => ({ url: '/history/42' }) })) {
    const handlers = {}; const messages = { textContent: '' }; const headings = { textContent: '' };
    const controls = ['continue', 'finish', 'sequel'].map(value => ({ value, disabled: false, hidden: false }));
    const elements = { direction: { value: 'Follow the same wheel.' }, allow_new_subjects: { checked: false }, request_id: { value: 'original-request-id' } };
    let valid = true; let requests = 0; let redirected = null;
    const form = {
        action: '/history/1/continue', dataset: {}, elements,
        reportValidity: () => valid,
        setAttribute() {}, removeAttribute() {},
        querySelector: () => messages,
        querySelectorAll: () => controls,
        closest: () => form,
    };
    const panel = {
        hidden: true,
        querySelector: (query) => query.includes('heading') ? headings : form,
        querySelectorAll: () => controls,
    };
    class Data {
        constructor() { this.fields = new Map(Object.entries(elements).map(([key, value]) => [key, value.value])); }
        set(key, value) { this.fields.set(key, value); }
        get(key) { return this.fields.get(key); }
    }
    const location = { origin: 'https://studio.example', assign: (value) => { redirected = value; } };
    vm.runInNewContext(code, {
        document: {
            addEventListener: (key, fn) => { handlers[key] = fn; },
            querySelector: (query) => query.startsWith('meta') ? { content: 'csrf-only' } : panel,
        },
        FormData: Data, URL, location, crypto: { randomUUID: () => 'fresh-request-id' },
        fetch: async (...args) => { requests++; return fetchImpl(...args); },
    });
    return {
        handlers, form, panel, messages, headings, controls, elements,
        setValid: (value) => { valid = value; },
        submit: (intent = 'continue') => handlers.submit({ target: form, submitter: { value: intent }, preventDefault() {} }),
        get requests() { return requests; }, get redirected() { return redirected; },
    };
}

test('Studio enables only the state-appropriate controls and hides stale kits', () => {
    const h = harness();
    h.handlers['veoprompter:run-ready']({ detail: { run: { continuation_url: '/history/2/continue', story_status: 'open' } } });
    assert.equal(h.panel.hidden, false); assert.equal(h.form.action, '/history/2/continue');
    assert.deepEqual(h.controls.map(c => c.hidden), [false, false, true]);
    h.handlers['veoprompter:run-ready']({ detail: { run: { continuation_url: '/history/3/continue', story_status: 'ended' } } });
    assert.deepEqual(h.controls.map(c => c.hidden), [true, true, false]);
    h.handlers['veoprompter:run-ready']({ detail: {} }); assert.equal(h.panel.hidden, true);
});

test('a continuation posts only to this site with CSRF and opens the saved new kit', async () => {
    const h = harness(async (url, options) => {
        assert.equal(url.origin, 'https://studio.example');
        assert.equal(options.method, 'POST'); assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'csrf-only');
        assert.equal(options.body.get('intent'), 'finish');
        assert.equal(options.body.get('direction'), 'Follow the same wheel.');
        assert.ok(h.controls.every(c => c.disabled));
        return { ok: true, json: async () => ({ url: '/history/42' }) };
    });
    await h.submit('finish');
    assert.equal(h.redirected, 'https://studio.example/history/42');
    assert.ok(h.controls.every(c => !c.disabled));
});

test('validation and duplicate-submit guard avoid unnecessary requests', async () => {
    let complete;
    const h = harness(() => new Promise(resolve => { complete = resolve; }));
    h.setValid(false); await h.submit(); assert.equal(h.requests, 0);
    h.setValid(true); const pending = h.submit(); await h.submit(); assert.equal(h.requests, 1);
    complete({ ok: true, json: async () => ({ url: '/history/4' }) }); await pending;
});

test('failure keeps request ID for idempotent retry and renders server errors as text', async () => {
    const h = harness(async () => ({ ok: false, json: async () => ({ message: '<img src=x onerror=alert(1)> source unchanged' }) }));
    await h.submit();
    assert.equal(h.elements.request_id.value, 'original-request-id');
    assert.equal(h.messages.textContent, '<img src=x onerror=alert(1)> source unchanged');
    assert.equal(h.redirected, null); assert.equal(h.form.dataset.busy, 'false');
});

test('editing direction creates a fresh operation ID, but not while a request is pending', () => {
    const h = harness();
    const target = { name: 'direction', closest: () => h.form };
    h.handlers.input({ target }); assert.equal(h.elements.request_id.value, 'fresh-request-id');
    h.elements.request_id.value = 'pending-id'; h.form.dataset.busy = 'true';
    h.handlers.input({ target }); assert.equal(h.elements.request_id.value, 'pending-id');
});

test('cross-origin endpoints and redirect URLs are refused', async () => {
    const h = harness(); h.form.action = 'https://other.example/continue'; await h.submit();
    assert.equal(h.requests, 0); assert.equal(h.redirected, null);
    const redirect = harness(async () => ({ ok: true, json: async () => ({ url: 'https://other.example/history/1' }) }));
    await redirect.submit(); assert.equal(redirect.redirected, null); assert.match(redirect.messages.textContent, /on this site/);
});
