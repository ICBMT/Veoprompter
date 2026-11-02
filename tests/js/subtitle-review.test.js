import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const source = await readFile(new URL('../../public/js/subtitle-review.js', import.meta.url), 'utf8');
const { initTranscriptReview } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);
globalThis.location = { origin: 'https://studio.example' };
class Element {
    constructor(doc) { this.ownerDocument = doc; this.children = []; this.dataset = {}; this.listeners = {}; this.checked = false; this.disabled = false; this.textContent = ''; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    setAttribute() {}
    addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
    async emit(name, event = {}) { await Promise.all((this.listeners[name] || []).map(fn => fn({ preventDefault() {}, ...event }))); await new Promise(resolve => setImmediate(resolve)); }
    querySelectorAll(name) { return this.children.flatMap(child => [...(child.tagName === name ? [child] : []), ...child.querySelectorAll(name)]); }
    play() { return Promise.resolve(); }
    pause() { this.paused = true; }
    set innerHTML(_) { throw new Error('Unsafe HTML insertion'); }
}
function setup(count = 3, options = {}) {
    const doc = { createElement(tagName) { const node = new Element(doc); node.tagName = tagName; return node; }, querySelector() { return { content: 'csrf' }; } };
    const panel = new Element(doc), nodes = {};
    for (const name of ['review-feedback', 'transcript-form', 'review-words', 'review-video', 'review-confirm', 'review-save', 'review-approve', 'review-load', 'review-previous', 'review-next', 'review-page', 'transcript-paragraph']) nodes[name] = new Element(doc);
    panel.querySelector = selector => nodes[selector.slice(6, -1)];
    panel.dataset = { reviewUrl: '/transcript', saveUrl: '/draft', approveUrl: '/approve', originalUrl: '/original' };
    const calls = [], approvals = [];
    const words = Array.from({ length: count }, (_, i) => ({ start: i, end: i + .5, text: `Word${i}`, original: `Word${i}` }));
    const fetcher = async (url, request) => {
        calls.push({ url, ...request });
        return { ok: true, redirected: false, json: async () => structuredClone(request.method ? { status: request.method === 'POST' ? 'approved' : 'awaiting_review', revision: 2 } : { editable: true, revision: 1, words }) };
    };
    const editor = initTranscriptReview(panel, async data => approvals.push(data), { fetcher, confirmDiscard: () => false, ...options });
    return { panel, nodes, calls, approvals, editor, words };
}
test('loading a transcript never saves or approves and preserves safe plain text', async () => {
    const s = setup(); s.words[0].text = '<img src=x onerror=alert(1)>';
    await s.editor.load();
    assert.equal(s.calls.length, 1); assert.equal(s.calls[0].method, undefined);
    assert.match(s.nodes['transcript-paragraph'].textContent, /<img/);
    assert.equal(s.approvals.length, 0); assert.equal(s.nodes['review-confirm'].checked, false);
});
test('pagination retains edits and draft save cannot render', async () => {
    const s = setup(81); await s.editor.load();
    const input = s.nodes['review-words'].querySelectorAll('input')[0]; input.value = 'Corrected';
    await s.panel.emit('input', { target: input });
    await s.nodes['review-next'].emit('click'); await s.nodes['review-previous'].emit('click');
    assert.equal(s.nodes['review-words'].querySelectorAll('input')[0].value, 'Corrected');
    await s.nodes['review-save'].emit('click');
    const saved = s.calls.at(-1); assert.equal(saved.method, 'PUT');
    const body = JSON.parse(saved.body); assert.equal(body.words.length, 81); assert.equal(body.words[0], 'Corrected');
    assert.equal(body.confirmed, undefined); assert.equal(s.approvals.length, 0);
});
test('approval requires explicit confirmation and includes the latest full draft revision', async () => {
    const s = setup(); await s.editor.load();
    await s.nodes['transcript-form'].emit('submit'); assert.equal(s.calls.length, 1);
    await s.nodes['review-save'].emit('click');
    s.nodes['review-confirm'].checked = true;
    await s.nodes['transcript-form'].emit('submit');
    const approved = s.calls.at(-1); assert.equal(approved.method, 'POST');
    assert.equal(JSON.parse(approved.body).confirmed, true); assert.equal(JSON.parse(approved.body).revision, 2);
    assert.equal(s.approvals.length, 1);
});
test('changing wording clears prior checkbox confirmation; reload never silently discards edits', async () => {
    const s = setup(); await s.editor.load(); s.nodes['review-confirm'].checked = true;
    const input = s.nodes['review-words'].querySelectorAll('input')[0]; input.value = 'Changed';
    await s.panel.emit('input', { target: input }); assert.equal(s.nodes['review-confirm'].checked, false);
    await s.nodes['review-load'].emit('click'); assert.equal(s.calls.length, 1);
    await s.nodes['transcript-form'].emit('submit'); assert.equal(s.calls.length, 1);
});
test('invalid earlier-page text is shown rather than approved from another page', async () => {
    const s = setup(81); await s.editor.load();
    const input = s.nodes['review-words'].querySelectorAll('input')[0]; input.value = '';
    await s.panel.emit('input', { target: input }); await s.nodes['review-next'].emit('click');
    s.nodes['review-confirm'].checked = true; await s.nodes['transcript-form'].emit('submit');
    assert.equal(s.calls.length, 1); assert.match(s.nodes['review-feedback'].textContent, /word 1/);
    assert.match(s.nodes['review-page'].textContent, /Words 1–80/);
});
test('remote URLs and failed save requests cannot approve or erase the current draft', async () => {
    const s = setup(); s.panel.dataset.reviewUrl = 'https://evil.example/transcript'; await s.editor.load();
    assert.equal(s.calls.length, 0); assert.match(s.nodes['review-feedback'].textContent, /stay on this site/);
    const failed = setup(3, { fetcher: async (_url, request) => ({ ok: !request.method, json: async () => request.method ? { message: 'Newer draft; reload.' } : { editable: true, revision: 1, words: [{ start: 0, end: 1, text: 'Keep', original: 'Keep' }] } }) });
    await failed.editor.load(); failed.nodes['review-confirm'].checked = true;
    await failed.nodes['transcript-form'].emit('submit');
    assert.equal(failed.approvals.length, 0); assert.match(failed.nodes['review-feedback'].textContent, /Newer draft/);
    assert.equal(failed.nodes['review-words'].querySelectorAll('input')[0].value, 'Keep');
});
