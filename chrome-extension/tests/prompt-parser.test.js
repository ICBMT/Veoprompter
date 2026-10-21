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

test('keeps variable clip counts in order for the extension', () => {
    const clipCount = 6;
    const clipPrompts = Array.from({ length: clipCount }, (_, index) => `COPY CLIP ${index + 1} PROMPT > Clip ${index + 1} prompt.`);
    const parsed = parsePrompt([
        'SECTION 1 — PROJECT OVERVIEW',
        'A six-part sequence.',
        'SECTION 8 — FOUR COPY-AND-PASTE VIDEO PROMPTS',
        ...clipPrompts,
        'SECTION 9 — EXACT HANDOFF SPECIFICATION',
        'Final handoff details.',
        'SECTION 10 — FINAL CONTINUITY AUDIT',
        'Continuity remains stable.',
    ].join('\n'));

    assert.equal(parsed.format, 'clip-sequence');
    assert.deepEqual(parsed.chunks.slice(1).map((chunk) => chunk.clipNumber), [1, 2, 3, 4, 5, 6]);
    assert.match(parsed.chunks[6].text, /SECTION 9 — EXACT HANDOFF SPECIFICATION/);
    assert.match(parsed.chunks[6].text, /SECTION 10 — FINAL CONTINUITY AUDIT/);
});

test('splits section-per-clip v2 output and supports AI-selected counts above eight', () => {
    const clipCount = 10;
    const lines = [
        'SECTION 1 — PROJECT OVERVIEW',
        'AI-selected count: 10.',
        'SECTION 7 — CONTINUITY MAP',
        'Each ending connects to the next opening.',
    ];

    for (let clip = 1; clip <= clipCount; clip += 1) {
        const transitionLine = clip === 1
            ? 'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF'
            : clip === 6
                ? 'TRANSITION TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION'
                : 'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION';
        lines.push(
            `SECTION ${7 + clip} — COPY CLIP ${clip} PROMPT`,
            '```text',
            transitionLine,
            `Clip ${clip}: a self-contained prompt with a ${clip === 1 ? 3 : 6}-second action.`,
            '```',
        );
    }

    lines.push('SECTION 9+ — FINAL CONTINUITY AUDIT', 'All ten handoffs are coherent.');
    const parsed = parsePrompt(lines.join('\n'));

    assert.equal(parsed.format, 'clip-sequence');
    assert.equal(parsed.chunks.length, clipCount + 1);
    assert.match(parsed.chunks[0].text, /SECTION 7 — CONTINUITY MAP/);
    assert.doesNotMatch(parsed.chunks[0].text, /SECTION 8/);
    assert.deepEqual(parsed.chunks.slice(1).map((chunk) => chunk.clipNumber), Array.from({ length: clipCount }, (_, index) => index + 1));
    assert.match(parsed.chunks[1].text, /Clip 1: a self-contained prompt with a 3-second action/);
    assert.match(parsed.chunks[10].text, /Clip 10: a self-contained prompt/);
    assert.equal(parsed.chunks[6].transitionType, 'C');
    assert.equal(parsed.chunks[6].label, 'Transition clip 6 · Type C');
    assert.match(parsed.chunks[10].text, /SECTION 9\+ — FINAL CONTINUITY AUDIT/);
    assert.match(parsed.chunks[10].text, /All ten handoffs are coherent/);
    assert.match(parsed.notice, /10 clip prompts separated/);
});

test('classifies Type A, Type B, and Type C clips for the extension handoff', () => {
    const parsed = parsePrompt([
        'SECTION 1 — PROJECT OVERVIEW',
        'A short story with a deliberate time transition.',
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'The character plants a seed.',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION',
        'Continue this exact scene from the frame shown. The character waters the seed.',
        'COPY CLIP 3 PROMPT',
        'TRANSITION TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'The character carries a watering can through the garden gate.',
        'COPY TRANSITION CLIP 4 PROMPT',
        'TRANSITION TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION',
        'Before: a young plant. Transformation: seasons pass in a purposeful time-lapse. After: a mature tree.',
        'SECTION 9 — EXACT HANDOFF SPECIFICATION',
        'The mature tree is the canonical state for the next clip.',
    ].join('\n'));

    assert.equal(parsed.format, 'clip-sequence');
    assert.deepEqual(parsed.chunks.slice(1).map((chunk) => chunk.transitionType), [null, 'A', 'B', 'C']);
    assert.equal(parsed.chunks[2].label, 'Clip 2 · Type A');
    assert.equal(parsed.chunks[3].label, 'Clip 3 · Type B');
    assert.equal(parsed.chunks[4].label, 'Transition clip 4 · Type C · final handoff');
    assert.match(parsed.chunks[4].transitionLabel, /narrative \/ temporal transition/);
    assert.doesNotMatch(parsed.chunks[4].text, /Continue this exact scene/);
    assert.match(parsed.chunks[4].text, /Before: a young plant/);
    assert.match(parsed.chunks[4].text, /SECTION 9 — EXACT HANDOFF SPECIFICATION/);
    assert.match(parsed.notice, /1 dedicated Type C transition clip identified/);
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
