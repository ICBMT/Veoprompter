const STORAGE_KEYS = ['connectedAppOrigins', 'activePrompt'];
const APP_BRIDGE_ID = 'veoprompter-app-bridge';

const mainButton = document.querySelector('[data-main-action]');
const promptCard = document.querySelector('[data-prompt-card]');
const emptyState = document.querySelector('[data-empty-state]');
const statusMessage = document.querySelector('[data-status]');
const backAction = document.querySelector('[data-back-action]');
const restartAction = document.querySelector('[data-restart-action]');
const copyFallbackAction = document.querySelector('[data-copy-fallback]');
const connectionList = document.querySelector('[data-connection-list]');
const progressTrack = document.querySelector('.progress-track');

let activeTab = null;
let popupState = { connectedAppOrigins: [], activePrompt: null };
let currentAction = 'none';

function currentOrigin(value) {
    try {
        const url = new URL(value);
        return ['http:', 'https:'].includes(url.protocol) ? url.origin : null;
    } catch {
        return null;
    }
}

function isFlowTab(tab) {
    try {
        const url = new URL(tab?.url);
        return url.protocol === 'https:'
            && (url.hostname === 'flow.google'
                || (url.hostname === 'labs.google' && /^\/fx\/tools\/flow(?:\/|$)/.test(url.pathname)));
    } catch {
        return false;
    }
}

function isLikelyVeoPrompterPage(tab) {
    if (/veoprompter/i.test(tab?.title || '')) return true;
    try {
        return ['localhost', '127.0.0.1', '[::1]'].includes(new URL(tab.url).hostname);
    } catch {
        return false;
    }
}

function permissionPattern(origin) {
    const url = new URL(origin);
    return `${url.protocol}//${url.hostname}/*`;
}

function setStatus(message = '', kind = 'info') {
    statusMessage.textContent = message;
    statusMessage.dataset.kind = kind;
    statusMessage.hidden = !message;
}

function showEmpty(title, copy) {
    emptyState.hidden = false;
    promptCard.hidden = true;
    document.querySelector('[data-empty-title]').textContent = title;
    document.querySelector('[data-empty-copy]').textContent = copy;
}

function setMainAction(label, action, disabled = false) {
    mainButton.textContent = label;
    mainButton.disabled = disabled;
    currentAction = action;
}

function updateConnectionList(origins) {
    connectionList.replaceChildren();
    if (!origins.length) {
        connectionList.textContent = 'No site connected yet.';
        return;
    }

    origins.forEach((origin) => {
        const row = document.createElement('div');
        row.className = 'connection-origin';
        row.textContent = origin;
        connectionList.append(row);
    });
}

function renderPrompt(prompt) {
    promptCard.hidden = false;
    emptyState.hidden = true;
    document.querySelector('[data-prompt-title]').textContent = prompt.title || 'VeoPrompter prompt';
    document.querySelector('[data-prompt-notice]').textContent = prompt.notice || 'Your prompt is stored locally in this browser.';

    const total = prompt.chunks.length;
    const nextIndex = Math.min(Math.max(Number(prompt.nextIndex) || 0, 0), total);
    const flowIsActive = isFlowTab(activeTab);
    const percent = total ? Math.round((nextIndex / total) * 100) : 0;
    const progressBar = document.querySelector('[data-progress-bar]');
    progressBar.style.width = `${percent}%`;
    progressTrack.setAttribute('aria-valuemax', String(total || 1));
    progressTrack.setAttribute('aria-valuenow', String(nextIndex));
    document.querySelector('[data-progress-count]').textContent = total
        ? `Part ${Math.min(nextIndex + 1, total)} of ${total}`
        : 'No copyable parts';

    const nextChunk = prompt.chunks[nextIndex];
    document.querySelector('[data-next-part]').textContent = nextChunk
        ? `Next: ${nextChunk.label}`
        : 'All parts ready';

    if (nextChunk) {
        const flowLabel = nextChunk.kind === 'context'
            ? 'Sections 1–7'
            : nextChunk.kind === 'clip'
                ? `Clip ${nextChunk.clipNumber}`
                : nextChunk.label;
        setMainAction(flowIsActive ? `Fill Flow · ${flowLabel}` : `Copy ${nextChunk.label}`, flowIsActive ? 'fill-flow' : 'copy');
    } else {
        setMainAction('All parts ready', 'none', true);
    }

    backAction.hidden = nextIndex === 0;
    restartAction.hidden = nextIndex === 0;
    copyFallbackAction.hidden = !flowIsActive || !nextChunk;
    document.querySelector('[data-usage-note]').textContent = nextChunk
        ? flowIsActive
            ? 'Click to copy this part and fill Flow’s empty prompt box. Review it, then click Flow’s Send button. Existing composer text is never overwritten.'
            : 'Each click copies one part. Open Flow and this popup to fill Flow automatically, or copy the sequence with Flow closed.'
        : 'All parts are ready. The extension never sends messages; you control when to submit each part in Flow.';
}

async function refresh() {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    activeTab = tab || null;
    popupState = await chrome.storage.local.get(STORAGE_KEYS);
    popupState.connectedAppOrigins ||= [];
    popupState.activePrompt ||= null;
    updateConnectionList(popupState.connectedAppOrigins);

    const appOrigin = activeTab?.url ? currentOrigin(activeTab.url) : null;
    let hasAppPermission = false;
    if (appOrigin) {
        try {
            hasAppPermission = await chrome.permissions.contains({ origins: [permissionPattern(appOrigin)] });
        } catch {
            hasAppPermission = false;
        }
    }
    const isConnectedApp = appOrigin && popupState.connectedAppOrigins.includes(appOrigin) && hasAppPermission;
    const needsConnection = Boolean(appOrigin && !isConnectedApp && isLikelyVeoPrompterPage(activeTab));

    if (popupState.activePrompt) {
        renderPrompt(popupState.activePrompt);
        return;
    }

    showEmpty(
        needsConnection ? 'Connect this VeoPrompter site' : 'Send a prompt to Flow',
        needsConnection
            ? 'Allow this extension to receive prompts from the VeoPrompter site open in this tab.'
            : 'Generate a prompt in VeoPrompter, then use its Send to Flow button. Your prompt stays in this browser.',
    );

    if (needsConnection) {
        setMainAction('Connect this site', 'connect-site');
        document.querySelector('[data-usage-note]').textContent = 'You will be asked to approve this site. The extension does not read other websites.';
    } else {
        setMainAction('Send a prompt from VeoPrompter first', 'none', true);
        document.querySelector('[data-usage-note]').textContent = 'Once a prompt is stored, copy its parts on any tab or open Flow first to fill its prompt box automatically.';
    }

    backAction.hidden = true;
    restartAction.hidden = true;
    copyFallbackAction.hidden = true;
}

async function registerAppBridge(origins) {
    const permittedMatches = [];
    for (const origin of origins) {
        const pattern = permissionPattern(origin);
        if (await chrome.permissions.contains({ origins: [pattern] })) permittedMatches.push(pattern);
    }
    const matches = [...new Set(permittedMatches)];
    const registered = await chrome.scripting.getRegisteredContentScripts({ ids: [APP_BRIDGE_ID] });

    if (!matches.length) {
        if (registered.length) await chrome.scripting.unregisterContentScripts({ ids: [APP_BRIDGE_ID] });
        return;
    }

    const script = {
        id: APP_BRIDGE_ID,
        matches,
        js: ['app-bridge.js'],
        runAt: 'document_idle',
        persistAcrossSessions: true,
        allFrames: false,
    };

    if (registered.length) await chrome.scripting.updateRegisteredContentScripts([script]);
    else await chrome.scripting.registerContentScripts([script]);
}

async function connectCurrentSite() {
    const origin = activeTab?.url ? currentOrigin(activeTab.url) : null;
    if (!origin) {
        setStatus('Open your VeoPrompter application in this tab, then connect it here.', 'error');
        return;
    }

    let pattern;
    try {
        pattern = permissionPattern(origin);
    } catch {
        setStatus('This page does not have a supported web address.', 'error');
        return;
    }

    try {
        const granted = await chrome.permissions.request({ origins: [pattern] });
        if (!granted) {
            setStatus('Site access was not approved. You can connect it later from this popup.', 'error');
            return;
        }

        const origins = [...new Set([...popupState.connectedAppOrigins, origin])];
        await chrome.storage.local.set({ connectedAppOrigins: origins });
        await registerAppBridge(origins);
        await chrome.scripting.executeScript({ target: { tabId: activeTab.id }, files: ['app-bridge.js'] });
        popupState.connectedAppOrigins = origins;
        updateConnectionList(origins);
        setStatus('Connected. Return to VeoPrompter and press Send to Flow on a generated prompt.', 'success');
        await refresh();
    } catch {
        setStatus('The site could not be connected. Check extension permissions and try again.', 'error');
    }
}

async function copyText(text) {
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);
            return;
        } catch {
            // Use the extension-page copy fallback below if Clipboard API access is blocked.
        }
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';
    document.body.append(textarea);
    textarea.focus();
    textarea.select();
    const copied = document.execCommand('copy');
    textarea.remove();
    if (!copied) throw new Error('Clipboard access was blocked.');
}

function flowFailureMessage(reason) {
    if (reason === 'composer-not-found') return 'Flow’s prompt box was not found. Nothing was inserted. Reload Flow or use “Copy this part to clipboard instead.”';
    if (reason === 'composer-not-empty') return 'Flow’s prompt box already contains text, so it was left untouched. Send or clear that text, then retry, or use “Copy this part to clipboard instead.”';
    if (reason === 'fill-failed') return 'Flow did not accept the prompt. Nothing was sent. Try again or use “Copy this part to clipboard instead.”';
    if (reason === 'not-flow') return 'Open Google Flow in the active tab to fill its prompt box. No text was sent.';
    if (reason === 'prompt-changed') return 'The active prompt changed while filling. Check Flow before continuing.';
    if (reason === 'empty-prompt') return 'This part is empty. Send the prompt from VeoPrompter again.';
    return 'The extension could not reach Flow. If the text is not in the prompt box, use “Copy this part to clipboard instead.”';
}

async function copyNextPart() {
    const prompt = popupState.activePrompt;
    const chunk = prompt?.chunks?.[prompt.nextIndex];
    if (!chunk) return;

    try {
        await copyText(chunk.text);
        prompt.nextIndex += 1;
        await chrome.storage.local.set({ activePrompt: prompt });
        popupState.activePrompt = prompt;
        renderPrompt(prompt);
        setStatus(`${chunk.label} copied to the clipboard. Paste it yourself when ready, then continue with the next part.`, 'success');
    } catch {
        setStatus('Clipboard access failed. Allow clipboard access for the extension and try again.', 'error');
    }
}

async function fillNextPartInFlow() {
    const prompt = popupState.activePrompt;
    const chunk = prompt?.chunks?.[prompt.nextIndex];
    if (!chunk || !activeTab?.id) return;

    let copiedToClipboard = false;
    try {
        await copyText(chunk.text);
        copiedToClipboard = true;
    } catch {
        // Flow insertion can still succeed even if the clipboard is unavailable.
    }

    try {
        const response = await chrome.runtime.sendMessage({
            type: 'VEOPROMPTER_FLOW_INSERT_NEXT',
            promptId: prompt.id,
            expectedIndex: prompt.nextIndex,
            tabId: activeTab.id,
        });
        if (!response?.ok) {
            setStatus(flowFailureMessage(response?.reason), 'error');
            return;
        }

        const clipboardNote = copiedToClipboard ? ' It is also on your clipboard.' : '';
        setStatus(`${response.label || chunk.label} is in Flow’s prompt box. Review it and click Flow’s Send button when ready; the extension has not sent it.${clipboardNote}`, 'success');
    } catch {
        setStatus(flowFailureMessage('flow-unavailable'), 'error');
    }
}

function navigationFeedback(undo) {
    if (undo?.result === 'cleared-unsent-text') return ' The matching unsent text was cleared from Flow.';
    if (undo?.result === 'already-sent') return ' Flow’s composer was already empty; messages in the chat were not changed.';
    if (undo?.reason === 'composer-changed') return ' Flow’s composer had changed, so it was left untouched.';
    if (undo?.reason === 'composer-not-found') return ' The Flow composer was not found, so no Flow text was changed.';
    if (undo?.reason === 'clear-failed') return ' Flow did not clear the matching text, so it may still be in the composer.';
    if (undo?.reason === 'not-flow') return ' The original tab is no longer a Flow page, so it was left untouched.';
    if (undo?.reason === 'flow-unavailable') return ' Flow could not be reached, so its text was left untouched.';
    return '';
}

async function goBackOnePart() {
    const prompt = popupState.activePrompt;
    if (!prompt || prompt.nextIndex <= 0) return;

    try {
        const response = await chrome.runtime.sendMessage({
            type: 'VEOPROMPTER_PROMPT_REWIND',
            promptId: prompt.id,
        });
        if (!response?.ok) {
            setStatus('The prompt sequence could not be rewound. Close and reopen the extension, then try again.', 'error');
            return;
        }

        await refresh();
        const promptAfterRewind = popupState.activePrompt;
        const chunk = promptAfterRewind?.chunks?.[response.nextIndex];
        setStatus(`Back to ${chunk?.label || 'the previous part'}. It is ready to fill or copy again.${navigationFeedback(response.undo)}`, 'info');
    } catch {
        setStatus('The prompt sequence could not be rewound. Close and reopen the extension, then try again.', 'error');
    }
}

async function restartPrompt() {
    const prompt = popupState.activePrompt;
    if (!prompt) return;

    try {
        const response = await chrome.runtime.sendMessage({
            type: 'VEOPROMPTER_PROMPT_RESTART',
            promptId: prompt.id,
        });
        if (!response?.ok) {
            setStatus('The prompt sequence could not be restarted. Close and reopen the extension, then try again.', 'error');
            return;
        }

        await refresh();
        setStatus(`Started over. Sections 1–7 are ready again.${navigationFeedback(response.undo)}`, 'success');
    } catch {
        setStatus('The prompt sequence could not be restarted. Close and reopen the extension, then try again.', 'error');
    }
}

let actionPending = false;

mainButton.addEventListener('click', async () => {
    if (actionPending) return;
    actionPending = true;
    mainButton.disabled = true;
    try {
        if (currentAction === 'connect-site') await connectCurrentSite();
        else if (currentAction === 'fill-flow') await fillNextPartInFlow();
        else if (currentAction === 'copy') await copyNextPart();
        await refresh();
    } finally {
        actionPending = false;
    }
});

backAction.addEventListener('click', async (event) => {
    event.preventDefault();
    if (actionPending) return;
    actionPending = true;
    try {
        await goBackOnePart();
    } finally {
        actionPending = false;
    }
});

restartAction.addEventListener('click', async (event) => {
    event.preventDefault();
    if (actionPending) return;
    actionPending = true;
    try {
        await restartPrompt();
    } finally {
        actionPending = false;
    }
});

copyFallbackAction.addEventListener('click', async (event) => {
    event.preventDefault();
    if (actionPending) return;
    actionPending = true;
    try {
        await copyNextPart();
    } finally {
        actionPending = false;
    }
});

refresh().catch(() => {
    showEmpty('Extension could not start', 'Close this popup and open it again. If the problem continues, reload the extension in chrome://extensions.');
    setMainAction('Extension unavailable', 'none', true);
});
