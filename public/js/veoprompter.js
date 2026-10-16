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
    const videoTypeSelect = form.querySelector('[data-video-type-select]');
    const learningObjectiveFields = form.querySelector('[data-learning-objective-fields]');
    let activeMode = 'story';
    let latestOutput = '';

    const updateLearningObjectiveFields = () => {
        if (!learningObjectiveFields) return;
        const show = activeMode === 'story' && videoTypeSelect?.value === 'educational';
        learningObjectiveFields.hidden = !show;
        learningObjectiveFields.querySelectorAll('input, select, textarea').forEach((field) => {
            field.disabled = !show;
        });
    };

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
        updateLearningObjectiveFields();

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
    videoTypeSelect?.addEventListener('change', updateLearningObjectiveFields);
    setMode('story');

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

        if (payload.saved_to_history && payload.run?.url) {
            historyNotice.classList.remove('is-warning');
            historyNotice.append(document.createTextNode('Saved to your prompt library. '));
            link.href = payload.run.url;
            link.textContent = 'Open saved prompt ↗';
        } else {
            historyNotice.classList.add('is-warning');
            historyNotice.append(document.createTextNode('Your prompt is ready, but could not be saved to this project. Copy or download it now.'));
            historyNotice.hidden = false;
            return;
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
            document.querySelector('#results-heading').textContent = payload.mode === 'extend' ? 'The next shot is in frame.' : 'The video plan is in frame.';
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

(() => {
    const form = document.querySelector('.project-settings-form');
    if (!form) return;

    const styleControl = form.querySelector('#visual_style');
    const styleChoices = [...form.querySelectorAll('input[name="visual_style"]')];
    const customStyle = form.querySelector('[data-custom-style]');
    const customStyleInput = form.querySelector('#style_detail');
    const voiceFields = form.querySelector('[data-voice-fields]');
    const narrationRadios = [...form.querySelectorAll('input[name="narration"]')];
    const characterTypeControl = form.querySelector('[data-character-type-select]');
    const characterTypeChoices = [...form.querySelectorAll('input[name="character_type"]')];
    const characterDetails = form.querySelector('#character_details');
    const selectedValue = (name, control) => form.querySelector(`input[name="${name}"]:checked`)?.value || control?.value || '';

    const updateCustomStyle = () => {
        const show = selectedValue('visual_style', styleControl) === 'custom';
        if (customStyle) customStyle.hidden = !show;
        if (customStyleInput) {
            customStyleInput.disabled = !show;
            customStyleInput.required = show;
        }
    };

    const updateCharacterDetails = () => {
        if (characterDetails) characterDetails.required = selectedValue('character_type', characterTypeControl) === 'fictional';
    };

    const updateNarration = () => {
        const enabled = form.querySelector('input[name="narration"]:checked')?.value === '1';
        if (voiceFields) {
            voiceFields.hidden = !enabled;
            voiceFields.querySelectorAll('input, select, textarea').forEach((field) => {
                field.disabled = !enabled;
            });
        }
        form.querySelectorAll('[data-voice-preview-button]').forEach((button) => {
            button.disabled = !enabled;
            button.title = enabled ? '' : 'Turn narration on to preview a voice.';
        });
        narrationRadios.forEach((radio) => {
            radio.closest('.toggle-choice')?.classList.toggle('is-active', radio.checked);
        });
    };

    styleChoices.forEach((choice) => choice.addEventListener('change', updateCustomStyle));
    styleControl?.addEventListener('change', updateCustomStyle);
    narrationRadios.forEach((radio) => radio.addEventListener('change', updateNarration));
    characterTypeChoices.forEach((choice) => choice.addEventListener('change', updateCharacterDetails));
    if (!characterTypeChoices.length) characterTypeControl?.addEventListener('change', updateCharacterDetails);
    form.querySelectorAll('.aspect-choice input').forEach((input) => {
        input.addEventListener('change', () => {
            form.querySelectorAll('.aspect-choice').forEach((choice) => {
                choice.classList.toggle('is-selected', choice.querySelector('input')?.checked ?? false);
            });
        });
    });

    updateCustomStyle();
    updateCharacterDetails();
    updateNarration();

    document.querySelectorAll('.field-info').forEach((button) => {
        button.setAttribute('aria-label', `Help: ${button.dataset.tooltip || 'More information'}`);
        button.setAttribute('aria-expanded', 'false');
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const willOpen = button.getAttribute('aria-expanded') !== 'true';
            document.querySelectorAll('.field-info[aria-expanded="true"]').forEach((openButton) => {
                openButton.setAttribute('aria-expanded', 'false');
            });
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', (event) => {
        if (!event.target.closest('.field-info')) {
            document.querySelectorAll('.field-info[aria-expanded="true"]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
        }
    });
})();

(() => {
    // Voice previews are generated server-side by Gemini TTS, cached by the full preference tuple,
    // and played as WAV so the result is consistent across devices instead of relying on local voices.
    const previewButtons = [...document.querySelectorAll('[data-voice-preview-button]')];
    if (!previewButtons.length) return;

    const endpoint = document.querySelector('meta[name="voice-sample-url"]')?.content;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const audioUrls = new Map();
    let previewToken = 0;
    let activeRequest = null;
    let activeButton = null;

    const readValue = (form, name, fallback) => {
        const selected = form.querySelector(`input[name="${name}"]:checked`);
        if (selected) return selected.value;
        return form.querySelector(`#${name}`)?.value || fallback;
    };

    const readConfig = (button) => {
        const form = button.closest('.project-settings-form');
        if (form) {
            return {
                language: readValue(form, 'language', 'English'),
                narrator_voice: readValue(form, 'narrator_voice', 'feminine'),
                narrator_accent: readValue(form, 'narrator_accent', 'American English'),
                narrator_tone: readValue(form, 'narrator_tone', 'warm and friendly'),
                narration: readValue(form, 'narration', '1'),
            };
        }

        const source = button.closest('.studio-page')?.querySelector('[data-voice-config]')
            || document.querySelector('[data-voice-config]');
        return {
            language: source?.dataset.language || 'English',
            narrator_voice: source?.dataset.narratorVoice || 'feminine',
            narrator_accent: source?.dataset.narratorAccent || 'American English',
            narrator_tone: source?.dataset.narratorTone || 'warm and friendly',
            narration: '1',
        };
    };

    const cacheKey = (config) => JSON.stringify([
        config.language,
        config.narrator_voice,
        config.narrator_accent,
        config.narrator_tone,
    ]);

    const getPlayer = (button) => {
        const card = button.closest('.voice-preview-card, .generated-voice-preview');
        if (!card) return null;
        let player = card.querySelector('[data-voice-sample-player]');
        if (!player) {
            player = document.createElement('audio');
            player.className = 'voice-sample-audio';
            player.controls = true;
            player.preload = 'none';
            player.hidden = true;
            player.dataset.voiceSamplePlayer = '';
            player.setAttribute('aria-label', 'Generated voice sample audio');
            card.append(player);
        }
        return player;
    };

    const setStatus = (button, text) => {
        const status = button.closest('.voice-preview-card, .generated-voice-preview')?.querySelector('[data-voice-preview-status]');
        if (status) status.textContent = text;
    };

    const pausePlayers = () => {
        document.querySelectorAll('[data-voice-sample-player]').forEach((player) => {
            player.pause();
            player.currentTime = 0;
        });
    };

    const stopPreview = () => {
        previewToken += 1;
        activeRequest?.abort();
        activeRequest = null;
        pausePlayers();
        if (activeButton) {
            activeButton.disabled = false;
            activeButton.removeAttribute('aria-busy');
            activeButton = null;
        }
        document.querySelectorAll('[data-voice-preview-status]').forEach((status) => {
            if (/^(Playing|Preparing)/.test(status.textContent)) {
                status.textContent = 'Voice settings changed. Preview again to hear the updated combination.';
            }
        });
    };

    const rememberAudio = (key, url) => {
        audioUrls.set(key, url);
        while (audioUrls.size > 6) {
            const [oldestKey, oldestUrl] = audioUrls.entries().next().value;
            audioUrls.delete(oldestKey);
            document.querySelectorAll('[data-voice-sample-player]').forEach((player) => {
                if (player.src === oldestUrl) {
                    player.pause();
                    player.removeAttribute('src');
                    player.load();
                    player.hidden = true;
                }
            });
            URL.revokeObjectURL(oldestUrl);
        }
    };

    const playSample = async (button) => {
        const config = readConfig(button);
        if (config.narration !== '1') {
            setStatus(button, 'Turn narration on in project settings before previewing a voice.');
            return;
        }
        if (!endpoint || !csrfToken) {
            setStatus(button, 'Voice sample playback is unavailable. Refresh the page and try again.');
            return;
        }

        activeRequest?.abort();
        if (activeButton) {
            activeButton.disabled = false;
            activeButton.removeAttribute('aria-busy');
        }
        activeButton = null;
        pausePlayers();
        const token = ++previewToken;
        const request = new AbortController();
        activeRequest = request;
        activeButton = button;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        setStatus(button, 'Preparing your Gemini TTS sample. The first request for a combination may take a few seconds.');

        try {
            const key = cacheKey(config);
            let url = audioUrls.get(key);
            if (!url) {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: request.signal,
                    headers: {
                        'Accept': 'audio/wav, application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        language: config.language,
                        narrator_voice: config.narrator_voice,
                        narrator_accent: config.narrator_accent,
                        narrator_tone: config.narrator_tone,
                    }),
                });
                if (!response.ok) {
                    const payload = await response.json().catch(() => ({}));
                    throw new Error(payload.message || 'The voice sample could not be generated. Please try again.');
                }
                const blob = await response.blob();
                if (!blob.size || !blob.type.includes('audio')) {
                    throw new Error('Gemini returned an empty or unsupported audio sample. Try again or ask an administrator to check the TTS settings.');
                }
                url = URL.createObjectURL(blob);
                rememberAudio(key, url);
            }

            if (token !== previewToken) return;
            const player = getPlayer(button);
            if (!player) throw new Error('The voice sample player could not be prepared.');
            player.src = url;
            player.hidden = false;
            window.dispatchEvent(new CustomEvent('veo:voice-sample-ready', { detail: { player } }));
            player.onended = () => setStatus(button, 'Preview finished. This sample is cached for the selected combination.');
            player.onplaying = () => setStatus(button, `Playing the generated ${config.language} sample. This preference combination is cached for future previews.`);

            try {
                await player.play();
            } catch {
                setStatus(button, 'Your sample is ready. Press play in the audio controls to listen.');
            }
        } catch (error) {
            if (token !== previewToken) return;
            setStatus(button, error.name === 'AbortError'
                ? 'Preview cancelled.'
                : (error.message || 'The voice sample could not be generated. Please try again.'));
        } finally {
            if (token === previewToken) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
                activeButton = null;
                activeRequest = null;
            }
        }
    };

    document.querySelectorAll('.project-settings-form').forEach((form) => {
        form.addEventListener('change', (event) => {
            if (['language', 'narrator_voice', 'narrator_accent', 'narrator_tone', 'narration'].includes(event.target.name)) {
                stopPreview();
            }
        });
    });

    previewButtons.forEach((button) => button.addEventListener('click', () => playSample(button)));
})();

(() => {
    const expiryMeta = document.querySelector('meta[name="account-expires-at"]');
    if (expiryMeta) {
        const millisecondsUntilExpiry = new Date(expiryMeta.content).getTime() - Date.now();
        if (millisecondsUntilExpiry <= 0) {
            window.location.assign('/login');
        } else {
            window.setTimeout(() => window.location.assign('/login'), millisecondsUntilExpiry);
        }
    }

    const presenceMeta = document.querySelector('meta[name="presence-url"]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (presenceMeta && csrf) {
        let lastPing = 0;
        const ping = async () => {
            if (document.visibilityState !== 'visible' || Date.now() - lastPing < 55000) return;
            lastPing = Date.now();
            try {
                const response = await fetch(presenceMeta.content, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                if (response.status === 401) window.location.assign('/login');
            } catch {
                // Presence is best-effort; a network hiccup must not interrupt the workspace.
            }
        };
        window.setInterval(ping, 60000);
        document.addEventListener('visibilitychange', ping);
    }

    const adminUsers = document.querySelector('[data-admin-presence-url]');
    if (!adminUsers) return;

    const refreshPresence = async () => {
        try {
            const response = await fetch(adminUsers.dataset.adminPresenceUrl, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;
            const payload = await response.json();
            document.querySelectorAll('[data-user-presence][data-user-id]').forEach((indicator) => {
                const activity = payload.users?.[indicator.dataset.userId];
                if (!activity) return;
                const online = Boolean(activity.online);
                indicator.classList.toggle('is-online', online);
                indicator.classList.toggle('is-offline', !online);
                const label = indicator.querySelector('[data-presence-label]');
                if (label) label.textContent = online ? 'Online' : 'Offline';
                const time = indicator.parentElement?.querySelector('[data-presence-time]');
                if (time) {
                    time.textContent = online ? 'Active now' : (activity.last_seen_at ? `Last seen ${new Date(activity.last_seen_at).toLocaleString()}` : 'No recent activity');
                }
            });
        } catch {
            // Keep the server-rendered last-seen state if polling fails.
        }
    };

    refreshPresence();
    window.setInterval(refreshPresence, 45000);
})();

(() => {
    const form = document.querySelector('[data-project-wizard]');
    if (!form) return;
    form.classList.add('is-enhanced');

    const allSteps = [...form.querySelectorAll('[data-wizard-step]')];
    const backButton = form.querySelector('[data-wizard-back]');
    const nextButton = form.querySelector('[data-wizard-next]');
    const submitButton = form.querySelector('[data-wizard-submit]');
    const progressLabel = form.querySelector('[data-wizard-step-count]');
    const progressGroup = form.querySelector('[data-wizard-step-group]');
    const progressBar = form.querySelector('[data-wizard-progress]');
    const progressTrack = form.querySelector('[role="progressbar"]');
    const progressNote = form.querySelector('[data-wizard-progress-note]');
    const announcement = form.querySelector('[data-wizard-announcement]');
    const validationMessage = form.querySelector('[data-wizard-validation]');
    let currentStep = allSteps[0];

    const chosenValue = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value
        ?? form.querySelector(`select[name="${name}"]`)?.value
        ?? '';

    const isRelevant = (step) => {
        const condition = step.dataset.wizardWhen;
        if (!condition) return true;
        const [name, expected] = condition.split(':');
        return chosenValue(name) === expected;
    };

    const activeSteps = () => allSteps.filter(isRelevant);

    const labelForValue = (field) => {
        const checked = form.querySelector(`input[name="${field}"]:checked`);
        if (checked) {
            return checked.dataset.summaryLabel
                || checked.closest('.wizard-choice')?.querySelector('.wizard-choice-title')?.textContent.trim()
                || checked.closest('.aspect-choice')?.querySelector('span:not(.ratio-icon):not(.sr-only)')?.textContent.trim()
                || checked.value;
        }
        const select = form.querySelector(`select[name="${field}"]`);
        if (select) return select.selectedOptions[0]?.textContent.trim() || '';
        const input = form.querySelector(`[name="${field}"]:not([type="radio"]):not([type="checkbox"]):not([type="hidden"])`);
        return input?.value.trim() || '';
    };

    const updateReview = () => {
        const visualStyle = chosenValue('visual_style');
        const characterType = chosenValue('character_type');
        const narration = chosenValue('narration');
        const videoType = chosenValue('video_type');
        const hiddenRows = new Set();
        const reviewVoicePreview = form.querySelector('[data-wizard-review-voice-preview]');
        if (reviewVoicePreview) reviewVoicePreview.hidden = narration !== '1';
        if (visualStyle !== 'custom') hiddenRows.add('style_detail');
        if (videoType !== 'educational') hiddenRows.add('educational_depth');
        if (characterType !== 'fictional') hiddenRows.add('character_details');
        if (narration !== '1') {
            ['language', 'narrator_voice', 'narrator_accent', 'narrator_tone'].forEach((key) => hiddenRows.add(key));
        }

        form.querySelectorAll('[data-wizard-summary-for]').forEach((summary) => {
            const key = summary.dataset.wizardSummaryFor;
            const row = summary.closest('[data-wizard-review-row]');
            if (row) row.hidden = hiddenRows.has(key);
            let value = labelForValue(key);
            if (key === 'visual_style' && visualStyle === 'custom') value = 'Custom visual style';
            if (key === 'character_type' && characterType === 'fictional') value = 'Fictional character';
            summary.textContent = value || '—';
        });
    };

    const updateChoiceStyles = () => {
        form.querySelectorAll('.wizard-choice').forEach((choice) => {
            choice.classList.toggle('is-selected', Boolean(choice.querySelector('input')?.checked));
        });
    };

    const render = (focusStep = false) => {
        const steps = activeSteps();
        if (!steps.length) return;
        if (!steps.includes(currentStep)) {
            const previousIndex = Math.max(0, allSteps.indexOf(currentStep));
            currentStep = steps.find((step) => allSteps.indexOf(step) >= previousIndex) || steps[steps.length - 1];
        }
        const currentIndex = steps.indexOf(currentStep);
        steps.forEach((step, index) => {
            const number = step.querySelector('[data-wizard-number]');
            if (number) number.textContent = String(index + 1).padStart(2, '0');
        });
        allSteps.forEach((step) => {
            const isCurrent = step === currentStep && isRelevant(step);
            step.hidden = !isCurrent;
            step.setAttribute('aria-current', isCurrent ? 'step' : 'false');
        });

        const humanStep = currentIndex + 1;
        const isReview = Boolean(currentStep.hasAttribute('data-wizard-review'));
        progressLabel.textContent = `STEP ${humanStep} OF ${steps.length}`;
        progressGroup.textContent = currentStep.dataset.wizardGroup || 'PROJECT SETUP';
        progressTrack.setAttribute('aria-valuemax', String(steps.length));
        progressTrack.setAttribute('aria-valuenow', String(humanStep));
        progressBar.style.width = `${steps.length <= 1 ? 100 : (currentIndex / (steps.length - 1)) * 100}%`;
        progressNote.textContent = isReview
            ? 'Everything here is saved as your project default. You can edit it later.'
            : 'One small choice at a time. You can go back whenever you like.';
        backButton.hidden = currentIndex === 0;
        nextButton.hidden = isReview;
        submitButton.hidden = !isReview;
        if (announcement) announcement.textContent = `Step ${humanStep} of ${steps.length}: ${currentStep.dataset.wizardTitle || 'Project setup'}`;
        updateReview();
        updateChoiceStyles();

        if (focusStep) {
            currentStep.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
            window.requestAnimationFrame(() => currentStep.querySelector('.wizard-question h2')?.focus({ preventScroll: true }));
        }
    };

    const validateStep = (step, focusInvalid = true) => {
        const fields = [...step.querySelectorAll('input:not([type="hidden"]), select, textarea')]
            .filter((field) => !field.disabled);
        const invalid = fields.find((field) => !field.checkValidity());
        if (!invalid) return true;
        if (validationMessage) {
            validationMessage.textContent = 'Please answer this question before continuing.';
            validationMessage.hidden = false;
        }
        if (focusInvalid) invalid.reportValidity();
        return false;
    };

    nextButton.addEventListener('click', () => {
        if (!validateStep(currentStep)) return;
        if (validationMessage) validationMessage.hidden = true;
        const steps = activeSteps();
        const nextIndex = steps.indexOf(currentStep) + 1;
        if (nextIndex < steps.length) {
            currentStep = steps[nextIndex];
            render(true);
        }
    });

    backButton.addEventListener('click', () => {
        const steps = activeSteps();
        const previousIndex = steps.indexOf(currentStep) - 1;
        if (previousIndex >= 0) {
            currentStep = steps[previousIndex];
            if (validationMessage) validationMessage.hidden = true;
            render(true);
        }
    });

    form.addEventListener('input', () => {
        updateReview();
        if (validationMessage) validationMessage.hidden = true;
    });
    form.addEventListener('change', (event) => {
        updateChoiceStyles();
        updateReview();
        if (validationMessage) validationMessage.hidden = true;
        if (['visual_style', 'character_type', 'narration', 'video_type'].includes(event.target.name)) render();
    });

    form.addEventListener('submit', (event) => {
        if (!currentStep.hasAttribute('data-wizard-review')) {
            event.preventDefault();
            if (!validateStep(currentStep)) return;
            if (validationMessage) validationMessage.hidden = true;
            const steps = activeSteps();
            const nextIndex = steps.indexOf(currentStep) + 1;
            if (nextIndex < steps.length) {
                currentStep = steps[nextIndex];
                render(true);
            }
            return;
        }

        const steps = activeSteps();
        const invalidStep = steps.find((step) => !validateStep(step, false));
        if (invalidStep) {
            event.preventDefault();
            currentStep = invalidStep;
            render(true);
            window.requestAnimationFrame(() => validateStep(invalidStep));
            return;
        }
        if (validationMessage) validationMessage.hidden = true;
    });

    render();
    if (document.querySelector('.validation-summary')) {
        const steps = activeSteps();
        const invalidStep = steps.find((step) => !validateStep(step, false));
        if (invalidStep) {
            currentStep = invalidStep;
            render();
        }
    }
})();
