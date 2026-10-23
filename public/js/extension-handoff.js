(() => {
    const statusFor = (button) => {
        const describedBy = (button.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        for (const id of describedBy) {
            const candidate = document.getElementById(id);
            if (candidate?.matches('[data-flow-transfer-status]')) return candidate;
        }
        return button.closest('[data-prompt-handoff]')?.querySelector('[data-flow-transfer-status]')
            || document.querySelector('[data-flow-transfer-status]');
    };

    const transferPrompt = (button, prompt, metadata = {}) => {
        const status = statusFor(button);
        const label = button.querySelector('[data-flow-button-label]') || button;
        const originalLabel = label.textContent.trim() || 'Send to Flow';
        const content = typeof prompt === 'string' ? prompt : '';

        if (!content.trim()) {
            if (status) {
                status.dataset.kind = 'error';
                status.textContent = 'There is no saved prompt text to send.';
                status.hidden = false;
            }
            return;
        }

        const requestId = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}`;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        label.textContent = 'Sending…';
        if (status) {
            status.hidden = true;
            status.textContent = '';
        }

        let settled = false;
        let timeoutId;
        const finish = (response) => {
            if (settled) return;
            settled = true;
            window.clearTimeout(timeoutId);
            window.removeEventListener('message', onAck);
            button.disabled = false;
            button.removeAttribute('aria-busy');
            label.textContent = response?.ok ? 'Saved in extension' : originalLabel;

            if (status) {
                status.dataset.kind = response?.ok ? 'success' : 'error';
                status.textContent = response?.ok
                    ? `${response.replacedPrevious ? 'Replaced the previous extension prompt. ' : ''}Saved ${response.chunkCount} part${response.chunkCount === 1 ? '' : 's'} locally. On the destination tab, click Copy in the extension and then click a text field to paste once. You still submit each part yourself.`
                    : (response?.message || 'The extension did not respond. Connect it to this site, then try again.');
                status.hidden = false;
            }

            window.setTimeout(() => {
                if (label.textContent === 'Saved in extension') label.textContent = originalLabel;
            }, 1800);
        };
        const onAck = (event) => {
            if (event.source !== window || event.origin !== window.location.origin) return;
            const response = event.data;
            if (!response || response.type !== 'VEOPROMPTER_FLOW_EXTENSION_ACK' || response.requestId !== requestId) return;
            finish(response);
        };

        window.addEventListener('message', onAck);
        timeoutId = window.setTimeout(() => finish({
            ok: false,
            message: 'No connected VeoPrompter extension was found. Open the extension on this site, choose “Connect this site”, then try again.',
        }), 5000);

        const title = typeof metadata.title === 'string' && metadata.title.trim()
            ? metadata.title.trim()
            : 'VeoPrompter prompt';
        const savedRunId = Number(metadata.savedRunId);
        window.postMessage({
            type: 'VEOPROMPTER_FLOW_EXTENSION_STORE',
            requestId,
            prompt: content,
            title,
            mode: metadata.mode === 'extend' ? 'extend' : 'story',
            savedRunId: Number.isSafeInteger(savedRunId) && savedRunId > 0 ? savedRunId : null,
        }, window.location.origin);
    };

    window.veoprompterSendToFlow = transferPrompt;

    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element
            ? event.target.closest('[data-send-to-flow][data-prompt-target]')
            : null;
        if (!button) return;

        event.preventDefault();
        let source;
        try {
            source = document.querySelector(button.dataset.promptTarget);
        } catch {
            source = null;
        }
        const prompt = source ? ('value' in source ? source.value : source.textContent) : '';
        transferPrompt(button, prompt, {
            title: button.dataset.promptTitle || 'Saved VeoPrompter prompt',
            mode: button.dataset.promptMode || 'story',
            savedRunId: button.dataset.promptRunId || null,
        });
    });
})();
