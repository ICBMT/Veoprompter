const STORAGE_KEYS = ['connectedAppOrigins', 'activePrompt'];
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

function renderPrompt(prompt) {
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
        : 'No copyable parts';

    const nextChunk = prompt.chunks[nextIndex];
    document.querySelector('[data-next-part]').textContent = nextChunk
        ? `Next: ${nextChunk.label}`
        : 'All parts copied';

    if (nextChunk) {
        setMainAction(`Copy ${nextChunk.label}`, 'copy');
    } else {
        setMainAction('All parts copied', 'none', true);
    }

    backAction.hidden = nextIndex === 0;
    restartAction.hidden = nextIndex === 0;
    document.querySelector('[data-usage-note]').textContent = nextChunk
        ? 'Copy each part in order, then paste it into Flow or another destination. Flow does not need to be open while you prepare the sequence.'
        : 'All parts are copied. Paste them into Flow in order; sent messages are controlled by you.';
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
        document.querySelector('[data-usage-note]').textContent = 'Once a prompt is stored, you can copy its parts in order without opening Flow.';
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
        setStatus(`${chunk.label} copied. Paste it wherever you need it, then copy the next part.`, 'success');
    } catch {
        setStatus('Clipboard access failed. Allow clipboard access for the extension and try again.', 'error');
    }
}

async function goBackOnePart() {
    const prompt = popupState.activePrompt;
    if (!prompt || prompt.nextIndex <= 0) return;

    prompt.nextIndex -= 1;
    await chrome.storage.local.set({ activePrompt: prompt });
    popupState.activePrompt = prompt;
    renderPrompt(prompt);
    const chunk = prompt.chunks[prompt.nextIndex];
    setStatus(`Back to ${chunk?.label || 'the previous part'}. Click the main action to copy it again.`, 'info');
}

async function restartPrompt() {
    const prompt = popupState.activePrompt;
    if (!prompt) return;

    prompt.nextIndex = 0;
    await chrome.storage.local.set({ activePrompt: prompt });
    popupState.activePrompt = prompt;
    renderPrompt(prompt);
    setStatus('Started over. The first part is ready to copy.', 'success');
}

mainButton.addEventListener('click', async () => {
    mainButton.disabled = true;
    if (currentAction === 'connect-site') await connectCurrentSite();
    else if (currentAction === 'copy') await copyNextPart();
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
