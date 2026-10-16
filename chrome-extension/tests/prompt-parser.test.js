import assert from 'node:assert/strict';
import test from 'node:test';
import { parsePrompt } from '../prompt-parser.js';

const promptWithFourClips = [
    '**SECTION 1 — PROJECT OVERVIEW**',
    '- Educational Title: A Rainy Garden',
    '',
    '**SECTION 7 — CLIP PLAN AND CONTINUITY MAP**',
    '- Clip 1: Scan a seed.',
    '',
    '**SECTION 8 — FOUR COPY-AND-PASTE VIDEO PROMPTS**',
    '',
    'COPY CLIP 1 PROMPT &gt; Cinematic 3D animation, 8 seconds. Chip scans a seed.',
    '',
    'COPY CLIP 2 PROMPT >',
    '```text',
    'Cinematic 3D animation, 8 seconds. Continue the same scene as Chip waters the seed.',
    '```',
    '',
    '### COPY CLIP 3 PROMPT',
    '```text',
    'Cinematic 3D animation, 8 seconds. A green shoot appears beside Chip.',
    '```',
    '',
    'COPY CLIP 4 PROMPT > Chip finishes the scan and smiles at the camera.',
    '',
    '---',
    '',
    '**SECTION 9 — EXACT HANDOFF SPECIFICATION**',
    '- Clip 2 to 3: the water drop is still falling.',
    '',
    '**SECTION 10 — FINAL CONTINUITY AUDIT**',
    '- Chip remains white and cyan.',
].join('\n');

test('separates Sections 1–7 and any number of clip prompts, appending handoff notes to the last clip', () => {
    const parsed = parsePrompt(promptWithFourClips);

    assert.equal(parsed.format, 'clip-sequence');
    assert.equal(parsed.chunks.length, 5);
    assert.equal(parsed.chunks[0].kind, 'context');
    assert.match(parsed.chunks[0].text, /SECTION 7/);
    assert.doesNotMatch(parsed.chunks[0].text, /SECTION 8/);
    assert.deepEqual(parsed.chunks.slice(1).map((chunk) => chunk.clipNumber), [1, 2, 3, 4]);
    assert.equal(parsed.chunks[1].text, 'Cinematic 3D animation, 8 seconds. Chip scans a seed.');
    assert.equal(parsed.chunks[2].text, 'Cinematic 3D animation, 8 seconds. Continue the same scene as Chip waters the seed.');
    assert.match(parsed.chunks[3].text, /A green shoot appears beside Chip\./);
    assert.match(parsed.chunks[4].text, /Chip finishes the scan and smiles at the camera\./);
    assert.match(parsed.chunks[4].text, /SECTION 9 — EXACT HANDOFF SPECIFICATION/);
    assert.match(parsed.chunks[4].text, /SECTION 10 — FINAL CONTINUITY AUDIT/);
    assert.match(parsed.notice, /4 clip prompts separated/);
});

test('keeps a one-clip result copy-ready and includes trailing continuity sections', () => {
    const parsed = parsePrompt([
        'SECTION 1 — PROJECT OVERVIEW',
        'The idea.',
        'SECTION 8 — ONE COPY-AND-PASTE VIDEO PROMPT',
        'COPY CLIP 1 PROMPT > One complete prompt.',
        'SECTION 9 — EXACT HANDOFF SPECIFICATION',
        'Final handoff details.',
    ].join('\n'));

    assert.equal(parsed.chunks.length, 2);
    assert.equal(parsed.chunks[1].label, 'Clip 1 · final handoff');
    assert.match(parsed.chunks[1].text, /One complete prompt\./);
    assert.match(parsed.chunks[1].text, /Final handoff details\./);
});

test('extracts the self-contained copy block from an extension prompt', () => {
    const parsed = parsePrompt([
        'Visible starting state: a robot is at a workbench.',
        '',
        '### COPY EXTENSION VIDEO PROMPT',
        '```text',
        'Continue the exact scene from the shown frame. Keep the robot and lighting unchanged.',
        '```',
        '',
        'Continuity checklist: keep the same camera.',
    ].join('\n'));

    assert.equal(parsed.format, 'extension-prompt');
    assert.equal(parsed.chunks.length, 1);
    assert.equal(parsed.chunks[0].text, 'Continue the exact scene from the shown frame. Keep the robot and lighting unchanged.');
});

test('falls back to one complete copy when the generated output is not in the recognized format', () => {
    const raw = 'A complete single prompt with no numbered clip sections.';
    const parsed = parsePrompt(raw);

    assert.equal(parsed.format, 'complete-prompt');
    assert.equal(parsed.chunks.length, 1);
    assert.equal(parsed.chunks[0].text, raw);
    assert.match(parsed.notice, /complete prompt will be copied as one part/);
});

test('reports empty input without throwing', () => {
    const parsed = parsePrompt('  \n  ');
    assert.equal(parsed.chunks[0].text, '');
    assert.match(parsed.notice, /prompt is empty/);
});
