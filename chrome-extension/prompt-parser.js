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

function capWarnings(warnings) {
    const uniqueWarnings = [...new Set(warnings)];
    return uniqueWarnings.length > 8
        ? [...uniqueWarnings.slice(0, 8), `${uniqueWarnings.length - 8} additional check warning(s).`]
        : uniqueWarnings;
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
    const incomingLines = text.split('\n').filter((line) => {
        const normalized = line.replace(/\*\*/g, '').replace(/__/g, '');
        return !/^\s*(?:[-*+]\s*)?OUTGOING\s+HANDOFF\s+TYPE\s*:/i.test(normalized);
    });
    const incomingText = incomingLines.join('\n');
    const marker = incomingText.match(/(?:INCOMING\s+)?TRANSITION\s+TYPE\s*:\s*([^\r\n]+)/i);
    if (marker) {
        const explicit = marker[1].match(/\bTYPE\s*([ABC])\b/i);
        return explicit ? explicit[1].toUpperCase() : null;
    }

    const descriptive = incomingText.match(/\bTYPE\s+([ABC])\s*[—–:-]\s*(?:DIRECT\s+CONTINUATION|NATURAL\s+ACTION\s+TRANSITION|NARRATIVE\s*\/\s*TEMPORAL\s+TRANSITION)\b/i);
    return descriptive ? descriptive[1].toUpperCase() : null;
}

function outgoingTransitionType(text) {
    const lines = text.split('\n').map((line) => line.replace(/\*\*/g, '').replace(/__/g, ''));
    const line = lines.find((candidate) => /^\s*(?:[-*+]\s*)?OUTGOING\s+HANDOFF\s+TYPE\s*:/i.test(candidate));
    const marker = line?.match(/^\s*(?:[-*+]\s*)?OUTGOING\s+HANDOFF\s+TYPE\s*:\s*([^\r\n]+)/i);
    if (!marker) return null;

    if (/\bFINAL\b/i.test(marker[1])) return 'FINAL';
    const explicit = marker[1].match(/\bTYPE\s*([ABC])\b/i);
    return explicit ? explicit[1].toUpperCase() : null;
}

function metadataValue(text, label) {
    const target = `${label.toLowerCase()}:`;
    const line = text.split('\n').find((candidate) => {
        const normalized = candidate.replace(/\*\*/g, '').replace(/__/g, '').replace(/`/g, '').trim().replace(/^[-*+>]\s*/, '');
        return normalized.toLowerCase().startsWith(target);
    });
    if (!line) return null;

    const normalized = line.replace(/\*\*/g, '').replace(/__/g, '').replace(/`/g, '');
    const marker = normalized.match(/^\s*(?:[-*+>]\s*)?[^:]+:\s*(.*?)\s*$/);
    return marker ? marker[1].trim() : null;
}

function durationSeconds(text) {
    const value = metadataValue(text, 'CLIP DURATION');
    const match = value?.match(/^(\d+(?:\.\d+)?)\s*(?:seconds?|secs?|s)$/i);
    return match ? Number(match[1]) : null;
}

function speechMapRows(text) {
    const rows = [];
    const pattern = /^\s*(?:[-*+•–]\s*)?(\d+(?:\.\d+)?)\s*(?:s)?\s*(?:–|—|-|to)\s*(\d+(?:\.\d+)?)\s*s\s*\|\s*VISIBLE ACTION:\s*(.*?)\s*\|\s*SPEAKER:\s*(.*?)\s*\|\s*LINE:\s*(.+?)\s*$/iu;

    text.split('\n').forEach((line) => {
        const normalized = line.replace(/\*\*/g, '').replace(/__/g, '').replace(/`/g, '');
        const match = normalized.match(pattern);
        if (!match) return;
        rows.push({ start: Number(match[1]), end: Number(match[2]), action: match[3].trim(), speaker: match[4].trim(), line: match[5].trim() });
    });

    return rows;
}

function estimateWords(line) {
    return (line.match(/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/gu) || []).length;
}

function structuredSpeechWarnings(chunk, duration) {
    const warnings = [];
    const mapValue = metadataValue(chunk.text, 'SPEECH/VISUAL MAP');
    const rows = speechMapRows(chunk.text);
    const number = chunk.clipNumber || 1;

    if (duration === null) {
        warnings.push(`Clip ${number} is missing CLIP DURATION, so speech timing cannot be checked against the clip length.`);
    }
    if (mapValue === null) {
        warnings.push(`Clip ${number} is missing SPEECH/VISUAL MAP metadata.`);
    }

    if (rows.length === 0) {
        const explicitSilence = mapValue && /\bnone\b/i.test(mapValue)
            && (/\bintentionally\b|spoken audio is off/i.test(mapValue));
        if (mapValue !== null && !explicitSilence) {
            warnings.push(`Clip ${number} has no timed speech rows and does not explicitly mark the clip as silent or audio-off.`);
        }
        return warnings;
    }

    let previousEnd = null;
    rows.forEach((row, index) => {
        if (!row.action || !row.speaker || !row.line) {
            warnings.push(`Clip ${number} speech row ${index + 1} needs a visible action, speaker, and exact line.`);
        }
        if (row.start < 0 || row.end <= row.start) {
            warnings.push(`Clip ${number} speech row ${index + 1} has an invalid time range.`);
        }
        if (duration !== null && row.end > duration) {
            warnings.push(`Clip ${number} speech row ${index + 1} ends after the ${duration}-second clip.`);
        }
        if (previousEnd !== null && row.start < previousEnd) {
            warnings.push(`Clip ${number} has overlapping speech windows; leave natural pauses instead of layering or rushing lines.`);
        }
        previousEnd = Math.max(previousEnd ?? 0, row.end);

        const words = estimateWords(row.line);
        const pauseCount = (row.line.match(/[.!?…](?:\s|$)/gu) || []).length;
        const minimumSeconds = (words / 2.2) + Math.max(0.12, Math.min(0.45, pauseCount * 0.12));
        if (words > 0 && row.end - row.start + 0.05 < minimumSeconds) {
            warnings.push(`Clip ${number} speech row ${index + 1} has ${words} words in ${(row.end - row.start).toFixed(1)} seconds; shorten it or allocate enough time for natural delivery.`);
        }
    });

    if (duration !== null && duration - rows.at(-1).end < 0.25) {
        warnings.push(`Clip ${number} leaves no settled visual beat after the final spoken line.`);
    }
    return warnings;
}

function handoffWarnings(chunks) {
    const clips = chunks.filter((chunk) => chunk.kind === 'clip');
    const hasTypeContract = clips.some((chunk) => chunk.transitionType
        || chunk.outgoingTransitionType
        || /^\s*(?:INCOMING\s+)?TRANSITION\s+TYPE\s*:/im.test(chunk.text))
        || clips.some((chunk) => /^\s*SPEECH\/VISUAL\s+MAP\s*:/im.test(chunk.text));
    const hasStateContract = clips.some((chunk) => metadataValue(chunk.text, 'OPENING CONTINUITY STATE') !== null
        || metadataValue(chunk.text, 'ENDING CONTINUITY STATE') !== null);
    const hasTimingContract = clips.some((chunk) => durationSeconds(chunk.text) !== null
        || speechMapRows(chunk.text).length > 0);
    if (!hasTypeContract && !hasStateContract && !hasTimingContract) return [];

    const warnings = [];
    if (hasTypeContract) {
        const firstClip = clips[0];
        const hasInitialMarker = firstClip?.text.split('\n').some((line) => {
            const normalized = line.replace(/\*\*/g, '').replace(/__/g, '').replace(/`/g, '');
            return /^\s*(?:[-*+>]\s*)?TRANSITION\s+TYPE\s*:\s*INITIAL\s+CLIP\b/i.test(normalized);
        });
        if (firstClip && !hasInitialMarker) warnings.push(`Clip ${firstClip.clipNumber} should use TRANSITION TYPE: INITIAL CLIP — NO PREVIOUS HANDOFF.`);

        clips.slice(0, -1).forEach((previous, index) => {
            const next = clips[index + 1];
            if (!previous.outgoingTransitionType) {
                warnings.push(`Clip ${previous.clipNumber} is missing its outgoing handoff type.`);
                return;
            }
            if (!next.transitionType) {
                warnings.push(`Clip ${next.clipNumber} is missing an incoming transition type; Clip ${previous.clipNumber} cannot be checked against it.`);
                return;
            }
            if (previous.outgoingTransitionType !== next.transitionType) {
                warnings.push(`Clip ${previous.clipNumber} ends with Type ${previous.outgoingTransitionType}, but Clip ${next.clipNumber} begins with Type ${next.transitionType}.`);
            }
        });

        const finalClip = clips.at(-1);
        if (finalClip && finalClip.outgoingTransitionType !== 'FINAL') {
            warnings.push(`Clip ${finalClip.clipNumber} must end with OUTGOING HANDOFF TYPE: FINAL — NO NEXT HANDOFF.`);
        }
    }

    if (hasStateContract) {
        clips.forEach((clip) => {
            const number = clip.clipNumber;
            const opening = metadataValue(clip.text, 'OPENING CONTINUITY STATE');
            const ending = metadataValue(clip.text, 'ENDING CONTINUITY STATE');
            const openingPresentation = metadataValue(clip.text, 'OPENING PRESENTATION STATE');
            const endingPresentation = metadataValue(clip.text, 'ENDING PRESENTATION STATE');
            if (!opening || !ending) warnings.push(`Clip ${number} needs both OPENING CONTINUITY STATE and ENDING CONTINUITY STATE.`);
            if (!openingPresentation || !endingPresentation) warnings.push(`Clip ${number} needs opening and ending camera/lighting/audio presentation states.`);
        });

        clips.slice(0, -1).forEach((previous, index) => {
            const next = clips[index + 1];
            const ending = metadataValue(previous.text, 'ENDING CONTINUITY STATE');
            const opening = metadataValue(next.text, 'OPENING CONTINUITY STATE');
            const normalizedEnding = ending?.replace(/\s+/g, ' ').trim();
            const normalizedOpening = opening?.replace(/\s+/g, ' ').trim();
            if (normalizedEnding && normalizedOpening && normalizedEnding !== normalizedOpening) {
                warnings.push(`Clip ${next.clipNumber} opening continuity state does not exactly match Clip ${previous.clipNumber} ending state; review object/process identity and any missing causal step.`);
            }

            if (['A', 'B'].includes(previous.outgoingTransitionType)) {
                const priorPresentation = metadataValue(previous.text, 'ENDING PRESENTATION STATE')?.replace(/\s+/g, ' ').trim();
                const nextPresentation = metadataValue(next.text, 'OPENING PRESENTATION STATE')?.replace(/\s+/g, ' ').trim();
                if (priorPresentation && nextPresentation && priorPresentation !== nextPresentation) {
                    warnings.push(`Clip ${next.clipNumber} opening presentation state does not exactly match Clip ${previous.clipNumber} ending state; Type B presentation changes must happen after the matched opening inside the destination clip.`);
                }
            }
        });
    }

    if (hasTimingContract) {
        clips.forEach((clip) => {
            warnings.push(...structuredSpeechWarnings(clip, durationSeconds(clip.text)));
        });
    }

    return capWarnings(warnings);
}

function extensionQualityWarnings(text) {
    const hasTiming = durationSeconds(text) !== null || speechMapRows(text).length > 0;
    const hasState = metadataValue(text, 'OPENING CONTINUITY STATE') !== null
        || metadataValue(text, 'ENDING CONTINUITY STATE') !== null;
    if (!hasTiming && !hasState) return [];

    const warnings = structuredSpeechWarnings({ text, clipNumber: 1 }, durationSeconds(text));
    ['OPENING CONTINUITY STATE', 'ENDING CONTINUITY STATE', 'OPENING PRESENTATION STATE', 'ENDING PRESENTATION STATE'].forEach((field) => {
        if (!metadataValue(text, field)) warnings.push(`Extension prompt is missing ${field}.`);
    });
    return capWarnings(warnings);
}

function visibleCharacterCast(text) {
    const line = text.split('\n').find((candidate) => {
        const normalized = candidate.replace(/\*\*/g, '').replace(/__/g, '');
        return /^\s*(?:[-*+]\s*)?VISIBLE\s+CHARACTER\s+CAST\s*:/i.test(normalized);
    });
    if (!line) return null;

    const normalized = line.replace(/\*\*/g, '').replace(/__/g, '');
    const marker = normalized.match(/^\s*(?:[-*+]\s*)?VISIBLE\s+CHARACTER\s+CAST\s*:\s*(.+?)\s*$/i);
    return marker ? marker[1].trim().replace(/\s+/g, ' ') || null : null;
}

const TRANSITION_LABELS = {
    A: 'direct continuation',
    B: 'natural action transition',
    C: 'narrative / temporal transition',
};

function fallback(raw, reason) {
    const text = raw.trim();
    const cast = visibleCharacterCast(text);
    return {
        format: 'complete-prompt',
        chunks: [{ kind: 'complete', label: 'Complete prompt', text, visibleCast: cast }],
        notice: reason,
        handoffWarnings: [],
    };
}

/**
 * Split either the app's Section 8 clip list or a section-per-clip v2 response
 * into context and individually copy-ready prompts. Incoming and outgoing
 * Type A/B/C handoffs are labeled and cross-checked; continuity/audit sections
 * after the final clip are attached to that final handoff.
 *
 * @param {string} raw
 * @returns {{format: string, chunks: Array<{kind: string, label: string, text: string, visibleCast?: string|null, clipNumber?: number, transitionType?: string|null, transitionLabel?: string|null, outgoingTransitionType?: string|null}>, notice: string, handoffWarnings: string[]}}
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
                chunks: [{ kind: 'extension', label: 'Extension prompt', text: copyPrompt, visibleCast: visibleCharacterCast(copyPrompt) }],
                notice: 'A single copy-ready extension prompt was found.',
                handoffWarnings: extensionQualityWarnings(copyPrompt),
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
                outgoingTransitionType: outgoingTransitionType(clipText),
                label: `${label}${hasHandoff ? ' · final handoff' : ''}`,
                text: clipText,
                visibleCast: visibleCharacterCast(clipText),
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
    const warnings = handoffWarnings(chunks);
    const notice = `${clipCount} clip prompt${clipCount === 1 ? '' : 's'} separated${hasHandoff ? '; the closing handoff and continuity sections are attached to the final clip' : ''}${transitionNotice}.`;

    return {
        format: 'clip-sequence',
        chunks,
        notice,
        handoffWarnings: warnings,
    };
}
