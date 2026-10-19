export function armPasteOnNextField(text, promptId, expectedIndex, token, tabId) {
    if (typeof text !== 'string' || !text || !promptId || !token || !Number.isInteger(expectedIndex)) {
        return { ok: false, reason: 'invalid-paste-request' };
    }

    const textInputTypes = new Set(['text', 'search', 'url', 'email', 'tel']);
    const isEditable = (element) => {
        if (!element || element.nodeType !== 1 || element.disabled || element.readOnly) return false;
        if (element.getAttribute('aria-disabled') === 'true' || element.getAttribute('aria-readonly') === 'true') return false;

        const tagName = element.tagName?.toLowerCase();
        if (tagName === 'textarea') return true;
        if (tagName === 'input') return textInputTypes.has((element.type || 'text').toLowerCase());

        return element.isContentEditable
            || ['true', 'plaintext-only'].includes((element.getAttribute('contenteditable') || '').toLowerCase());
    };

    const findEditableTarget = (event) => {
        const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
        for (const item of path) {
            if (isEditable(item)) return item;
        }

        let element = event.target?.nodeType === 1 ? event.target : event.target?.parentElement;
        while (element && element !== document.documentElement) {
            if (isEditable(element)) return element;
            element = element.parentElement;
        }
        return null;
    };

    const dispatchInput = (element, value) => {
        let event;
        try {
            event = new InputEvent('input', {
                bubbles: true,
                inputType: 'insertText',
                data: value,
            });
        } catch {
            event = new Event('input', { bubbles: true });
        }
        element.dispatchEvent(event);
    };

    const insertAtCaret = (element) => {
        if (!element.isConnected || !isEditable(element)) return false;

        const tagName = element.tagName.toLowerCase();
        if (tagName === 'input' || tagName === 'textarea') {
            const value = String(element.value || '');
            let start = value.length;
            let end = value.length;
            try {
                if (Number.isInteger(element.selectionStart)) start = element.selectionStart;
                if (Number.isInteger(element.selectionEnd)) end = element.selectionEnd;
            } catch {
                // Some input types do not expose a text selection; append in that case.
            }
            start = Math.max(0, Math.min(start, value.length));
            end = Math.max(start, Math.min(end, value.length));

            const prototype = tagName === 'textarea' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set;
            if (!setter) return false;

            const nextValue = `${value.slice(0, start)}${text}${value.slice(end)}`;
            setter.call(element, nextValue);
            try {
                element.setSelectionRange(start + text.length, start + text.length);
            } catch {
                // Text values still get inserted when selection APIs are unavailable.
            }
            dispatchInput(element, text);
            return true;
        }

        try {
            element.focus({ preventScroll: true });
        } catch {
            element.focus();
        }

        const selection = window.getSelection();
        let range = null;
        if (selection?.rangeCount) {
            const currentRange = selection.getRangeAt(0);
            const container = currentRange.commonAncestorContainer;
            const ancestor = container.nodeType === 1 ? container : container.parentElement;
            if (ancestor && (ancestor === element || element.contains(ancestor))) range = currentRange.cloneRange();
        }

        if (!range) {
            range = document.createRange();
            range.selectNodeContents(element);
            range.collapse(false);
        }

        selection?.removeAllRanges();
        selection?.addRange(range);
        try {
            if (document.execCommand('insertText', false, text)) return true;
        } catch {
            // Use a Range insertion for contenteditable implementations without insertText support.
        }

        range.deleteContents();
        const textNode = document.createTextNode(text);
        range.insertNode(textNode);
        range.setStartAfter(textNode);
        range.collapse(true);
        selection?.removeAllRanges();
        selection?.addRange(range);
        dispatchInput(element, text);
        return true;
    };

    const matchesPendingPaste = (activePrompt) => activePrompt?.id === promptId
        && activePrompt.nextIndex === expectedIndex + 1
        && activePrompt.pendingPaste?.token === token
        && activePrompt.pendingPaste?.index === expectedIndex
        && activePrompt.pendingPaste?.tabId === tabId;

    const listenerSlot = '__veoprompterActiveOneTimePaste';
    try {
        if (typeof window[listenerSlot] === 'function') window[listenerSlot]();
    } catch {
        // A stale listener is harmless because every paste is also checked against storage.
    }

    let processingClick = false;
    let onClick;
    const removeListener = () => {
        document.removeEventListener('click', onClick, true);
        if (window[listenerSlot] === removeListener) delete window[listenerSlot];
    };
    onClick = (event) => {
        if (processingClick) return;
        const target = findEditableTarget(event);
        if (!target) return;
        processingClick = true;

        window.setTimeout(async () => {
            try {
                const { activePrompt = null } = await chrome.storage.local.get('activePrompt');
                if (!matchesPendingPaste(activePrompt)) {
                    removeListener();
                    return;
                }

                if (!insertAtCaret(target)) {
                    processingClick = false;
                    return;
                }

                removeListener();
                activePrompt.pendingPaste = null;
                await chrome.storage.local.set({ activePrompt });
            } catch {
                processingClick = false;
            }
        }, 0);
    };

    document.addEventListener('click', onClick, true);
    window[listenerSlot] = removeListener;
    return { ok: true, armed: true };
}
