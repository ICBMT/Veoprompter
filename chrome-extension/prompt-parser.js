const SECTION_HEADING = /^SECTION\s+(\d+)(?:\b|\s|[—–:-])/i;
const CLIP_HEADING_PATTERNS = [
    /^(?:COPY\s+)?(?:TRANSITION\s+)?CLIP\s+(\d+)(?:\s+TRANSITION)?\s+(?:COPY[- ]AND[- ]PASTE\s+)?(?:VIDEO\s+)?PROMPT\b\s*(?:>|:|—|–|-)?\s*(.*)$/i,
    /^(?:TRANSITION\s+)?CLIP\s+(\d+)\s*(?:[—–:-]\s*)?(?:TRANSITION\s+)?(?:COPY[- ]AND[- ]PASTE\s+)?(?:VIDEO\s+)?PROMPT\b\s*(?:>|:|—|–|-)?\s*(.*)$/i,
];

function decoratedHeading(line) {
    return line
        .trim()
        .replace(/&gt;/gi, '>')
        .replace(/^>\s*/, '')
        .replace(/^#{1,6}\s*/, '')
        .replace(/^(?:[-*+]\s+)/, '')
        .replace(/^\*\*(.*)\*\*$/, '$1')
        .replace(/^__(.*)__$/, '$1')
        .trim();
}

function sectionNumber(line) {
    const match = decoratedHeading(line).match(SECTION_HEADING);
    return match ? Number(match[1]) : null;
}

function clipHeading(line) {
    const value = decoratedHeading(line);
    const sectionTitle = value.match(/^SECTION\s+\d+\+?(?:\s*[—–:-]\s*|\s+)(.+)$/i);
    const candidates = sectionTitle
        ? [{ text: sectionTitle[1].trim(), sectionWrapped: true }, { text: value, sectionWrapped: false }]
        : [{ text: value, sectionWrapped: false }];

    for (const candidate of candidates) {
        for (const pattern of CLIP_HEADING_PATTERNS) {
            const match = candidate.text.match(pattern);
            if (match) {
                return {
                    number: Number(match[1]),
                    inlineText: match[2].trim(),
                    sectionWrapped: candidate.sectionWrapped,
                    transitionHeading: /^(?:COPY\s+)?TRANSITION\s+CLIP\b/i.test(candidate.text),
                };
            }
        }
    }

    return null;
}

function sectionLineBefore(lines, index) {
    let previous = index - 1;
    while (previous >= 0 && !lines[previous].trim()) previous -= 1;

    return previous >= 0 && sectionNumber(lines[previous]) !== null ? previous : index;
}

function cleanCopyBlock(lines) {
    const content = [...lines].map((line) => line.replace(/\r/g, ''));
    const isBoundary = (line) => !line.trim() || /^\s*(?:---+|\*\*\*+)\s*$/.test(line);

    while (content.length && isBoundary(content[0])) content.shift();
    while (content.length && isBoundary(content[content.length - 1])) content.pop();
    if (content.length && /^\s*```[^`]*\s*$/.test(content[0])) content.shift();
    while (content.length && !content[0].trim()) content.shift();
    if (content.length && /^\s*```\s*$/.test(content[content.length - 1])) content.pop();
    while (content.length && !content[content.length - 1].trim()) content.pop();

    return content.join('\n').trim();
}

function extensionPrompt(lines) {
    const marker = lines.findIndex((line) => /^(?:#{1,6}\s*)?(?:\*\*)?COPY\s+EXTENSION\s+VIDEO\s+PROMPT\b/i.test(line.trim()));
    if (marker < 0) return null;

    const markerText = decoratedHeading(lines[marker]).replace(/^COPY\s+EXTENSION\s+VIDEO\s+PROMPT\b\s*(?:>|:|—|–|-)?\s*/i, '').trim();
    const nextSection = lines.findIndex((line, index) => index > marker && sectionNumber(line) !== null);
    const end = nextSection < 0 ? lines.length : nextSection;
    const body = [...(markerText ? [markerText] : []), ...lines.slice(marker + 1, end)];
    const openingFence = body.findIndex((line) => /^\s*```[^`]*\s*$/.test(line));

    if (openingFence >= 0) {
        const closingFence = body.findIndex((line, index) => index > openingFence && /^\s*```\s*$/.test(line));
        if (closingFence > openingFence) {
            return cleanCopyBlock(body.slice(openingFence + 1, closingFence));
        }
    }

    const stop = body.findIndex((line) => /^(?:#{1,6}\s*)?(?:CONTINUITY\s+(?:CHECK|AUDIT)|COPY\s+CHECKLIST)\b/i.test(decoratedHeading(line)));
    return cleanCopyBlock(body.slice(0, stop < 0 ? body.length : stop));
}

function transitionType(text) {
    const marker = text.match(/(?:INCOMING\s+)?TRANSITION\s+TYPE\s*:\s*([^\r\n]+)/i);
    if (marker) {
        const explicit = marker[1].match(/\bTYPE\s*([ABC])\b/i);
        return explicit ? explicit[1].toUpperCase() : null;
    }

    const descriptive = text.match(/\bTYPE\s+([ABC])\s*[—–:-]\s*(?:DIRECT\s+CONTINUATION|NATURAL\s+ACTION\s+TRANSITION|NARRATIVE\s*\/\s*TEMPORAL\s+TRANSITION)\b/i);
    return descriptive ? descriptive[1].toUpperCase() : null;
}

const TRANSITION_LABELS = {
    A: 'direct continuation',
    B: 'natural action transition',
    C: 'narrative / temporal transition',
};

function fallback(raw, reason) {
    return {
        format: 'complete-prompt',
        chunks: [{ kind: 'complete', label: 'Complete prompt', text: raw.trim() }],
        notice: reason,
    };
}

/**
 * Split either the app's Section 8 clip list or a section-per-clip v2 response
 * into context and individually copy-ready prompts. Incoming Type A/B/C handoffs
 * are labeled for the popup; continuity/audit sections after the final clip are
 * attached to that final handoff.
 *
 * @param {string} raw
 * @returns {{format: string, chunks: Array<{kind: string, label: string, text: string, clipNumber?: number, transitionType?: string|null, transitionLabel?: string|null}>, notice: string}}
 */
export function parsePrompt(raw) {
    if (typeof raw !== 'string' || !raw.trim()) {
        return fallback('', 'The prompt is empty. Generate a prompt in VeoPrompter and send it again.');
    }

    const text = raw.replace(/\r\n?/g, '\n');
    const lines = text.split('\n');
    const sectionEight = lines.findIndex((line) => sectionNumber(line) === 8);
    const scanStart = sectionEight >= 0 ? sectionEight : 0;
    const clipHeadings = [];

    for (let index = scanStart; index < lines.length; index += 1) {
        const clip = clipHeading(lines[index]);
        if (!clip) continue;

        clipHeadings.push({
            ...clip,
            line: index,
            startLine: clip.sectionWrapped ? index : sectionLineBefore(lines, index),
        });
    }

    if (!clipHeadings.length) {
        const copyPrompt = extensionPrompt(lines);
        if (copyPrompt) {
            return {
                format: 'extension-prompt',
                chunks: [{ kind: 'extension', label: 'Extension prompt', text: copyPrompt }],
                notice: 'A single copy-ready extension prompt was found.',
            };
        }

        return fallback(text, 'This format was not recognized, so the complete prompt will be copied as one part.');
    }

    const contextEnd = sectionEight >= 0 ? sectionEight : clipHeadings[0].startLine;
    const contextText = cleanCopyBlock(lines.slice(0, contextEnd));
    const lastClipLine = clipHeadings[clipHeadings.length - 1].line;
    let trailerStart = lines.length;

    for (let index = lastClipLine + 1; index < lines.length; index += 1) {
        if (sectionNumber(lines[index]) !== null) {
            trailerStart = index;
            break;
        }
    }

    const chunks = [];

    if (contextText) {
        chunks.push({
            kind: 'context',
            label: 'Project context · Sections 1–7',
            text: contextText,
        });
    }

    clipHeadings.forEach((clip, index) => {
        const nextClipStart = clipHeadings[index + 1]?.startLine;
        const bodyEnd = nextClipStart ?? trailerStart;
        const clipLines = [];
        if (clip.inlineText) clipLines.push(clip.inlineText);
        clipLines.push(...lines.slice(clip.line + 1, Math.max(clip.line + 1, bodyEnd)));
        let clipText = cleanCopyBlock(clipLines);
        const hasHandoff = index === clipHeadings.length - 1 && trailerStart < lines.length;

        if (hasHandoff) {
            const handoff = cleanCopyBlock(lines.slice(trailerStart));
            if (handoff) clipText = [clipText, handoff].filter(Boolean).join('\n\n');
        }

        if (clipText) {
            const transition = transitionType(clipText) ?? (clip.transitionHeading ? 'C' : null);
            const label = transition === 'C'
                ? `Transition clip ${clip.number} · Type C`
                : transition
                    ? `Clip ${clip.number} · Type ${transition}`
                    : `Clip ${clip.number}`;

            chunks.push({
                kind: 'clip',
                clipNumber: clip.number,
                transitionType: transition,
                transitionLabel: transition ? TRANSITION_LABELS[transition] : null,
                label: `${label}${hasHandoff ? ' · final handoff' : ''}`,
                text: clipText,
            });
        }
    });

    const clipCount = chunks.filter((chunk) => chunk.kind === 'clip').length;
    if (!clipCount) {
        return fallback(text, 'Clip labels were found, but their prompts were empty. The complete prompt will be copied as one part.');
    }

    const hasHandoff = trailerStart < lines.length;
    const transitionCount = chunks.filter((chunk) => chunk.transitionType === 'C').length;
    const transitionNotice = transitionCount
        ? `; ${transitionCount} dedicated Type C transition clip${transitionCount === 1 ? '' : 's'} identified`
        : '';
    const notice = `${clipCount} clip prompt${clipCount === 1 ? '' : 's'} separated${hasHandoff ? '; the closing handoff and continuity sections are attached to the final clip' : ''}${transitionNotice}.`;

    return {
        format: 'clip-sequence',
        chunks,
        notice,
    };
}
