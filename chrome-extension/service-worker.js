import { parsePrompt } from './prompt-parser.js';
import { flowComposerAction } from './flow-composer.js';

const APP_ORIGINS_KEY = 'connectedAppOrigins';
const ACTIVE_PROMPT_KEY = 'activePrompt';
const MAX_PROMPT_CHARACTERS = 500_000;

function reply(sendResponse, value) {
    sendResponse(value);
}

function isExtensionPage(sender) {
    if (sender.id !== chrome.runtime.id || sender.frameId !== 0 || !sender.url) return false;

    try {
        const url = new URL(sender.url);
        return url.protocol === 'chrome-extension:' && url.hostname === chrome.runtime.id;
    } catch {
        return false;
    }
}

function isFlowUrl(value) {
    try {
        const url = new URL(value);
        return url.protocol === 'https:'
            && (url.hostname === 'flow.google'
                || (url.hostname === 'labs.google' && /^\/fx\/tools\/flow(?:\/|$)/.test(url.pathname)));
    } catch {
        return false;
    }
}

async function executeFlowAction(tabId, action, text) {
    // Restrict scripting to a verified Flow URL; activeTab supplies temporary access only after the user opens the extension there.
    try {
        const tab = await chrome.tabs.get(tabId);
        if (!isFlowUrl(tab?.url)) return { ok: false, reason: 'not-flow' };

        const [execution] = await chrome.scripting.executeScript({
            target: { tabId },
            func: flowComposerAction,
            args: [action, text],
        });
        return execution?.result || { ok: false, reason: 'flow-unavailable' };
    } catch {
        return { ok: false, reason: 'flow-unavailable' };
    }
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
    const prompt = {
        id: crypto.randomUUID(),
        title: typeof message.title === 'string' && message.title.trim()
            ? message.title.trim().slice(0, 140)
            : 'VeoPrompter prompt',
        mode: message.mode === 'extend' ? 'extend' : 'story',
        sourceOrigin,
        createdAt: new Date().toISOString(),
        rawText: message.prompt,
        format: parsed.format,
        notice: parsed.notice,
        chunks: parsed.chunks,
        nextIndex: 0,
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

async function insertNextPart(message, sender) {
    if (!isExtensionPage(sender)) return { ok: false, reason: 'untrusted-sender' };
    if (!Number.isInteger(message.tabId) || typeof message.promptId !== 'string') {
        return { ok: false, reason: 'invalid-request' };
    }

    const { [ACTIVE_PROMPT_KEY]: prompt = null } = await chrome.storage.local.get(ACTIVE_PROMPT_KEY);
    const expectedIndex = Number(message.expectedIndex);
    if (!prompt || prompt.id !== message.promptId || prompt.nextIndex !== expectedIndex) {
        return { ok: false, reason: 'prompt-changed' };
    }

    const chunk = prompt.chunks[prompt.nextIndex];
    if (!chunk?.text?.trim()) return { ok: false, reason: 'empty-prompt' };

    const result = await executeFlowAction(message.tabId, 'fill', chunk.text);
    if (!result.ok) return result;

    const { [ACTIVE_PROMPT_KEY]: currentPrompt = null } = await chrome.storage.local.get(ACTIVE_PROMPT_KEY);
    if (!currentPrompt || currentPrompt.id !== prompt.id || currentPrompt.nextIndex !== expectedIndex) {
        return { ok: false, reason: 'prompt-changed', inserted: true };
    }

    currentPrompt.nextIndex = expectedIndex + 1;
    currentPrompt.lastInserted = {
        index: expectedIndex,
        tabId: message.tabId,
        insertedAt: new Date().toISOString(),
    };
    await chrome.storage.local.set({ [ACTIVE_PROMPT_KEY]: currentPrompt });

    return {
        ok: true,
        promptId: currentPrompt.id,
        nextIndex: currentPrompt.nextIndex,
        lastInserted: currentPrompt.lastInserted,
        label: chunk.label,
    };
}

async function navigatePrompt(message, sender, action) {
    if (!isExtensionPage(sender)) return { ok: false, reason: 'untrusted-sender' };

    const { [ACTIVE_PROMPT_KEY]: prompt = null } = await chrome.storage.local.get(ACTIVE_PROMPT_KEY);
    if (!prompt || prompt.id !== message.promptId) return { ok: false, reason: 'prompt-changed' };

    const targetIndex = action === 'restart' ? 0 : Math.max(0, prompt.nextIndex - 1);
    let undo = { ok: true, result: 'not-applicable' };
    const lastInserted = prompt.lastInserted;
    const shouldClear = lastInserted && (action === 'restart' || lastInserted.index === targetIndex);

    if (shouldClear) {
        const insertedText = prompt.chunks[lastInserted.index]?.text;
        undo = Number.isInteger(lastInserted.tabId) && typeof insertedText === 'string'
            ? await executeFlowAction(lastInserted.tabId, 'clear-if-matching', insertedText)
            : { ok: false, reason: 'flow-unavailable' };
        prompt.lastInserted = null;
    }

    prompt.nextIndex = targetIndex;
    await chrome.storage.local.set({ [ACTIVE_PROMPT_KEY]: prompt });
    return { ok: true, promptId: prompt.id, nextIndex: prompt.nextIndex, undo, targetIndex };
}

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message?.type === 'VEOPROMPTER_STORE_PROMPT_FROM_APP') {
        receivePrompt(message, sender)
            .then((response) => reply(sendResponse, response))
            .catch(() => reply(sendResponse, {
                ok: false,
                message: 'The prompt could not be saved in extension storage. Check the extension and try again.',
            }));
        return true;
    }

    if (message?.type === 'VEOPROMPTER_FLOW_INSERT_NEXT') {
        insertNextPart(message, sender)
            .then((response) => reply(sendResponse, response))
            .catch(() => reply(sendResponse, { ok: false, reason: 'flow-unavailable' }));
        return true;
    }

    if (message?.type === 'VEOPROMPTER_PROMPT_REWIND') {
        navigatePrompt(message, sender, 'rewind')
            .then((response) => reply(sendResponse, response))
            .catch(() => reply(sendResponse, { ok: false, reason: 'storage-unavailable' }));
        return true;
    }

    if (message?.type === 'VEOPROMPTER_PROMPT_RESTART') {
        navigatePrompt(message, sender, 'restart')
            .then((response) => reply(sendResponse, response))
            .catch(() => reply(sendResponse, { ok: false, reason: 'storage-unavailable' }));
        return true;
    }

    return false;
});
