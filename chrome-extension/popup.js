const STORAGE_KEYS = ['connectedAppOrigins', 'activePrompt'];
const FLOW_URL = 'https://labs.google/fx/tools/flow/';
const FLOW_MATCHES = ['https://labs.google/fx/tools/flow*', 'https://flow.google/*'];
const APP_BRIDGE_ID = 'veoprompter-app-bridge';

const mainButton = document.querySelector('[data-main-action]');
const promptCard = document.querySelector('[data-prompt-card]');
const emptyState = document.querySelector('[data-empty-state]');
const statusMessage = document.querySelector('[data-status]');
const backAction = document.querySelector('[data-back-action]');
const restartAction = document.querySelector('[data-restart-action]');
const connectionList = document.querySelector('[data-connection-list]');
const progressTrack = document.querySelector('.progress-track');

let activeTab = null;
let popupState = { connectedAppOrigins: [], activePrompt: null };
let currentAction = 'none';

function isFlowUrl(value) {
    try {
        const url = new URL(value);
        return (url.hostname === 'labs.google' && url.pathname.startsWith('/fx/tools/flow'))
            || (url.hostname === 'flow.google');
    } catch {
        return false;
    }
}

function currentOrigin(value) {
    try {
        const url = new URL(value);
        return ['http:', 'https:'].includes(url.protocol) ? url.origin : null;
    } catch {
        return null;
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

function renderPrompt(prompt, onFlow, tab = activeTab) {
    promptCard.hidden = false;
    emptyState.hidden = true;
    document.querySelector('[data-prompt-title]').textContent = prompt.title || 'VeoPrompter prompt';
    document.querySelector('[data-prompt-notice]').textContent = prompt.notice || 'Your prompt is stored locally in this browser.';

    const total = prompt.chunks.length;
    const nextIndex = Math.min(Math.max(Number(prompt.nextIndex) || 0, 0), total);
    const percent = total ? Math.round((nextIndex / total) * 100) : 0;
    const progressBar = document.querySelector('[data-progress-bar]');
    progressBar.style.width = `${percent}%`;
    progressTrack.setAttribute('aria-valuemax', String(total || 1));
    progressTrack.setAttribute('aria-valuenow', String(nextIndex));
    document.querySelector('[data-progress-count]').textContent = total
        ? `Part ${Math.min(nextIndex + 1, total)} of ${total}`
        : 'No pasteable parts';

    const nextChunk = prompt.chunks[nextIndex];
    document.querySelector('[data-next-part]').textContent = nextChunk
        ? `Next: ${nextChunk.label}`
        : 'All parts pasted';

    const differentFlowTab = onFlow && prompt.flowTabId && prompt.flowTabId !== tab?.id;
    if (differentFlowTab) {
        setMainAction('Return to the Flow chat in use', 'resume-flow');
    } else if (onFlow) {
        if (nextChunk) {
            setMainAction(`Paste ${nextChunk.label}`, 'paste');
        } else {
            setMainAction('All parts pasted', 'none', true);
        }
    } else {
        setMainAction('Open Google Flow', 'open-flow');
    }

    backAction.hidden = nextIndex === 0;
    restartAction.hidden = nextIndex === 0;
    document.querySelector('[data-usage-note]').textContent = differentFlowTab
        ? 'This sequence stays in the Flow tab where it began. Return there so Flow keeps the project context.'
        : onFlow
            ? 'Paste fills Flow’s prompt box only. Review and send each message in Flow yourself.'
            : 'Open Flow to continue. Each click fills its prompt box with one part; messages are not sent automatically.';
}

async function refresh() {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    activeTab = tab || null;
    popupState = await chrome.storage.local.get(STORAGE_KEYS);
    popupState.connectedAppOrigins ||= [];
    popupState.activePrompt ||= null;
    updateConnectionList(popupState.connectedAppOrigins);

    const onFlow = Boolean(activeTab?.url && isFlowUrl(activeTab.url));
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
    const needsConnection = Boolean(appOrigin && !onFlow && !isConnectedApp && isLikelyVeoPrompterPage(activeTab));
    const prompt = popupState.activePrompt;

    if (prompt?.flowTabId) {
        try {
            await chrome.tabs.get(prompt.flowTabId);
        } catch {
            prompt.flowTabId = null;
            prompt.nextIndex = 0;
            prompt.lastPaste = null;
            await chrome.storage.local.set({ activePrompt: prompt });
            setStatus('The Flow tab for this sequence was closed. Restarted at the project context.', 'info');
        }
    }

    if (prompt) {
        renderPrompt(prompt, onFlow, activeTab);
        return;
    }

    showEmpty(
        needsConnection ? 'Connect this VeoPrompter site' : 'Send a prompt to Flow',
        needsConnection
            ? 'Allow this extension to receive prompts from the VeoPrompter site open in this tab.'
            : onFlow
                ? 'No prompt is loaded yet. Generate one in VeoPrompter and press Send to Flow.'
                : 'Generate a prompt in VeoPrompter, then use its Send to Flow button. Your prompt stays in this browser.',
    );

    if (needsConnection) {
        setMainAction('Connect this site', 'connect-site');
        document.querySelector('[data-usage-note]').textContent = 'You will be asked to approve this site. The extension does not read other websites.';
    } else if (onFlow) {
        setMainAction('Waiting for a VeoPrompter prompt', 'none', true);
    } else {
        setMainAction('Send a prompt from VeoPrompter first', 'none', true);
    }

    backAction.hidden = true;
    restartAction.hidden = true;
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
    if (!origin || isFlowUrl(activeTab.url)) {
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

async function openFlow() {
    try {
        const targetTabId = popupState.activePrompt?.flowTabId;
        if (targetTabId) {
            await chrome.tabs.update(targetTabId, { active: true });
            return;
        }

        const tabs = await chrome.tabs.query({ currentWindow: true, url: FLOW_MATCHES });
        if (tabs.length) {
            await chrome.tabs.update(tabs[0].id, { active: true });
        } else {
            await chrome.tabs.create({ url: FLOW_URL, active: true });
        }
    } catch {
        setStatus('Google Flow could not be opened. Open labs.google/fx/tools/flow and try again.', 'error');
    }
}

function flowError(reason) {
    if (reason === 'composer-not-found') return 'Flow’s prompt box was not found. Wait for Flow to finish loading, then try again.';
    if (reason === 'composer-not-empty') return 'Flow’s prompt box already has text. Send or clear it before pasting the next part.';
    if (reason === 'composer-changed') return 'The Flow prompt box has changed. It was left untouched; clear it before going back.';
    if (reason === 'paste-failed' || reason === 'undo-failed') return 'Flow did not accept the text. Click in its prompt box and try again.';
    if (reason === 'empty-prompt') return 'This part is empty. Send the prompt from VeoPrompter again.';
    return 'The extension could not reach Flow. Refresh the Flow tab and try again.';
}

async function resumeFlow() {
    const tabId = popupState.activePrompt?.flowTabId;
    if (!tabId) {
        setStatus('The original Flow tab is no longer available. Start over to use a new Flow chat.', 'error');
        return;
    }

    try {
        await chrome.tabs.update(tabId, { active: true });
    } catch {
        setStatus('The original Flow tab is no longer available. Start over to use a new Flow chat.', 'error');
    }
}

async function pasteNext() {
    const prompt = popupState.activePrompt;
    const chunk = prompt?.chunks?.[prompt.nextIndex];
    if (!chunk || !activeTab?.id) return;
    if (prompt.flowTabId && prompt.flowTabId !== activeTab.id) {
        setStatus('Return to the Flow tab where this sequence began before pasting the next part.', 'error');
        return;
    }

    try {
        const result = await chrome.tabs.sendMessage(activeTab.id, {
            type: 'VEOPROMPTER_FLOW_PASTE',
            text: chunk.text,
        });
        if (!result?.ok) {
            setStatus(flowError(result?.reason), 'error');
            return;
        }

        prompt.flowTabId = activeTab.id;
        prompt.lastPaste = {
            index: prompt.nextIndex,
            insertedText: chunk.text,
            previousText: result.previousText || '',
            tabId: activeTab.id,
            pastedAt: new Date().toISOString(),
        };
        prompt.nextIndex += 1;
        await chrome.storage.local.set({ activePrompt: prompt });
        popupState.activePrompt = prompt;
        renderPrompt(prompt, true);
        setStatus(`${chunk.label} is in Flow. Send it there, then continue with the next part.`, 'success');
    } catch {
        setStatus(flowError('flow-unavailable'), 'error');
    }
}

async function goBackOnePart() {
    const prompt = popupState.activePrompt;
    if (!prompt || prompt.nextIndex <= 0) return;

    const targetIndex = prompt.nextIndex - 1;
    const lastPaste = prompt.lastPaste;
    let note = 'Messages already sent in Flow remain in that conversation.';

    if (lastPaste?.index === targetIndex && Number.isInteger(lastPaste.tabId)) {
        try {
            const result = await chrome.tabs.sendMessage(lastPaste.tabId, {
                type: 'VEOPROMPTER_FLOW_UNDO',
                insertedText: lastPaste.insertedText,
                previousText: lastPaste.previousText,
            });
            if (result?.reason === 'composer-changed') {
                note = 'The Flow prompt box was changed, so it was left untouched. Clear it before pasting the previous part.';
            } else if (result?.result === 'cleared-unsent-paste') {
                note = 'The unsent text was removed from Flow. You can paste this part again.';
            }
        } catch {
            note = 'Flow was not available to undo the unsent text. The paste position was moved back.';
        }
    }

    prompt.nextIndex = targetIndex;
    prompt.lastPaste = null;
    await chrome.storage.local.set({ activePrompt: prompt });
    popupState.activePrompt = prompt;
    renderPrompt(prompt, Boolean(activeTab?.url && isFlowUrl(activeTab.url)));
    const chunk = prompt.chunks[targetIndex];
    setStatus(`Back to ${chunk?.label || 'the previous part'}. ${note}`, 'info');
}

async function restartPrompt() {
    const prompt = popupState.activePrompt;
    if (!prompt) return;

    if (prompt.lastPaste?.tabId && prompt.lastPaste.insertedText) {
        try {
            await chrome.tabs.sendMessage(prompt.lastPaste.tabId, {
                type: 'VEOPROMPTER_FLOW_UNDO',
                insertedText: prompt.lastPaste.insertedText,
                previousText: prompt.lastPaste.previousText,
            });
        } catch {
            // The prompt position can still be reset even if its Flow tab was closed.
        }
    }

    prompt.nextIndex = 0;
    prompt.lastPaste = null;
    await chrome.storage.local.set({ activePrompt: prompt });
    popupState.activePrompt = prompt;
    renderPrompt(prompt, Boolean(activeTab?.url && isFlowUrl(activeTab.url)));
    setStatus('Started over. The first part is ready to paste again.', 'success');
}

mainButton.addEventListener('click', async () => {
    mainButton.disabled = true;
    if (currentAction === 'connect-site') await connectCurrentSite();
    else if (currentAction === 'open-flow') await openFlow();
    else if (currentAction === 'resume-flow') await resumeFlow();
    else if (currentAction === 'paste') await pasteNext();
    await refresh();
});

backAction.addEventListener('click', async (event) => {
    event.preventDefault();
    await goBackOnePart();
});

restartAction.addEventListener('click', async (event) => {
    event.preventDefault();
    await restartPrompt();
});

refresh().catch(() => {
    showEmpty('Extension could not start', 'Close this popup and open it again. If the problem continues, reload the extension in chrome://extensions.');
    setMainAction('Extension unavailable', 'none', true);
});
