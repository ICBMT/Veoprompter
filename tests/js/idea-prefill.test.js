import test from 'node:test';
import assert from 'node:assert/strict';
import { applyIdeaPrefill } from '../../public/js/idea-prefill.js';
import { videoIdeasLink } from '../../chrome-extension/ideas-link.js';

test('idea drafts fill only known empty creative fields and never submit or change preferences', () => {
    const changes = [];
    const fields = Object.fromEntries(['project_title', 'topic', 'learning_objective', 'creative_direction', 'character_idea', 'video_type', 'clip_duration'].map(name => [name, { value: '', dispatchEvent: () => changes.push(name) }]));
    fields.topic.value = 'User-written topic'; fields.video_type.value = 'documentary'; fields.clip_duration.value = 'auto';
    const form = { elements: { namedItem: name => fields[name] }, submit() { throw new Error('No automatic submit'); } };
    assert.equal(applyIdeaPrefill(form, { title: '<script>not HTML</script>', answers: { topic: 'Suggested topic', creative_direction: 'A grounded research angle', learning_objective: '', character_idea: 'An object', clip_duration: 99 } }, () => ({})), true);
    assert.equal(fields.topic.value, 'User-written topic');
    assert.equal(fields.project_title.value, '<script>not HTML</script>');
    assert.equal(fields.creative_direction.value, 'A grounded research angle');
    assert.equal(fields.video_type.value, 'documentary'); assert.equal(fields.clip_duration.value, 'auto');
    assert.ok(!changes.includes('topic'));
});
test('missing or malformed optional drafts leave the generator usable', () => {
    assert.equal(applyIdeaPrefill(null, {}), false);
    assert.equal(applyIdeaPrefill({}, null), false);
    assert.equal(applyIdeaPrefill({}, { answers: 'not an object' }), false);
    assert.equal(applyIdeaPrefill({}, { answers: null }), false);
    assert.equal(applyIdeaPrefill({}, { answers: [] }), false);
});
test('extension ideas shortcut uses only an explicitly connected HTTP(S) origin', () => {
    assert.equal(videoIdeasLink('https://studio.example', ['https://studio.example']), 'https://studio.example/ideas');
    for (const origin of ['https://evil.example', 'javascript:alert(1)', 'https://studio.example/path', 'https://user:pass@studio.example', 'file:///tmp/data']) {
        assert.equal(videoIdeasLink(origin, ['https://studio.example']), null);
    }
});
