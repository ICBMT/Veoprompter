import { copyableChunk } from './prompt-parser.js';
import { armPasteOnNextField } from './field-paste.js';
import { savedRunReviewLink } from './repair-link.js';

const STORAGE_KEYS = ['connectedAppOrigins', 'activePrompt'];
const APP_BRIDGE_ID = 'veoprompter-app-bridge';
const ACTIVE_PROMPT_KEY = 'activePrompt';

const mainButton = document.querySelector('[data-main-action]');
const promptCard = document.querySelector('[data-prompt-card]');
const emptyState = document.querySelector('[data-empty-state]');
const statusMessage = document.querySelector('[data-status]');
const backAction = document.querySelector('[data-back-action]');
const restartAction = document.querySelector('[data-restart-action]');
const connectionList = document.querySelector('[data-connection-list]');
const progressTrack = document.querySelector('.progress-track');
const characterCast = document.querySelector('[data-character-cast]');
const characterCastValue = document.querySelector('[data-character-cast-value]');
const handoffWarning = document.querySelector('[data-handoff-warning]');
const repairLinkWrap = document.querySelector('[data-repair-link-wrap]');
const repairLink = document.querySelector('[data-repair-link]');
const handoffCue = document.querySelector('[data-handoff-cue]');
const handoffKind = document.querySelector('[data-handoff-kind]');
const handoffGuidance = document.querySelector('[data-handoff-guidance]');
const outgoingHandoff = document.querySelector('[data-handoff-outgoing]');

let activeTab = null;
let popupState = { connectedAppOrigins: [], activePrompt: null };
let currentAction = 'none';
let actionPending = false;

function totalClipNumber(chunks) {
    return chunks.filter((chunk) => chunk.kind === 'clip').length;
}

function currentOrigin(value) {
    try {
        const url = new URL(value);
        return ['http:', 'https:'].includes(url.protocol) ? url.origin : null;
    } catch {
        return null;
    }
}

function canInjectIntoTab(tab) {
    try {
        const url = new URL(tab?.url);
        return ['http:', 'https:'].includes(url.protocol) && Number.isInteger(tab?.id);
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

    const warnings = Array.isArray(prompt.handoffWarnings) ? prompt.handoffWarnings : [];
    handoffWarning.hidden = warnings.length === 0;
    handoffWarning.textContent = warnings.length
        ? `Prompt check: ${warnings.join(' ')}`
        : '';

    const savedRunUrl = savedRunReviewLink(
        prompt.sourceOrigin,
        prompt.repairRunId,
        popupState.connectedAppOrigins,
    );
    repairLinkWrap.hidden = !savedRunUrl;
    if (savedRunUrl) repairLink.href = savedRunUrl;
    else repairLink.removeAttribute('href');

    const notesWrap = document.querySelector('[data-continuity-notes]');
    notesWrap.hidden = !prompt.continuityNotes;
    notesWrap.querySelector('pre').textContent = prompt.continuityNotes || '';
    const chunks = Array.isArray(prompt.chunks) ? prompt.chunks : [];
    const total = chunks.length;
    const nextIndex = Math.min(Math.max(Number(prompt.nextIndex) || 0, 0), total);
    const percent = total ? Math.round((nextIndex / total) * 100) : 0;
    const progressBar = document.querySelector('[data-progress-bar]');
    progressBar.style.width = `${percent}%`;
    progressTrack.setAttribute('aria-valuemax', String(total || 1));
    progressTrack.setAttribute('aria-valuenow', String(nextIndex));
    document.querySelector('[data-progress-count]').textContent = total
        ? `Part ${Math.min(nextIndex + 1, total)} of ${total}`
        : 'No copyable parts';

    const nextChunk = chunks[nextIndex];
    document.querySelector('[data-next-part]').textContent = nextChunk
        ? `Next: ${nextChunk.label}`
        : 'All parts copied';
    const visibleCast = typeof nextChunk?.visibleCast === 'string' ? nextChunk.visibleCast.trim() : '';
    characterCast.hidden = visibleCast === '';
    characterCastValue.textContent = visibleCast;

    const handoffGuidanceByType = {
        A: ['TYPE A · DIRECT CONTINUATION', 'Open on the exact prior ending; preserve cast, object/process state, motion, and camera without a reset.'],
        B: ['TYPE B · NATURAL ACTION TRANSITION', 'Show the full visible physical action that bridges the prior and next states.'],
        C: ['TYPE C · NARRATIVE / TEMPORAL', 'Mark an intentional, non-seamless before → visible change → after progression.'],
    };
    let incomingHandoff = null;
    if (nextChunk?.kind === 'extension') {
        incomingHandoff = ['EXTENSION FROM VISIBLE FRAME', 'Continue only from details visible in the supplied frame or stated in the brief; do not infer unseen events.'];
    } else if (nextChunk?.kind === 'clip' && nextChunk.transitionType) {
        incomingHandoff = handoffGuidanceByType[nextChunk.transitionType] || null;
    } else if (nextChunk?.kind === 'clip' && Number(nextChunk.clipNumber) === 1) {
        incomingHandoff = ['INITIAL CLIP', 'Establish the opening cast, scene, and exact visible starting state.'];
    } else if (nextChunk?.kind === 'clip' && Number(nextChunk.clipNumber) > 1) {
        incomingHandoff = ['INCOMING HANDOFF TYPE MISSING', 'Review the previous ending and this clip’s opening; do not assume a seamless continuation.'];
    }

    handoffCue.hidden = !incomingHandoff;
    handoffCue.classList.toggle('handoff-cue--warning', incomingHandoff?.[0] === 'INCOMING HANDOFF TYPE MISSING');
    handoffKind.textContent = incomingHandoff?.[0] || '';
    handoffGuidance.textContent = incomingHandoff?.[1] || '';
    const outgoingType = nextChunk?.outgoingTransitionType;
    outgoingHandoff.textContent = outgoingType === 'FINAL'
        ? 'Outgoing handoff: final clip — no next handoff.'
        : ['A', 'B', 'C'].includes(outgoingType)
            ? `Outgoing handoff: Type ${outgoingType}${nextChunk.clipNumber === totalClipNumber(chunks) ? '' : ' · must match the next clip’s incoming type'}.`
            : '';
    outgoingHandoff.hidden = outgoingHandoff.textContent === '';

    if (nextChunk) {
        setMainAction(`Copy ${nextChunk.label}`, 'copy');
    } else {
        setMainAction('All parts copied', 'none', true);
    }

    const pendingPaste = prompt.pendingPaste;
    const pendingLabel = pendingPaste?.label || 'the copied part';
    if (pendingPaste && pendingPaste.tabId === activeTab?.id) {
        document.querySelector('[data-usage-note]').textContent = `Click a text field on this tab to paste ${pendingLabel} once. Clipboard paste remains available; copying another part replaces this pending paste.`;
    } else if (pendingPaste) {
        document.querySelector('[data-usage-note]').textContent = `${pendingLabel} is armed on another tab. Return to that tab and click an editable text field, or use the clipboard manually.`;
    } else if (nextChunk) {
        document.querySelector('[data-usage-note]').textContent = 'Copy places the part on your clipboard and arms a one-time paste on this tab. Click a text field to insert it; you still choose when to send or submit.';
    } else {
        document.querySelector('[data-usage-note]').textContent = 'All parts are copied. You control when to submit or send them; the extension never submits or starts generation.';
    }

    backAction.hidden = nextIndex === 0;
    restartAction.hidden = nextIndex === 0;
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
        needsConnection ? 'Connect this VeoPrompter site' : 'Send a prompt to the extension',
        needsConnection
            ? 'Allow this extension to receive prompts from the VeoPrompter site open in this tab.'
            : 'Generate a prompt in VeoPrompter, then use its Send to Flow button. Your prompt stays in this browser.',
    );

    if (needsConnection) {
        setMainAction('Connect this site', 'connect-site');
        document.querySelector('[data-usage-note]').textContent = 'You will be asked to approve only this VeoPrompter origin. Destination-tab paste uses temporary activeTab access after you click Copy.';
    } else {
        setMainAction('Send a prompt from VeoPrompter first', 'none', true);
        document.querySelector('[data-usage-note]').textContent = 'Once a prompt is stored, open the extension on the destination tab, click Copy, then click a text field to paste it once.';
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
        setStatus('Connected. Return to VeoPrompter and press Send to Flow on a generated or saved prompt.', 'success');
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

function createPasteToken() {
    return window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

async function clearPendingPaste(promptId, token, restoreIndex = null) {
    const { [ACTIVE_PROMPT_KEY]: latest = null } = await chrome.storage.local.get(ACTIVE_PROMPT_KEY);
    if (!latest || latest.id !== promptId || latest.pendingPaste?.token !== token) return;

    latest.pendingPaste = null;
    if (restoreIndex !== null) latest.nextIndex = restoreIndex;
    await chrome.storage.local.set({ [ACTIVE_PROMPT_KEY]: latest });
    popupState.activePrompt = latest;
    renderPrompt(latest);
}

async function copyNextPart() {
    const prompt = popupState.activePrompt;
    const index = Number(prompt?.nextIndex);
    const chunk = copyableChunk(prompt?.chunks?.[index]);
    if (!chunk) return;

    let copiedToClipboard = false;
    try {
        await copyText(chunk.text);
        copiedToClipboard = true;
    } catch {
        // Injection may still succeed on ordinary web pages if clipboard access is blocked.
    }

    const scriptable = canInjectIntoTab(activeTab);
    if (!copiedToClipboard && !scriptable) {
        setStatus('Clipboard access failed, and this page does not allow automatic paste. Open a regular website or allow clipboard access, then try again.', 'error');
        return;
    }

    const token = createPasteToken();
    const replacedPending = Boolean(prompt.pendingPaste);
    let latest;
    try {
        const stored = await chrome.storage.local.get(ACTIVE_PROMPT_KEY);
        latest = stored[ACTIVE_PROMPT_KEY];
        if (!latest || latest.id !== prompt.id || latest.nextIndex !== index) {
            setStatus(copiedToClipboard
                ? `${chunk.label} is on your clipboard, but the active prompt changed. Paste it manually if needed.`
                : 'The active prompt changed before automatic paste could be prepared. Nothing was inserted.', 'error');
            return;
        }

        latest.nextIndex = index + 1;
        latest.pendingPaste = scriptable ? {
            token,
            index,
            tabId: activeTab.id,
            label: chunk.label,
            armedAt: new Date().toISOString(),
        } : null;
        latest.lastInserted = null;
        await chrome.storage.local.set({ [ACTIVE_PROMPT_KEY]: latest });
        popupState.activePrompt = latest;
        renderPrompt(latest);
    } catch {
        setStatus(copiedToClipboard
            ? `${chunk.label} is on your clipboard, but copy progress could not be saved.`
            : 'The prompt could not be prepared. Check extension storage and try again.', 'error');
        return;
    }

    if (!scriptable) {
        setStatus(copiedToClipboard
            ? `${chunk.label} copied. This browser page does not permit automatic paste; use the clipboard manually.`
            : `${chunk.label} is ready, but automatic paste is unavailable on this browser page.`, 'info');
        return;
    }

    try {
        const [execution] = await chrome.scripting.executeScript({
            target: { tabId: activeTab.id },
            func: armPasteOnNextField,
            args: [chunk.text, latest.id, index, token, activeTab.id],
        });
        if (execution?.result?.ok !== true) throw new Error('Could not arm one-time paste.');

        const prefix = replacedPending ? 'The previous pending paste was replaced. ' : '';
        setStatus(copiedToClipboard
            ? `${prefix}${chunk.label} copied. Click a text field on this tab to paste it once; you choose when to submit.`
            : `${prefix}${chunk.label} is armed for this tab. Click a text field to paste it once; clipboard copy was blocked.`, 'success');
    } catch {
        try {
            await clearPendingPaste(latest.id, token, copiedToClipboard ? null : index);
        } catch {
            // The clipboard remains a usable fallback even if extension storage also fails.
        }
        setStatus(copiedToClipboard
            ? `${chunk.label} is on your clipboard, but automatic paste could not be armed. Paste it manually.`
            : 'Automatic paste could not be armed and clipboard access failed. No prompt was inserted.', 'error');
    }
}

async function navigatePrompt(targetIndex, message) {
    const prompt = popupState.activePrompt;
    if (!prompt) return;

    try {
        const stored = await chrome.storage.local.get(ACTIVE_PROMPT_KEY);
        const latest = stored[ACTIVE_PROMPT_KEY];
        if (!latest || latest.id !== prompt.id) {
            setStatus('The active prompt changed. Reopen it from VeoPrompter and try again.', 'error');
            return;
        }

        const hadPendingPaste = Boolean(latest.pendingPaste);
        const total = Array.isArray(latest.chunks) ? latest.chunks.length : 0;
        latest.nextIndex = Math.max(0, Math.min(targetIndex, total));
        latest.pendingPaste = null;
        latest.lastInserted = null;
        await chrome.storage.local.set({ [ACTIVE_PROMPT_KEY]: latest });
        popupState.activePrompt = latest;
        renderPrompt(latest);
        setStatus(`${message}${hadPendingPaste ? ' Any pending one-time paste was cancelled; text already inserted into a page is left unchanged.' : ''}`, 'info');
    } catch {
        setStatus('The prompt sequence could not be updated. Your saved prompt is still intact; try again.', 'error');
    }
}

async function goBackOnePart() {
    const prompt = popupState.activePrompt;
    const nextIndex = Number(prompt?.nextIndex) || 0;
    if (!prompt || nextIndex <= 0) return;

    const targetIndex = Math.max(0, nextIndex - 1);
    const chunk = copyableChunk(prompt.chunks?.[targetIndex]);
    await navigatePrompt(targetIndex, `Back to ${chunk?.label || 'the previous part'}. It is ready to copy again.`);
}

async function restartPrompt() {
    if (!popupState.activePrompt) return;
    await navigatePrompt(0, 'Started over. Sections 1–7 are ready again.');
}

mainButton.addEventListener('click', async () => {
    if (actionPending) return;
    actionPending = true;
    mainButton.disabled = true;
    try {
        if (currentAction === 'connect-site') await connectCurrentSite();
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

refresh().catch(() => {
    showEmpty('Extension could not start', 'Close this popup and open it again. If the problem continues, reload the extension in chrome://extensions.');
    setMainAction('Extension unavailable', 'none', true);
});
