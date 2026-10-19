export function flowComposerAction(action, text) {
    const normalize = (value) => String(value || '').replace(/\r\n?/g, '\n').replace(/\u00a0/g, ' ').trim();
    const isVisible = (element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== 'none'
            && style.visibility !== 'hidden'
            && Number(style.opacity) !== 0
            && rect.width > 0
            && rect.height > 0;
    };
    const isTextControl = (element) => element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement;
    const composerText = (element) => isTextControl(element) ? element.value : element.innerText || element.textContent || '';

    const findComposer = () => [...document.querySelectorAll('textarea, [contenteditable="true"], [role="textbox"], input[type="text"]')]
        .filter((element) => isVisible(element) && !element.disabled && !element.readOnly)
        .map((element) => {
            const descriptor = [
                element.getAttribute('aria-label'),
                element.getAttribute('placeholder'),
                element.getAttribute('data-placeholder'),
                element.getAttribute('title'),
                element.getAttribute('name'),
                element.getAttribute('role'),
                element.className,
            ].join(' ').toLowerCase();
            const editable = isTextControl(element) || element.isContentEditable;
            if (!editable || /search|filter|feedback|settings|rename/.test(descriptor)) {
                return { element, score: -100 };
            }

            let score = element.isContentEditable ? 6 : element instanceof HTMLTextAreaElement ? 5 : 1;
            if (/prompt|describe|create|video|imagine|write|message|type/.test(descriptor)) score += 12;
            if (element.getAttribute('role') === 'textbox') score += 3;
            if (element.getAttribute('aria-multiline') === 'true') score += 2;
            if (element.className?.toString().toLowerCase().includes('prosemirror')) score += 5;
            return { element, score };
        })
        .filter((candidate) => candidate.score > 0)
        .sort((left, right) => right.score - left.score)[0]?.element || null;

    const dispatchInput = (element, value) => {
        let event;
        try {
            event = new InputEvent('input', {
                bubbles: true,
                inputType: value ? 'insertText' : 'deleteContentBackward',
                data: value || null,
            });
        } catch {
            event = new Event('input', { bubbles: true });
        }
        element.dispatchEvent(event);
    };

    const writeText = (element, value) => {
        try {
            element.focus({ preventScroll: true });
        } catch {
            element.focus();
        }

        if (isTextControl(element)) {
            const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set;
            if (!setter) return false;
            setter.call(element, value);
            dispatchInput(element, value);
            element.dispatchEvent(new Event('change', { bubbles: true }));
            return normalize(composerText(element)) === normalize(value);
        }

        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(element);
        if (value) range.collapse(false);
        selection?.removeAllRanges();
        selection?.addRange(range);

        let changed = false;
        try {
            changed = document.execCommand(value ? 'insertText' : 'delete', false, value);
        } catch {
            changed = false;
        }

        if (!changed || normalize(composerText(element)) !== normalize(value)) {
            element.replaceChildren(...(value ? [document.createTextNode(value)] : []));
            dispatchInput(element, value);
        }

        return normalize(composerText(element)) === normalize(value);
    };

    if (action !== 'fill' && action !== 'clear-if-matching') {
        return { ok: false, reason: 'unsupported-action' };
    }

    if (typeof text !== 'string' || (action === 'fill' && !text.trim())) {
        return { ok: false, reason: 'empty-prompt' };
    }

    const composer = findComposer();
    if (!composer) return { ok: false, reason: 'composer-not-found' };

    const priorText = composerText(composer);
    if (action === 'fill') {
        if (normalize(priorText)) return { ok: false, reason: 'composer-not-empty' };
        if (!writeText(composer, text)) return { ok: false, reason: 'fill-failed' };
        return { ok: true, result: 'filled' };
    }

    if (!normalize(priorText)) return { ok: true, result: 'already-sent' };
    if (normalize(priorText) !== normalize(text)) return { ok: false, reason: 'composer-changed' };
    if (!writeText(composer, '')) return { ok: false, reason: 'clear-failed' };
    return { ok: true, result: 'cleared-unsent-text' };
}
