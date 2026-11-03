import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../public/js/story-continuation.js', import.meta.url), 'utf8');
const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

function fixture(crypto, fetch = async () => ({ ok: false, status: 502, json: async () => ({ message: 'Provider failed.' }) })) {
    const handlers = {};
    const message = { textContent: '' };
    const controls = [{ disabled: false }, { disabled: true }];
    const form = { dataset: {}, action: '/history/1/continue', elements: { request_id: { value: 'server-issued-uuid' }, direction: { value: 'Continue' }, allow_new_subjects: { checked: false } },
        querySelector: () => message, querySelectorAll: () => controls, reportValidity: () => true, setAttribute() {}, removeAttribute() {} };
    const panel = { hidden: false, querySelector: selector => selector === '[data-story-form]' ? form : selector === '[data-story-message]' ? message : {}, querySelectorAll: () => [] };
    const calls = [];
    const context = { crypto, Uint8Array, URL, location: { origin: 'http://example.test', assign() {} },
        FormData: class { set() {} },
        document: { addEventListener: (type, handler) => { handlers[type] = handler; }, querySelector: selector => selector.includes('data-results') ? panel : null },
        fetch: async (...args) => { calls.push(args); return fetch(...args); } };
    vm.runInNewContext(source, context);
    return { form, message, controls, calls,
        input: () => handlers.input({ target: { closest: () => form, name: 'direction' } }),
        ready: () => handlers['veoprompter:run-ready']({ detail: { run: { continuation_url: '/history/2/continue', story_status: 'ended' } } }),
        submit: (intent = 'sequel') => handlers.submit({ target: { closest: () => form }, preventDefault() {}, submitter: { value: intent } }) };
}

function insecureOriginCrypto() {
    let n = 0;
    return { getRandomValues: bytes => { bytes.fill(++n); return bytes; } };
}

test('HTTP-origin UUID fallback produces distinct RFC4122 v4 IDs on run-ready and input', () => {
    const f = fixture(insecureOriginCrypto());
    f.ready();
    const first = f.form.elements.request_id.value;
    assert.match(first, uuidPattern);
    f.input();
    assert.match(f.form.elements.request_id.value, uuidPattern);
    assert.notEqual(f.form.elements.request_id.value, first);
});

test('native randomUUID is used when supported', () => {
    const f = fixture({ randomUUID: () => 'native-uuid' });
    f.input();
    assert.equal(f.form.elements.request_id.value, 'native-uuid');
});

test('an exposed but throwing randomUUID falls back safely', () => {
    const f = fixture({ ...insecureOriginCrypto(), randomUUID() { throw new Error('insecure context'); } });
    f.input();
    assert.match(f.form.elements.request_id.value, uuidPattern);
});

test('missing cryptography reports a visible error and blocks changed requests, never throws', async () => {
    const f = fixture(undefined);
    f.input();
    assert.match(f.message.textContent, /secure request ID/);
    await f.submit();
    assert.equal(f.calls.length, 0);
});

test('failed retries retain request IDs, changed intent rotates IDs, and disabled controls stay disabled', async () => {
    const f = fixture(insecureOriginCrypto());
    f.input();
    const id = f.form.elements.request_id.value;
    await f.submit();
    await f.submit();
    assert.equal(f.form.elements.request_id.value, id);
    assert.equal(f.calls.length, 2);
    assert.match(f.message.textContent, /HTTP 502/);
    assert.equal(f.controls[0].disabled, false);
    assert.equal(f.controls[1].disabled, true);
    await f.submit('finish');
    assert.notEqual(f.form.elements.request_id.value, id);
});

test('opening field diagnostics are visible without losing retry identity', async () => {
    const f = fixture(insecureOriginCrypto(), async () => ({ ok: false, status: 502, json: async () => ({ message: 'Opening rejected.', opening_issues: [{ field: 'OPENING PRESENTATION STATE', reason: 'does not match the saved ending' }] }) }));
    await f.submit();
    assert.match(f.message.textContent, /OPENING PRESENTATION STATE: does not match/);
    assert.equal(f.form.elements.request_id.value, 'server-issued-uuid');
});
