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
        'OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION',
        'Continue this exact scene from the frame shown. The character waters the seed.',
        'OUTGOING HANDOFF TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'COPY CLIP 3 PROMPT',
        'TRANSITION TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'The character carries a watering can through the garden gate.',
        'OUTGOING HANDOFF TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION',
        'COPY TRANSITION CLIP 4 PROMPT',
        'TRANSITION TYPE: TYPE C — NARRATIVE / TEMPORAL TRANSITION',
        'Before: a young plant. Transformation: seasons pass in a purposeful time-lapse. After: a mature tree.',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
        'SECTION 9 — EXACT HANDOFF SPECIFICATION',
        'The mature tree is the canonical state for the next clip.',
    ].join('\n'));

    assert.equal(parsed.format, 'clip-sequence');
    assert.deepEqual(parsed.chunks.slice(1).map((chunk) => chunk.transitionType), [null, 'A', 'B', 'C']);
    assert.deepEqual(parsed.chunks.slice(1).map((chunk) => chunk.outgoingTransitionType), ['A', 'B', 'C', 'FINAL']);
    assert.deepEqual(parsed.handoffWarnings, []);
    assert.equal(parsed.chunks[2].label, 'Clip 2 · Type A');
    assert.equal(parsed.chunks[3].label, 'Clip 3 · Type B');
    assert.equal(parsed.chunks[4].label, 'Transition clip 4 · Type C · final handoff');
    assert.match(parsed.chunks[4].transitionLabel, /narrative \/ temporal transition/);
    assert.doesNotMatch(parsed.chunks[4].text, /Continue this exact scene/);
    assert.match(parsed.chunks[4].text, /Before: a young plant/);
    assert.match(parsed.chunks[4].text, /SECTION 9 — EXACT HANDOFF SPECIFICATION/);
    assert.match(parsed.notice, /1 dedicated Type C transition clip identified/);
});

test('flags a mismatch between the previous clip outgoing type and the next incoming type', () => {
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'The character plants the seed.',
        '**OUTGOING HANDOFF TYPE:** TYPE A — DIRECT CONTINUATION',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'The character carries the seed tray to the greenhouse.',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
    ].join('\n'));

    assert.deepEqual(parsed.chunks.map((chunk) => chunk.outgoingTransitionType), ['A', 'FINAL']);
    assert.equal(parsed.handoffWarnings.length, 1);
    assert.match(parsed.handoffWarnings[0], /Clip 1 ends with Type A, but Clip 2 begins with Type B/);
});

test('flags missing outgoing and final markers when the new speech-map contract is present', () => {
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'SPEECH/VISUAL MAP: 0–2s, the bee lands on the flower → “A bee lands.”',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION',
        'SPEECH/VISUAL MAP: 0–2s, pollen moves onto the bee → “Pollen sticks to its legs.”',
    ].join('\n'));

    assert.equal(parsed.handoffWarnings.length, 2);
    assert.match(parsed.handoffWarnings[0], /Clip 1 is missing its outgoing handoff type/);
    assert.match(parsed.handoffWarnings[1], /Clip 2 must end with OUTGOING HANDOFF TYPE: FINAL/);
});

test('checks an incoming transition marker even when no speech or outgoing field is present', () => {
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'A visible opening shot.',
    ].join('\n'));

    assert.ok(parsed.handoffWarnings.some((warning) => /must end with OUTGOING HANDOFF TYPE: FINAL/.test(warning)));
});

test('does not mistake an outgoing marker for a missing incoming type', () => {
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'The character begins watering.',
        'OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION',
        'COPY CLIP 2 PROMPT',
        'The same action continues.',
        'OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION',
        'COPY CLIP 3 PROMPT',
        'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION',
        'The action completes.',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
    ].join('\n'));

    assert.equal(parsed.chunks[1].transitionType, null);
    assert.match(parsed.handoffWarnings[0], /Clip 2 is missing an incoming transition type/);
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

test('preserves character-specific voice identities in each copied standalone clip prompt', () => {
    const parsed = parsePrompt([
        'SECTION 3 — CHARACTER BIBLE',
        'Mina: copper goggles, thoughtful personality; fixed voice is light mezzo pitch, warm brass-like timbre, measured cadence, crisp Urdu articulation.',
        'Rafi: small blue robot; fixed voice is low-mid pitch, rounded synthetic resonance, clipped cadence, soft metallic texture.',
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        '```text',
        'Mina and Rafi stand beside the flower. Keep Mina’s warm mezzo voice distinct from Rafi’s rounded synthetic voice.',
        'DIALOGUE — Mina: “The pollen travels with the bee.” Rafi: “I see it land on the next flower.”',
        'Preserve each named character’s exact voice identity and speaker mapping throughout this clip.',
        '```',
    ].join('\n'));

    assert.equal(parsed.format, 'clip-sequence');
    assert.match(parsed.chunks[0].text, /fixed voice is light mezzo pitch/);
    assert.match(parsed.chunks[1].text, /Keep Mina’s warm mezzo voice distinct from Rafi’s rounded synthetic voice/);
    assert.match(parsed.chunks[1].text, /DIALOGUE — Mina:/);
    assert.match(parsed.chunks[1].text, /Preserve each named character’s exact voice identity/);
});

test('surfaces each clip’s canonical visible cast while preserving full identity locks in copied text', () => {
    const parsed = parsePrompt([
        'SECTION 3 — CHARACTER BIBLE AND CANONICAL CAST',
        'Mina — CHAR-01: warm brown skin, round cheeks, copper goggles, indigo jacket, small scar above left brow.',
        'Rafi — CHAR-02: compact teal robot, square head, amber optic eyes, silver chest plate.',
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'VISIBLE CHARACTER CAST: Mina ×1.',
        'CHARACTER IDENTITY LOCK:',
        'Mina — CHAR-01: warm brown skin, round cheeks, copper goggles, indigo jacket, small scar above left brow.',
        'SHOT CAST MAP:',
        'SHOT 1 VISIBLE CHARACTER CAST: Mina ×1.',
        'SHOT 1 CHARACTER IDENTITY LOCK: Mina — warm brown skin, round cheeks, copper goggles.',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION',
        'VISIBLE CHARACTER CAST: Mina ×1; Rafi ×1.',
        'CHARACTER IDENTITY LOCK:',
        'Mina — CHAR-01: warm brown skin, round cheeks, copper goggles, indigo jacket, small scar above left brow.',
        'Rafi — CHAR-02: compact teal robot, square head, amber optic eyes, silver chest plate.',
        'COPY CLIP 3 PROMPT',
        'TRANSITION TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'VISIBLE CHARACTER CAST: None.',
        'CHARACTER IDENTITY LOCK: None — no characters or person-like figures appear.',
    ].join('\n'));

    assert.equal(parsed.chunks[1].visibleCast, 'Mina ×1.');
    assert.equal(parsed.chunks[2].visibleCast, 'Mina ×1; Rafi ×1.');
    assert.equal(parsed.chunks[3].visibleCast, 'None.');
    assert.match(parsed.chunks[1].text, /CHARACTER IDENTITY LOCK:[\s\S]*Mina — CHAR-01: warm brown skin, round cheeks, copper goggles, indigo jacket/);
    assert.match(parsed.chunks[2].text, /Rafi — CHAR-02: compact teal robot, square head, amber optic eyes, silver chest plate/);
    assert.match(parsed.chunks[2].text, /VISIBLE CHARACTER CAST: Mina ×1; Rafi ×1\./);
});

test('extracts the self-contained copy block from an extension prompt', () => {
    const parsed = parsePrompt([
        'Visible starting state: a robot is at a workbench.',
        '',
        '### COPY EXTENSION VIDEO PROMPT',
        '```text',
        'VISIBLE CHARACTER CAST: Visible person 1 ×1.',
        'CHARACTER IDENTITY LOCK: One adult with a red wool hat, gray coat, and oval glasses, as visible.',
        'Continue the exact scene from the shown frame. Keep the robot and lighting unchanged.',
        '```',
        '',
        'Continuity checklist: keep the same camera.',
    ].join('\n'));

    assert.equal(parsed.format, 'extension-prompt');
    assert.equal(parsed.chunks.length, 1);
    assert.equal(parsed.chunks[0].visibleCast, 'Visible person 1 ×1.');
    assert.equal(parsed.chunks[0].text, 'VISIBLE CHARACTER CAST: Visible person 1 ×1.\nCHARACTER IDENTITY LOCK: One adult with a red wool hat, gray coat, and oval glasses, as visible.\nContinue the exact scene from the shown frame. Keep the robot and lighting unchanged.');
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

test('accepts a physically matched Type A state chain and a naturally timed spoken line', () => {
    const scene = 'cast=Bee (CHAR-01) x1; objects=pollen grain P-01 on left hind leg; process=transfer not yet started; environment=flower bed; motion=bee hovering eastward';
    const presentation = 'camera=medium close-up, static; lighting=soft morning sun; audio=quiet garden ambience';
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'CLIP DURATION: 4 seconds',
        'PRIMARY IDEA: Pollen attaches to the bee.',
        'VISUAL REPRESENTATION: LITERAL SCENE',
        'VISIBLE CAUSE → ACTION → RESULT: The bee brushes a pollen-bearing anther and one grain sticks to its hind leg.',
        `OPENING CONTINUITY STATE: ${scene}`,
        `ENDING CONTINUITY STATE: ${scene}`,
        `OPENING PRESENTATION STATE: ${presentation}`,
        `ENDING PRESENTATION STATE: ${presentation}`,
        'SPEECH/VISUAL MAP:',
        '- 0.2–2.2s | VISIBLE ACTION: pollen visibly sticks to the bee’s hind leg | SPEAKER: Narrator | LINE: “A bee carries pollen.”',
        'OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION',
        'CLIP DURATION: 4 seconds',
        'PRIMARY IDEA: The bee reaches the next flower.',
        'VISUAL REPRESENTATION: LITERAL SCENE',
        'VISIBLE CAUSE → ACTION → RESULT: The bee continues the same flight toward the next flower.',
        `OPENING CONTINUITY STATE: ${scene}`,
        'ENDING CONTINUITY STATE: bee at the next flower; pollen P-01 still on the same hind leg; environment=flower bed; motion=slowing to land',
        `OPENING PRESENTATION STATE: ${presentation}`,
        'ENDING PRESENTATION STATE: camera tracks right; soft morning sun; quiet garden ambience',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
        'SECTION 9 — EXACT HANDOFF SPECIFICATION',
    ].join('\n'));

    assert.deepEqual(parsed.handoffWarnings, []);
    assert.match(parsed.chunks[1].text, /OPENING CONTINUITY STATE: cast=Bee/);
    assert.match(parsed.chunks[1].text, /SPEECH\/VISUAL MAP:/);
});

test('flags a skipped physical/process state even when a Type A label claims direct continuation', () => {
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'OUTGOING HANDOFF TYPE: TYPE A — DIRECT CONTINUATION',
        'OPENING CONTINUITY STATE: workpiece=raw panel; stage=unformed',
        'ENDING CONTINUITY STATE: workpiece=raw panel; stage=unformed',
        'OPENING PRESENTATION STATE: fixed camera; workshop light',
        'ENDING PRESENTATION STATE: fixed camera; workshop light',
        'CLIP DURATION: 4 seconds',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE A — DIRECT CONTINUATION',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
        'OPENING CONTINUITY STATE: workpiece=finished assembly; stage=complete',
        'ENDING CONTINUITY STATE: workpiece=finished assembly; stage=complete',
        'OPENING PRESENTATION STATE: fixed camera; workshop light',
        'ENDING PRESENTATION STATE: fixed camera; workshop light',
        'CLIP DURATION: 4 seconds',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
    ].join('\n'));

    assert.ok(parsed.handoffWarnings.some((warning) => /opening continuity state does not exactly match/.test(warning)));
});

test('allows a justified Type B bridge whose physical change happens inside the next clip', () => {
    const priorState = 'object=seed tray in Mina’s left hand; stage=seeds planted; environment=greenhouse doorway; motion=stationary';
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'CLIP DURATION: 4 seconds',
        'PRIMARY IDEA: Mina begins moving the seeded tray.',
        'VISUAL REPRESENTATION: LITERAL SCENE',
        'VISIBLE CAUSE → ACTION → RESULT: Mina lifts the tray from the bench.',
        `OPENING CONTINUITY STATE: ${priorState}`,
        `ENDING CONTINUITY STATE: ${priorState}`,
        'OPENING PRESENTATION STATE: wide shot; stable daylight; soft room tone',
        'ENDING PRESENTATION STATE: wide shot; stable daylight; soft room tone',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
        'OUTGOING HANDOFF TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'CLIP DURATION: 6 seconds',
        'PRIMARY IDEA: Carry the same tray to the greenhouse bench.',
        'VISUAL REPRESENTATION: LITERAL SCENE',
        'VISIBLE CAUSE → ACTION → RESULT: Mina takes three visible steps through the doorway and sets the tray on the bench.',
        `OPENING CONTINUITY STATE: ${priorState}`,
        'ENDING CONTINUITY STATE: same tray on greenhouse bench; seeds remain planted; stage=ready for watering; motion=stationary',
        'OPENING PRESENTATION STATE: wide shot; stable daylight; soft room tone',
        'ENDING PRESENTATION STATE: side view; soft greenhouse light; footsteps stop',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
    ].join('\n'));

    assert.deepEqual(parsed.handoffWarnings, []);
});

test('flags a Type B opening presentation jump instead of accepting a hidden cut', () => {
    const state = 'object=seed tray; stage=seeds planted; environment=greenhouse; motion=stationary';
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'CLIP DURATION: 4 seconds',
        `OPENING CONTINUITY STATE: ${state}`,
        `ENDING CONTINUITY STATE: ${state}`,
        'OPENING PRESENTATION STATE: wide shot; daylight; quiet ambience',
        'ENDING PRESENTATION STATE: wide shot; daylight; quiet ambience',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
        'OUTGOING HANDOFF TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'COPY CLIP 2 PROMPT',
        'TRANSITION TYPE: TYPE B — NATURAL ACTION TRANSITION',
        'CLIP DURATION: 5 seconds',
        `OPENING CONTINUITY STATE: ${state}`,
        `ENDING CONTINUITY STATE: ${state}`,
        'OPENING PRESENTATION STATE: close-up; blue night; rain',
        'ENDING PRESENTATION STATE: close-up; blue night; rain',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
    ].join('\n'));

    assert.ok(parsed.handoffWarnings.some((warning) => /opening presentation state does not exactly match/.test(warning)));
});

test('flags narration overrun and speech extending past a clip duration', () => {
    const parsed = parsePrompt([
        'SECTION 8 — COPY-AND-PASTE VIDEO PROMPTS',
        'COPY CLIP 1 PROMPT',
        'TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF',
        'CLIP DURATION: 4 seconds',
        'OPENING CONTINUITY STATE: flower=closed; bee=hovering',
        'ENDING CONTINUITY STATE: flower=open; bee=landed',
        'OPENING PRESENTATION STATE: macro camera; daylight',
        'ENDING PRESENTATION STATE: macro camera; daylight',
        'SPEECH/VISUAL MAP:',
        '- 3.0–4.5s | VISIBLE ACTION: the flower opens | SPEAKER: Narrator | LINE: “The bee carries pollen to the flower and helps the plant make seeds.”',
        'OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF',
    ].join('\n'));

    assert.ok(parsed.handoffWarnings.some((warning) => /ends after the 4-second clip/.test(warning)));
    assert.ok(parsed.handoffWarnings.some((warning) => /words in 1.5 seconds/.test(warning)));
});

test('flags missing extension state fields once a structured timing contract is present', () => {
    const parsed = parsePrompt([
        '### COPY EXTENSION VIDEO PROMPT',
        'CLIP DURATION: 4 seconds',
        'SPEECH/VISUAL MAP: None — intentionally no spoken line in this clip.',
    ].join('\n'));

    assert.ok(parsed.handoffWarnings.some((warning) => /missing OPENING CONTINUITY STATE/.test(warning)));
    assert.ok(parsed.handoffWarnings.some((warning) => /missing ENDING PRESENTATION STATE/.test(warning)));
});

test('caps extension warning display at eight entries plus an additional-warning count', () => {
    const speechRows = Array.from({ length: 10 }, () =>
        '- 0.0–0.9s | VISIBLE ACTION: the bee carries pollen across the garden | SPEAKER: Narrator | LINE: “The tiny bee carries pollen across the garden to another bright flower.”',
    ).join('\n');
    const parsed = parsePrompt([
        '### COPY EXTENSION VIDEO PROMPT',
        'CLIP DURATION: 1 second',
        'OPENING CONTINUITY STATE: bee by first flower',
        'ENDING CONTINUITY STATE: bee near second flower',
        'OPENING PRESENTATION STATE: macro shot; daylight',
        'ENDING PRESENTATION STATE: same camera; daylight',
        'SPEECH/VISUAL MAP:',
        speechRows,
    ].join('\n'));

    assert.equal(parsed.handoffWarnings.length, 9);
    assert.match(parsed.handoffWarnings.at(-1), /additional check warning\(s\)\.$/);
});

test('checks a structured extension map without changing the copy-ready text', () => {
    const raw = [
        '### COPY EXTENSION VIDEO PROMPT',
        '```text',
        'CLIP DURATION: 5 seconds',
        'OPENING CONTINUITY STATE: Mina holds an unlit lantern.',
        'ENDING CONTINUITY STATE: Mina raises the lit lantern.',
        'OPENING PRESENTATION STATE: medium shot; blue dusk',
        'ENDING PRESENTATION STATE: same shot; warm lantern glow',
        'SPEECH/VISUAL MAP:',
        '- 0.0–1.0s | VISIBLE ACTION: Mina lifts the lantern | SPEAKER: Mina | LINE: “The light is waking.”',
        '```',
    ].join('\n');
    const parsed = parsePrompt(raw);

    assert.ok(parsed.handoffWarnings.some((warning) => /words in 1.0 seconds/.test(warning)));
    assert.match(parsed.chunks[0].text, /CLIP DURATION: 5 seconds/);
    assert.match(parsed.chunks[0].text, /LINE: “The light is waking\.”/);
});


test('speech timing warns about opening, breathing and closing pads in clip and extension formats', () => {
    for (const heading of ['COPY CLIP 1 PROMPT', 'COPY EXTENSION VIDEO PROMPT']) {
        const parsed = parsePrompt(`${heading}
CLIP DURATION: 4 seconds
SPEECH/VISUAL MAP:
- 0.0–1.0s | VISIBLE ACTION: a wheel turns | SPEAKER: Narrator | LINE: “It turns.”
- 1.1–3.9s | VISIBLE ACTION: the wheel slows | SPEAKER: Narrator | LINE: “It slows.”`);
        assert.ok(parsed.handoffWarnings.some((warning) => /opening visual beat/.test(warning)));
        assert.ok(parsed.handoffWarnings.some((warning) => /breathing pause/.test(warning)));
        assert.ok(parsed.handoffWarnings.some((warning) => /after the final spoken line/.test(warning)));
    }
});
