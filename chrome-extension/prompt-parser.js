const SECTION_HEADING = /^SECTION\s+(\d+)(?:\b|\s|[—–:-])/i;
const CLIP_HEADING_PATTERNS = [
    /^(?:COPY\s+)?CLIP\s+(\d+)\s+(?:COPY[- ]AND[- ]PASTE\s+)?(?:VIDEO\s+)?PROMPT\b\s*(?:>|:|—|–|-)?\s*(.*)$/i,
    /^CLIP\s+(\d+)\s*(?:[—–:-]\s*)?(?:COPY[- ]AND[- ]PASTE\s+)?(?:VIDEO\s+)?PROMPT\b\s*(?:>|:|—|–|-)?\s*(.*)$/i,
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

    for (const pattern of CLIP_HEADING_PATTERNS) {
        const match = value.match(pattern);
        if (match) return { number: Number(match[1]), inlineText: match[2].trim() };
    }

    return null;
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

function fallback(raw, reason) {
    return {
        format: 'complete-prompt',
        chunks: [{ kind: 'complete', label: 'Complete prompt', text: raw.trim() }],
        notice: reason,
    };
}

/**
 * Split a VeoPrompter result into the context and individually pasteable clips.
 * Section 9 onward is attached to the final clip so the Flow handoff notes stay
 * with the clip sequence without becoming a separate chat message.
 *
 * @param {string} raw
 * @returns {{format: string, chunks: Array<{kind: string, label: string, text: string, clipNumber?: number}>, notice: string}}
 */
export function parsePrompt(raw) {
    if (typeof raw !== 'string' || !raw.trim()) {
        return fallback('', 'The prompt is empty. Generate a prompt in VeoPrompter and send it again.');
    }

    const text = raw.replace(/\r\n?/g, '\n');
    const lines = text.split('\n');
    const sectionEight = lines.findIndex((line) => sectionNumber(line) === 8);
    const sectionStart = sectionEight >= 0 ? sectionEight + 1 : 0;
    const clipHeadings = [];

    for (let index = sectionStart; index < lines.length; index += 1) {
        const number = sectionNumber(lines[index]);
        if (index > sectionStart && number !== null && number > 8) break;
        const clip = clipHeading(lines[index]);
        if (clip) clipHeadings.push({ ...clip, line: index });
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

        return fallback(text, 'This format was not recognized, so the complete prompt will be pasted as one part.');
    }

    const firstClipLine = clipHeadings[0].line;
    const contextEnd = sectionEight >= 0 ? sectionEight : firstClipLine;
    const contextText = cleanCopyBlock(lines.slice(0, contextEnd));
    const sectionEnd = (() => {
        for (let index = firstClipLine + 1; index < lines.length; index += 1) {
            const number = sectionNumber(lines[index]);
            if (number !== null && number > 8) return index;
        }
        return lines.length;
    })();
    const chunks = [];

    if (contextText) {
        chunks.push({
            kind: 'context',
            label: 'Project context · Sections 1–7',
            text: contextText,
        });
    }

    clipHeadings.forEach((clip, index) => {
        const nextClipLine = clipHeadings[index + 1]?.line ?? sectionEnd;
        const clipLines = [];
        if (clip.inlineText) clipLines.push(clip.inlineText);
        clipLines.push(...lines.slice(clip.line + 1, Math.min(nextClipLine, sectionEnd)));
        let clipText = cleanCopyBlock(clipLines);

        if (index === clipHeadings.length - 1 && sectionEnd < lines.length) {
            const handoff = cleanCopyBlock(lines.slice(sectionEnd));
            if (handoff) clipText = [clipText, handoff].filter(Boolean).join('\n\n');
        }

        if (clipText) {
            chunks.push({
                kind: 'clip',
                clipNumber: clip.number,
                label: `Clip ${clip.number}${index === clipHeadings.length - 1 && sectionEnd < lines.length ? ' · final handoff' : ''}`,
                text: clipText,
            });
        }
    });

    const clipCount = chunks.filter((chunk) => chunk.kind === 'clip').length;
    if (!clipCount) {
        return fallback(text, 'Clip labels were found, but their prompts were empty. The complete prompt will be pasted as one part.');
    }

    const hasHandoff = sectionEnd < lines.length;
    const notice = `${clipCount} clip prompt${clipCount === 1 ? '' : 's'} separated${hasHandoff ? '; the closing handoff and continuity sections are attached to the final clip' : ''}.`;

    return {
        format: 'clip-sequence',
        chunks,
        notice,
    };
}
