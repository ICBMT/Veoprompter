import { parsePrompt } from './prompt-parser.js';

const APP_ORIGINS_KEY = 'connectedAppOrigins';
const ACTIVE_PROMPT_KEY = 'activePrompt';
const MAX_PROMPT_CHARACTERS = 500_000;

function reply(sendResponse, value) {
    sendResponse(value);
}

async function receivePrompt(message, sender) {
    if (sender.id !== chrome.runtime.id || sender.frameId !== 0 || !sender.url) {
        return { ok: false, message: 'Prompts can only be received from a connected VeoPrompter page.' };
    }

    let sourceOrigin;
    try {
        sourceOrigin = new URL(sender.url).origin;
    } catch {
        return { ok: false, message: 'The VeoPrompter page address could not be verified.' };
    }

    const { [APP_ORIGINS_KEY]: connectedOrigins = [] } = await chrome.storage.local.get(APP_ORIGINS_KEY);
    if (!connectedOrigins.includes(sourceOrigin)) {
        return { ok: false, message: 'Connect this VeoPrompter site from the extension before sending prompts.' };
    }

    if (typeof message.prompt !== 'string' || !message.prompt.trim() || message.prompt.length > MAX_PROMPT_CHARACTERS) {
        return { ok: false, message: 'This prompt is empty or too large to store.' };
    }

    const parsed = parsePrompt(message.prompt);
    if (!parsed.chunks.length || parsed.chunks.some((chunk) => !chunk.text.trim())) {
        return { ok: false, message: 'The prompt could not be divided into copy-ready parts.' };
    }

    const { [ACTIVE_PROMPT_KEY]: previousPrompt = null } = await chrome.storage.local.get(ACTIVE_PROMPT_KEY);
    const savedRunId = Number(message.savedRunId);
    const prompt = {
        id: crypto.randomUUID(),
        repairRunId: Number.isSafeInteger(savedRunId) && savedRunId > 0 ? savedRunId : null,
        title: typeof message.title === 'string' && message.title.trim()
            ? message.title.trim().slice(0, 140)
            : 'VeoPrompter prompt',
        mode: message.mode === 'extend' ? 'extend' : 'story',
        sourceOrigin,
        createdAt: new Date().toISOString(),
        rawText: message.prompt,
        format: parsed.format,
        notice: parsed.notice,
        handoffWarnings: parsed.handoffWarnings || [],
        chunks: parsed.chunks,
        nextIndex: 0,
        pendingPaste: null,
        lastInserted: null,
    };

    await chrome.storage.local.set({ [ACTIVE_PROMPT_KEY]: prompt });

    return {
        ok: true,
        promptId: prompt.id,
        chunkCount: prompt.chunks.length,
        notice: prompt.notice,
        replacedPrevious: Boolean(previousPrompt),
    };
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message?.type !== 'VEOPROMPTER_STORE_PROMPT_FROM_APP') return false;

    receivePrompt(message, sender)
        .then((response) => reply(sendResponse, response))
        .catch(() => reply(sendResponse, {
            ok: false,
            message: 'The prompt could not be saved in extension storage. Check the extension and try again.',
        }));
    return true;
});
