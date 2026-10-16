(() => {
    if (window.__veoprompterFlowBridgeLoaded) return;
    window.__veoprompterFlowBridgeLoaded = true;

    const PAGE_MESSAGE = 'VEOPROMPTER_FLOW_EXTENSION_STORE';
    const PAGE_ACK = 'VEOPROMPTER_FLOW_EXTENSION_ACK';
    const MAX_PROMPT_CHARACTERS = 500_000;

    window.addEventListener('message', (event) => {
        if (event.source !== window || event.origin !== window.location.origin) return;
        const message = event.data;
        if (!message || message.type !== PAGE_MESSAGE) return;

        const requestId = typeof message.requestId === 'string' ? message.requestId.slice(0, 100) : '';
        const prompt = typeof message.prompt === 'string' ? message.prompt : '';
        const respond = (result) => {
            window.postMessage({ type: PAGE_ACK, requestId, ...result }, window.location.origin);
        };

        if (!requestId || !prompt.trim() || prompt.length > MAX_PROMPT_CHARACTERS) {
            respond({ ok: false, message: 'This prompt is empty or too large to send to the extension.' });
            return;
        }

        try {
            chrome.runtime.sendMessage({
                type: 'VEOPROMPTER_STORE_PROMPT_FROM_APP',
                requestId,
                prompt,
                title: typeof message.title === 'string' ? message.title.slice(0, 140) : 'VeoPrompter prompt',
                mode: message.mode === 'extend' ? 'extend' : 'story',
            }, (response) => {
                if (chrome.runtime.lastError) {
                    respond({ ok: false, message: 'The extension could not store this prompt. Reopen the extension and try again.' });
                    return;
                }
                respond(response || { ok: false, message: 'The extension did not confirm that it received the prompt.' });
            });
        } catch {
            respond({ ok: false, message: 'The extension could not receive this prompt. Reopen it and try again.' });
        }
    });
})();
