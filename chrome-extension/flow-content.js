(() => {
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

    const composerText = (element) => {
        if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) return element.value;
        return element.innerText || element.textContent || '';
    };

    const findComposer = () => {
        const candidates = [...document.querySelectorAll('textarea, [contenteditable="true"], [role="textbox"], input[type="text"]')]
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
                const isEditable = element instanceof HTMLTextAreaElement
                    || element instanceof HTMLInputElement
                    || element.isContentEditable;
                if (!isEditable) return { element, score: -100 };
                if (/search|filter|feedback|settings|rename/.test(descriptor)) return { element, score: -100 };

                let score = element.isContentEditable ? 6 : element instanceof HTMLTextAreaElement ? 5 : 1;
                if (/prompt|describe|create|video|imagine|write|message|type/.test(descriptor)) score += 12;
                if (element.getAttribute('role') === 'textbox') score += 3;
                if (element.getAttribute('aria-multiline') === 'true') score += 2;
                if (element.className?.toString().toLowerCase().includes('prosemirror')) score += 5;

                return { element, score };
            })
            .filter((candidate) => candidate.score > 0)
            .sort((left, right) => right.score - left.score);

        return candidates[0]?.element || null;
    };

    const dispatchInput = (element, text) => {
        let event;
        try {
            event = new InputEvent('input', {
                bubbles: true,
                inputType: 'insertText',
                data: text,
            });
        } catch {
            event = new Event('input', { bubbles: true });
        }
        element.dispatchEvent(event);
    };

    const writeText = (element, text) => {
        element.focus({ preventScroll: true });

        if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
            const prototype = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set;
            if (!setter) return false;
            setter.call(element, text);
            dispatchInput(element, text);
            element.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        }

        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(element);
        if (text) range.collapse(false);
        selection?.removeAllRanges();
        selection?.addRange(range);

        let changed = false;
        try {
            changed = document.execCommand(text ? 'insertText' : 'delete', false, text);
        } catch {
            changed = false;
        }

        if (!changed || normalize(composerText(element)) !== normalize(text)) {
            element.replaceChildren(...(text ? [document.createTextNode(text)] : []));
            dispatchInput(element, text);
        }

        return normalize(composerText(element)) === normalize(text);
    };

    const sendResult = (sendResponse, response) => {
        sendResponse(response);
        return false;
    };

    chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
        if (message?.type === 'VEOPROMPTER_FLOW_PASTE') {
            if (typeof message.text !== 'string' || !message.text.trim()) {
                return sendResult(sendResponse, { ok: false, reason: 'empty-prompt' });
            }

            const composer = findComposer();
            if (!composer) {
                return sendResult(sendResponse, { ok: false, reason: 'composer-not-found' });
            }

            const priorText = composerText(composer);
            if (normalize(priorText)) {
                return sendResult(sendResponse, { ok: false, reason: 'composer-not-empty' });
            }

            if (!writeText(composer, message.text)) {
                return sendResult(sendResponse, { ok: false, reason: 'paste-failed' });
            }

            return sendResult(sendResponse, {
                ok: true,
                previousText: priorText,
                insertedText: message.text,
            });
        }

        if (message?.type === 'VEOPROMPTER_FLOW_UNDO') {
            const composer = findComposer();
            if (!composer) {
                return sendResult(sendResponse, { ok: false, reason: 'composer-not-found' });
            }

            const currentText = composerText(composer);
            if (!normalize(currentText)) {
                return sendResult(sendResponse, { ok: true, result: 'already-sent' });
            }

            if (normalize(currentText) !== normalize(message.insertedText)) {
                return sendResult(sendResponse, { ok: false, reason: 'composer-changed' });
            }

            if (!writeText(composer, typeof message.previousText === 'string' ? message.previousText : '')) {
                return sendResult(sendResponse, { ok: false, reason: 'undo-failed' });
            }

            return sendResult(sendResponse, { ok: true, result: 'cleared-unsent-paste' });
        }

        return false;
    });
})();
