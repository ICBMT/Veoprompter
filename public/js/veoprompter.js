(() => {
    const form = document.querySelector('#prompt-form');
    if (!form) return;

    const message = document.querySelector('[data-generation-message]');
    const resultSection = document.querySelector('[data-results]');
    const historyNotice = document.querySelector('[data-history-notice]');
    const readyLabel = document.querySelector('[data-ready-label]');
    const output = document.querySelector('[data-output-content]');
    const submitButton = form.querySelector('[data-submit-button]');
    const submitLabel = form.querySelector('[data-submit-label]');
    const modeInput = form.querySelector('[data-mode-value]');
    const upload = form.querySelector('#reference_frame');
    const uploadZone = form.querySelector('[data-upload-zone]');
    const uploadLabel = form.querySelector('[data-upload-label]');
    const uploadClear = form.querySelector('[data-upload-clear]');
    const storyTabs = [...document.querySelectorAll('[data-mode-tab]')];
    let activeMode = 'story';
    let latestOutput = '';

    const hideMessage = () => {
        message.hidden = true;
        message.textContent = '';
    };

    const setGroupEnabled = (group, enabled) => {
        group.hidden = !enabled;
        group.querySelectorAll('input, select, textarea, button').forEach((field) => {
            field.disabled = !enabled;
        });
    };

    const setMode = (mode) => {
        activeMode = mode;
        modeInput.value = mode;
        storyTabs.forEach((tab) => {
            const selected = tab.dataset.modeTab === mode;
            tab.classList.toggle('is-selected', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });

        document.querySelectorAll('[data-story-fields]').forEach((group) => setGroupEnabled(group, mode === 'story'));
        document.querySelectorAll('[data-extend-fields]').forEach((group) => setGroupEnabled(group, mode === 'extend'));
        document.querySelectorAll('[data-story-only]').forEach((group) => setGroupEnabled(group, mode === 'story'));
        document.querySelectorAll('[data-extend-only]').forEach((group) => setGroupEnabled(group, mode === 'extend'));

        const durationCopy = document.querySelector('[data-duration-copy]');
        if (durationCopy) durationCopy.name = mode === 'extend' ? 'clip_duration' : '';

        submitLabel.textContent = mode === 'extend' ? 'Build extension prompt' : 'Build my prompt kit';
        if (!resultSection.hidden) {
            resultSection.hidden = true;
            latestOutput = '';
        }
        hideMessage();
    };

    storyTabs.forEach((tab) => tab.addEventListener('click', () => setMode(tab.dataset.modeTab)));
    setMode('story');

    const styleSelect = document.querySelector('#visual_style');
    const customStyle = document.querySelector('[data-custom-style]');
    const customStyleInput = document.querySelector('#style_detail');
    const updateCustomStyle = () => {
        const show = styleSelect.value === 'custom';
        customStyle.hidden = !show;
        customStyleInput.disabled = !show;
        customStyleInput.required = show;
    };
    styleSelect.addEventListener('change', updateCustomStyle);
    updateCustomStyle();

    document.querySelectorAll('.aspect-choice input').forEach((input) => {
        input.addEventListener('change', () => {
            document.querySelectorAll('.aspect-choice').forEach((choice) => {
                choice.classList.toggle('is-selected', choice.querySelector('input').checked);
            });
        });
    });

    const voiceFields = document.querySelector('[data-voice-fields]');
    const narrationRadios = [...form.querySelectorAll('input[name="narration"]')];
    const updateNarration = () => {
        const enabled = form.querySelector('input[name="narration"]:checked')?.value === '1';
        voiceFields.hidden = !enabled;
        voiceFields.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !enabled;
        });
        narrationRadios.forEach((radio) => {
            radio.closest('.toggle-choice').classList.toggle('is-active', radio.checked);
        });
    };
    narrationRadios.forEach((radio) => radio.addEventListener('change', updateNarration));
    updateNarration();

    const showMessage = (text) => {
        message.replaceChildren(document.createTextNode(text));
        message.hidden = false;
    };

    const clearSelectedFile = () => {
        upload.value = '';
        uploadZone.classList.remove('has-file');
        uploadLabel.textContent = 'Drop a frame here or browse files';
        const browse = document.createElement('i');
        browse.textContent = 'browse files';
        uploadLabel.replaceChildren(document.createTextNode('Drop a frame here or '), browse);
        uploadClear.hidden = true;
    };

    upload.addEventListener('change', () => {
        const file = upload.files?.[0];
        if (!file) return clearSelectedFile();

        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(file.type) || file.size > 8 * 1024 * 1024) {
            clearSelectedFile();
            showMessage('Choose a PNG, JPG, or WEBP image no larger than 8 MB.');
            return;
        }

        uploadZone.classList.add('has-file');
        uploadLabel.textContent = `${file.name} · ${(file.size / 1024 / 1024).toFixed(1)} MB`;
        uploadClear.hidden = false;
        hideMessage();
    });

    const removeUpload = (event) => {
        event.preventDefault();
        event.stopPropagation();
        clearSelectedFile();
    };
    uploadClear.addEventListener('click', removeUpload);
    uploadClear.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') removeUpload(event);
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
            if (upload.disabled) return;
            event.preventDefault();
            uploadZone.classList.add('is-dragging');
        });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
            event.preventDefault();
            uploadZone.classList.remove('is-dragging');
        });
    });
    uploadZone.addEventListener('drop', (event) => {
        if (upload.disabled) return;
        const file = event.dataTransfer?.files?.[0];
        if (!file) return;
        const transfer = new DataTransfer();
        transfer.items.add(file);
        upload.files = transfer.files;
        upload.dispatchEvent(new Event('change', { bubbles: true }));
    });

    const copyText = async (text) => {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.append(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();
        if (!copied) throw new Error('Clipboard access is unavailable.');
    };

    const attachCopyAction = (button, text) => {
        button.addEventListener('click', async () => {
            const original = button.textContent;
            try {
                await copyText(text);
                button.textContent = '✓ Copied';
                button.classList.add('is-copied');
                window.setTimeout(() => {
                    button.textContent = original;
                    button.classList.remove('is-copied');
                }, 1500);
            } catch {
                showMessage('Clipboard access was blocked. Select the prompt text and copy it manually.');
            }
        });
    };

    const appendTextSection = (container, text) => {
        const lines = text.split(/\r?\n/);
        let paragraph = [];
        let list = null;
        const flushParagraph = () => {
            if (!paragraph.length) return;
            const p = document.createElement('p');
            p.className = 'output-paragraph';
            p.textContent = paragraph.join(' ');
            container.append(p);
            paragraph = [];
        };
        const closeList = () => { list = null; };

        lines.forEach((line) => {
            const trimmed = line.trim();
            if (!trimmed) {
                flushParagraph();
                closeList();
                return;
            }

            const markdownHeading = trimmed.match(/^(#{1,4})\s+(.+)$/);
            const namedSection = trimmed.match(/^(SECTION\s+\d+\s+[—–-]\s+.+|COPY(?:\s+[A-Z0-9].*)|PROJECT OVERVIEW|EDUCATIONAL ACCURACY CHECK|CHARACTER BIBLE|WORLD AND OBJECT BIBLE|GLOBAL VIDEO RULES|FINAL CONTINUITY AUDIT)$/i);
            if (markdownHeading || namedSection) {
                flushParagraph();
                closeList();
                const level = markdownHeading ? Math.min(markdownHeading[1].length, 3) : 2;
                const heading = document.createElement(`h${level + 1}`);
                heading.className = `output-heading level-${level}`;
                heading.textContent = markdownHeading ? markdownHeading[2].replace(/\*\*/g, '') : trimmed;
                container.append(heading);
                return;
            }

            const bullet = trimmed.match(/^(?:[-*•]|\d+[.)])\s+(.+)$/);
            if (bullet) {
                flushParagraph();
                if (!list) {
                    list = document.createElement('ul');
                    list.className = 'output-list';
                    container.append(list);
                }
                const item = document.createElement('li');
                item.textContent = bullet[1].replace(/\*\*/g, '');
                list.append(item);
                return;
            }

            closeList();
            paragraph.push(trimmed.replace(/\*\*/g, ''));
        });
        flushParagraph();
    };

    const renderOutput = (text) => {
        output.replaceChildren();
        const fences = /```([^\n`]*)\n([\s\S]*?)```/g;
        let cursor = 0;
        let pendingTitle = '';
        let match;
        while ((match = fences.exec(text)) !== null) {
            const before = text.slice(cursor, match.index);
            appendTextSection(output, before);
            const headings = [...before.matchAll(/(?:^|\n)(?:#{1,4}\s+)?(SECTION\s+\d+[^\n]*|COPY[^\n]*|[^\n]+)\s*$/gim)];
            if (headings.length) {
                const candidate = headings[headings.length - 1][1].trim();
                if (candidate.length < 100) pendingTitle = candidate.replace(/^#+\s*/, '');
            }

            const card = document.createElement('section');
            card.className = 'output-copy-card';
            const head = document.createElement('div');
            head.className = 'output-copy-head';
            const title = document.createElement('span');
            const codeLabel = match[1].trim();
            title.textContent = pendingTitle || (codeLabel && !['text', 'txt', 'plain text', 'markdown', 'md'].includes(codeLabel.toLowerCase()) ? codeLabel : 'COPY-READY PROMPT');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'output-copy-button';
            button.textContent = '▢ Copy section';
            const codeText = match[2].replace(/^\n+|\n+$/g, '');
            attachCopyAction(button, codeText);
            head.append(title, button);
            const pre = document.createElement('pre');
            pre.className = 'output-copy-pre';
            pre.textContent = codeText;
            card.append(head, pre);
            output.append(card);
            cursor = fences.lastIndex;
            pendingTitle = '';
        }

        appendTextSection(output, text.slice(cursor));
        if (!output.childElementCount) {
            const p = document.createElement('p');
            p.className = 'output-paragraph';
            p.textContent = text;
            output.append(p);
        }
    };

    const discardStaleOutput = () => {
        if (resultSection.hidden) return;
        resultSection.hidden = true;
        latestOutput = '';
    };
    form.addEventListener('input', discardStaleOutput);
    form.addEventListener('change', discardStaleOutput);

    const renderHistoryNotice = (payload) => {
        historyNotice.replaceChildren();
        const link = document.createElement('a');

        if (payload.saved_to_history && payload.project?.url) {
            historyNotice.classList.remove('is-warning');
            historyNotice.append(document.createTextNode('Saved to your prompt library. '));
            link.href = payload.project.url;
            link.textContent = 'Open project ↗';
        } else if (payload.history_error) {
            historyNotice.classList.add('is-warning');
            historyNotice.append(document.createTextNode('Your prompts are ready, but we could not save this kit. Copy or download it now.'));
            historyNotice.hidden = false;
            return;
        } else {
            historyNotice.classList.remove('is-warning');
            historyNotice.append(document.createTextNode('Want to keep this prompt kit? '));
            link.href = '/register';
            link.textContent = 'Create a free account ↗';
        }

        historyNotice.append(link);
        historyNotice.hidden = false;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        resultSection.hidden = true;
        historyNotice.hidden = true;
        latestOutput = '';
        hideMessage();
        if (!form.reportValidity()) return;

        const priorLabel = submitLabel.textContent;
        submitButton.disabled = true;
        submitButton.classList.add('is-loading');
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.setAttribute('aria-label', 'Creating consistent video prompts');
        let loadingLabel = 'Keeping every frame in sync';
        submitLabel.textContent = loadingLabel;
        const loadingTimer = window.setInterval(() => {
            loadingLabel = loadingLabel.endsWith('…') ? 'Keeping every frame in sync' : `${loadingLabel}…`;
            submitLabel.textContent = loadingLabel;
        }, 900);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                credentials: 'same-origin',
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const validationError = payload.errors ? Object.values(payload.errors).flat()[0] : null;
                const errorText = validationError || payload.message || 'The request could not be completed. Please try again.';
                showMessage(errorText);
                return;
            }

            latestOutput = payload.content || '';
            renderOutput(latestOutput);
            resultSection.hidden = false;
            document.querySelector('#results-heading').textContent = payload.mode === 'extend' ? 'The next shot is in frame.' : 'The story is in frame.';
            readyLabel.textContent = payload.mode === 'extend' ? 'EXTENSION PROMPT READY' : 'PROMPT KIT READY';
            renderHistoryNotice(payload);
            resultSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch {
            showMessage('We could not reach VeoPrompter. Check your connection and try again.');
        } finally {
            window.clearInterval(loadingTimer);
            submitButton.disabled = false;
            submitButton.classList.remove('is-loading');
            submitButton.removeAttribute('aria-busy');
            submitButton.removeAttribute('aria-label');
            submitLabel.textContent = activeMode === 'extend' ? 'Build extension prompt' : 'Build my prompt kit';
        }
    });

    document.querySelector('[data-copy-all]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        try {
            await copyText(latestOutput);
            button.innerHTML = '<span aria-hidden="true">✓</span> Copied';
            window.setTimeout(() => { button.innerHTML = '<span aria-hidden="true">▢</span> Copy all'; }, 1500);
        } catch {
            showMessage('Clipboard access was blocked. Select the prompts and copy them manually.');
        }
    });

    document.querySelector('[data-download-output]')?.addEventListener('click', () => {
        if (!latestOutput) return;
        const blob = new Blob([latestOutput], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `veoprompter-${activeMode === 'extend' ? 'extension' : 'prompt-kit'}-${new Date().toISOString().slice(0, 10)}.txt`;
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    });
})();

(() => {
    const form = document.querySelector('[data-provider-settings-form]');
    if (!form) return;

    const provider = form.querySelector('[data-provider-select]');
    const baseField = form.querySelector('[data-base-url-field]');
    const baseInput = form.querySelector('#base_url');
    const apiKeyInput = form.querySelector('#api_key');
    const modelSelect = form.querySelector('[data-model-input]');
    const modelHint = form.querySelector('[data-model-hint]');
    const modelFeedback = form.querySelector('[data-model-feedback]');
    const loadModelsButton = form.querySelector('[data-load-models]');
    const testButton = form.querySelector('[data-test-connection]');
    const manualModelWrap = form.querySelector('[data-manual-model-wrap]');
    const manualModelInput = form.querySelector('#manual_model');
    const manualToggle = form.querySelector('[data-toggle-manual-model]');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    let providerRequestId = 0;
    let loadTimer = null;
    let manualMode = false;
    let previousBaseUrl = baseInput.value;

    const currentModelInput = () => manualMode ? manualModelInput : modelSelect;

    const setModelOptions = (models, preferred) => {
        const values = new Set(models.map((model) => model.id));
        if (preferred && !values.has(preferred)) {
            models.unshift({ id: preferred, label: `${preferred} — current model` });
        }

        modelSelect.replaceChildren();
        models.forEach((model) => {
            const option = document.createElement('option');
            option.value = model.id;
            option.textContent = model.label;
            modelSelect.append(option);
        });

        if (preferred && values.has(preferred)) modelSelect.value = preferred;
        else if (models[0]) modelSelect.value = models[0].id;
    };

    const updateProviderFields = (resetModel = false) => {
        const isOpenAi = provider.value === 'openai';
        baseField.hidden = !isOpenAi;
        baseInput.disabled = !isOpenAi;
        const suggestion = isOpenAi ? modelSelect.dataset.openaiDefault : modelSelect.dataset.geminiDefault;

        if (resetModel) {
            setModelOptions([{ id: suggestion, label: `${suggestion} — recommended starting model` }], suggestion);
            modelFeedback.textContent = 'Enter the key for this provider to load its available models.';
        }

        modelHint.textContent = 'Choose a model returned by your provider, then test it before saving. You can also enter a model ID manually.';
    };

    const requestBody = () => {
        const body = new FormData();
        body.append('_token', csrfToken);
        body.append('provider', provider.value);
        body.append('base_url', provider.value === 'openai' ? baseInput.value : '');
        body.append('api_key', apiKeyInput.value);
        return body;
    };

    const postJson = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            body,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || `The request failed (HTTP ${response.status}).`);
        return payload;
    };

    const loadModels = async () => {
        window.clearTimeout(loadTimer);
        const requestId = ++providerRequestId;
        loadModelsButton.disabled = true;
        testButton.disabled = true;
        modelFeedback.classList.remove('is-success', 'is-error');
        modelFeedback.textContent = 'Checking the key and loading models securely…';

        try {
            const payload = await postJson(form.dataset.modelsUrl, requestBody());
            if (requestId !== providerRequestId) return;

            if (!Array.isArray(payload.models) || payload.models.length === 0) {
                modelFeedback.classList.add('is-error');
                modelFeedback.textContent = 'The provider returned no model IDs. Check the provider settings or enter a model ID manually.';
                return;
            }

            const previous = currentModelInput().value;
            setModelOptions(payload.models, previous);
            modelFeedback.classList.add('is-success');
            modelFeedback.textContent = `${payload.models.length} model${payload.models.length === 1 ? '' : 's'} found. Select one from the list, then test it.`;
        } catch (error) {
            if (requestId !== providerRequestId) return;
            modelFeedback.classList.add('is-error');
            modelFeedback.textContent = error.message || 'Could not load the model list. Check the key and endpoint.';
        } finally {
            if (requestId === providerRequestId) {
                loadModelsButton.disabled = false;
                testButton.disabled = false;
            }
        }
    };

    const scheduleModelLoad = () => {
        window.clearTimeout(loadTimer);
        providerRequestId += 1;
        loadModelsButton.disabled = false;
        testButton.disabled = false;
        if (!apiKeyInput.value.trim()) {
            modelFeedback.classList.remove('is-success', 'is-error');
            modelFeedback.textContent = 'Enter this provider’s key, or click Load to use a saved key for the same provider and endpoint.';
            return;
        }
        modelFeedback.classList.remove('is-success', 'is-error');
        modelFeedback.textContent = 'Key entered. Loading available models securely…';
        loadTimer = window.setTimeout(loadModels, 800);
    };

    const toggleManualModel = () => {
        manualMode = !manualMode;
        if (manualMode) {
            manualModelInput.value = modelSelect.value;
            manualModelInput.name = 'model';
            manualModelInput.disabled = false;
            manualModelInput.required = true;
            modelSelect.removeAttribute('name');
            modelSelect.disabled = true;
            modelSelect.required = false;
            manualModelWrap.hidden = false;
            manualToggle.textContent = 'Choose from model list';
            manualModelInput.focus();
            return;
        }

        const manualValue = manualModelInput.value.trim();
        if (manualValue && ![...modelSelect.options].some((option) => option.value === manualValue)) {
            const option = document.createElement('option');
            option.value = manualValue;
            option.textContent = `${manualValue} — manual model ID`;
            modelSelect.append(option);
        }
        if (manualValue) modelSelect.value = manualValue;
        modelSelect.name = 'model';
        modelSelect.disabled = false;
        modelSelect.required = true;
        manualModelInput.removeAttribute('name');
        manualModelInput.disabled = true;
        manualModelInput.required = false;
        manualModelWrap.hidden = true;
        manualToggle.textContent = 'Enter model ID manually';
        modelSelect.focus();
    };

    const testConnection = async () => {
        window.clearTimeout(loadTimer);
        const requestId = ++providerRequestId;
        const body = requestBody();
        body.append('model', currentModelInput().value.trim());
        testButton.disabled = true;
        modelFeedback.classList.remove('is-success', 'is-error');
        modelFeedback.textContent = 'Sending a short test request to the selected model…';

        try {
            const payload = await postJson(form.dataset.testUrl, body);
            if (requestId !== providerRequestId) return;
            modelFeedback.classList.add('is-success');
            modelFeedback.textContent = `${payload.message} Test reply: “${payload.reply}”`;
        } catch (error) {
            if (requestId !== providerRequestId) return;
            modelFeedback.classList.add('is-error');
            modelFeedback.textContent = error.message || 'The model test failed. Check the key, model, and endpoint.';
        } finally {
            if (requestId === providerRequestId) testButton.disabled = false;
        }
    };

    updateProviderFields();
    provider.addEventListener('change', () => {
        apiKeyInput.value = '';
        if (manualMode) toggleManualModel();
        updateProviderFields(true);
        scheduleModelLoad();
    });
    apiKeyInput.addEventListener('change', scheduleModelLoad);
    baseInput.addEventListener('change', () => {
        if (baseInput.value !== previousBaseUrl) {
            previousBaseUrl = baseInput.value;
            apiKeyInput.value = '';
            modelFeedback.textContent = 'Endpoint changed. Re-enter its API key before loading models or testing.';
        }
        scheduleModelLoad();
    });
    loadModelsButton.addEventListener('click', loadModels);
    manualToggle.addEventListener('click', toggleManualModel);
    testButton.addEventListener('click', testConnection);
})();

(() => {
    const copyFrom = async (element) => {
        const text = element?.textContent ?? '';
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
            return;
        }
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.append(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();
        if (!copied) throw new Error('Clipboard access is unavailable.');
    };

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const original = button.innerHTML;
            try {
                await copyFrom(document.querySelector(button.dataset.copyTarget));
                button.textContent = '✓ Copied';
                button.classList.add('is-copied');
                window.setTimeout(() => {
                    button.innerHTML = original;
                    button.classList.remove('is-copied');
                }, 1500);
            } catch {
                button.textContent = 'Select the text to copy';
            }
        });
    });

    document.querySelectorAll('[data-download-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const text = document.querySelector(button.dataset.downloadTarget)?.textContent ?? '';
            if (!text) return;
            const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = button.dataset.downloadName || 'veoprompter-prompt-kit.txt';
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(url);
        });
    });

    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });
})();
