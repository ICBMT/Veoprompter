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

(() => {
    const gsap = window.gsap;
    const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (!gsap || reduceMotion) return;

    if (window.ScrollTrigger) gsap.registerPlugin(window.ScrollTrigger);

    const studio = document.querySelector('.studio-page');
    if (studio) {
        const intro = gsap.timeline({ defaults: { ease: 'power3.out' } });
        intro.from('.hero-copy .eyebrow-label', { y: 12, autoAlpha: 0, duration: 0.45 })
            .from('.hero-copy h1', { y: 24, autoAlpha: 0, duration: 0.72, ease: 'power4.out' }, '-=0.24')
            .from('.hero-copy > p, .hero-qualities', { y: 14, autoAlpha: 0, duration: 0.52, stagger: 0.08 }, '-=0.36')
            .from('.storyboard-card', { y: 20, autoAlpha: 0, scale: 0.985, duration: 0.72 }, '-=0.56')
            .from('.workspace-section .section-heading-row, .workspace-section .mode-tabs, .workspace-section .prompt-form', {
                y: 18,
                autoAlpha: 0,
                duration: 0.56,
                stagger: 0.09,
                clearProps: 'transform,opacity,visibility',
            }, '-=0.3');
    } else {
        const pageIntro = document.querySelector('.admin-page, .admin-users-page, .admin-user-detail-page, .auth-page, .guide-page, .account-page, .history-page, .history-detail, .projects-page, .project-settings-page');
        if (pageIntro) {
            gsap.from(pageIntro, {
                y: 16,
                autoAlpha: 0,
                duration: 0.62,
                ease: 'power3.out',
                clearProps: 'transform,opacity,visibility',
            });
        }
    }

    const revealTargets = gsap.utils.toArray(
        '.admin-card, .admin-method-card, .admin-prompt-card, .project-run-card, .project-settings-grid .form-panel, .guide-item, .history-card, .principles-row > div, .saved-brief-card, .saved-output-card'
    );
    revealTargets.forEach((element, index) => {
        const reveal = {
            y: 18,
            autoAlpha: 0,
            duration: 0.58,
            delay: (index % 3) * 0.055,
            ease: 'power3.out',
            clearProps: 'transform,opacity,visibility',
        };

        if (window.ScrollTrigger) {
            gsap.from(element, {
                ...reveal,
                scrollTrigger: { trigger: element, start: 'top 92%', once: true },
            });
        } else {
            gsap.from(element, reveal);
        }
    });

    if (studio) {
        const art = document.querySelector('.storyboard-art');
        if (art) {
            const skyMotion = gsap.timeline({ repeat: -1, yoyo: true, defaults: { ease: 'sine.inOut' } });
            skyMotion.to('.art-sun', { x: 3, y: -6, duration: 3.8 })
                .to('.art-planet', { x: -2, y: 7, duration: 4.6 }, 0)
                .to('.art-character', { y: -3, duration: 2.4 }, 0);

            gsap.to('.art-orbit--two', {
                rotation: 385,
                duration: 42,
                ease: 'none',
                repeat: -1,
                transformOrigin: '50% 50%',
            });
            gsap.to('.art-stars', {
                backgroundPosition: '11px 27px, 40px 21px',
                duration: 7,
                ease: 'sine.inOut',
                yoyo: true,
                repeat: -1,
            });
        }
    }

    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        gsap.utils.toArray('.button-primary, .topbar-control').forEach((element) => {
            const moveX = gsap.quickTo(element, 'x', { duration: 0.32, ease: 'power3.out' });
            const moveY = gsap.quickTo(element, 'y', { duration: 0.32, ease: 'power3.out' });

            element.addEventListener('pointermove', (event) => {
                const bounds = element.getBoundingClientRect();
                moveX((event.clientX - (bounds.left + bounds.width / 2)) * 0.045);
                moveY((event.clientY - (bounds.top + bounds.height / 2)) * 0.08);
            });
            element.addEventListener('pointerleave', () => {
                moveX(0);
                moveY(0);
            });
        });
    }

    if (window.ScrollTrigger && document.fonts?.ready) {
        document.fonts.ready.then(() => window.ScrollTrigger.refresh());
    }
})();

(() => {
    const form = document.querySelector('.project-settings-form');
    if (!form) return;

    const styleSelect = form.querySelector('#visual_style');
    const customStyle = form.querySelector('[data-custom-style]');
    const customStyleInput = form.querySelector('#style_detail');
    const voiceFields = form.querySelector('[data-voice-fields]');
    const narrationRadios = [...form.querySelectorAll('input[name="narration"]')];
    const characterType = form.querySelector('[data-character-type-select]');
    const characterDetails = form.querySelector('#character_details');

    const updateCustomStyle = () => {
        const show = styleSelect?.value === 'custom';
        if (customStyle) customStyle.hidden = !show;
        if (customStyleInput) {
            customStyleInput.disabled = !show;
            customStyleInput.required = show;
        }
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

    styleSelect?.addEventListener('change', updateCustomStyle);
    narrationRadios.forEach((radio) => radio.addEventListener('change', updateNarration));
    characterType?.addEventListener('change', () => {
        if (characterDetails) characterDetails.required = characterType.value === 'fictional';
    });
    form.querySelectorAll('.aspect-choice input').forEach((input) => {
        input.addEventListener('change', () => {
            form.querySelectorAll('.aspect-choice').forEach((choice) => {
                choice.classList.toggle('is-selected', choice.querySelector('input')?.checked ?? false);
            });
        });
    });

    updateCustomStyle();
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
    const sampleLines = {
        English: 'A tiny seed drifts into the garden. With sunlight, water, and a little patience, it begins a remarkable journey.',
        Urdu: 'ایک ننھا سا بیج باغ میں گرتا ہے۔ دھوپ، پانی اور تھوڑے صبر سے اس کا حیرت انگیز سفر شروع ہوتا ہے۔',
        Spanish: 'Una pequeña semilla llega al jardín. Con luz, agua y un poco de paciencia, comienza un viaje extraordinario.',
        French: 'Une petite graine arrive dans le jardin. Avec du soleil, de l’eau et un peu de patience, une belle aventure commence.',
        Arabic: 'تصل بذرة صغيرة إلى الحديقة. ومع ضوء الشمس والماء والقليل من الصبر، تبدأ رحلة رائعة.',
        Hindi: 'एक छोटा सा बीज बगीचे में आता है। धूप, पानी और थोड़े धैर्य के साथ, इसकी अद्भुत यात्रा शुरू होती है।',
    };
    const localeForLanguage = { English: 'en-US', Urdu: 'ur-PK', Spanish: 'es-ES', French: 'fr-FR', Arabic: 'ar-SA', Hindi: 'hi-IN' };
    const accentLocales = {
        'American English': 'en-US',
        'British English': 'en-GB',
        'Pakistani English': 'en-PK',
        'Australian English': 'en-AU',
        Neutral: 'en',
    };
    const voiceNameHints = {
        feminine: /female|woman|zira|samantha|victoria|serena|susan|aria|jenny|karen|siri.*female|google.*female/i,
        masculine: /male|man|david|mark|daniel|alex|fred|george|james|guy|siri.*male|google.*male/i,
    };

    const readConfig = (button) => {
        const form = button.closest('.project-settings-form');
        if (form) {
            return {
                language: form.querySelector('#language')?.value || 'English',
                voice: form.querySelector('#narrator_voice')?.value || 'feminine',
                accent: form.querySelector('#narrator_accent')?.value || 'American English',
                tone: form.querySelector('#narrator_tone')?.value || 'warm and friendly',
                narration: form.querySelector('input[name="narration"]:checked')?.value || '0',
            };
        }
        const source = document.querySelector('[data-voice-config]');
        return {
            language: source?.dataset.language || 'English',
            voice: source?.dataset.narratorVoice || 'feminine',
            accent: source?.dataset.narratorAccent || 'American English',
            tone: source?.dataset.narratorTone || 'warm and friendly',
            narration: '1',
        };
    };

    const preferredLocale = (language, accent) => {
        if (language === 'English') return accentLocales[accent] || localeForLanguage.English;
        return localeForLanguage[language] || 'en-US';
    };

    const chooseVoice = (voices, locale, gender) => {
        const desired = locale.toLowerCase();
        const language = desired.split('-')[0];
        const candidates = voices.filter((voice) => voice.lang.toLowerCase().startsWith(language));
        const localeMatches = candidates.filter((voice) => voice.lang.toLowerCase() === desired);
        const pool = localeMatches.length ? localeMatches : candidates;
        const genderMatch = pool.find((voice) => voiceNameHints[gender]?.test(voice.name));
        return genderMatch || pool[0] || null;
    };

    const playSample = async (button) => {
        const status = button.closest('.voice-preview-card, .generated-voice-preview')?.querySelector('[data-voice-preview-status]');
        const config = readConfig(button);
        if (config.narration !== '1') {
            if (status) status.textContent = 'Turn narration on in project settings before previewing a voice.';
            return;
        }
        if (!('speechSynthesis' in window) || !('SpeechSynthesisUtterance' in window)) {
            if (status) status.textContent = 'This browser does not provide built-in speech preview.';
            return;
        }

        const synth = window.speechSynthesis;
        const locale = preferredLocale(config.language, config.accent);
        const utterance = new SpeechSynthesisUtterance(sampleLines[config.language] || sampleLines.English);
        const toneSettings = {
            'warm and friendly': { rate: 0.94, pitch: 1.03 },
            'curious and lively': { rate: 1.08, pitch: 1.13 },
            'calm and thoughtful': { rate: 0.86, pitch: 0.96 },
            'confident and clear': { rate: 0.98, pitch: 1.0 },
        };
        const tone = toneSettings[config.tone] || toneSettings['warm and friendly'];
        utterance.lang = locale;
        utterance.rate = tone.rate;
        utterance.pitch = config.voice === 'feminine' ? Math.min(1.3, tone.pitch + 0.08) : Math.max(0.75, tone.pitch - 0.08);

        const voices = synth.getVoices();
        const selectedVoice = chooseVoice(voices, locale, config.voice);
        if (selectedVoice) {
            utterance.voice = selectedVoice;
            utterance.lang = selectedVoice.lang || locale;
        }
        utterance.onstart = () => {
            if (status) status.textContent = `Playing a local ${config.language} sample${selectedVoice ? ` with ${selectedVoice.name}` : ''}.`;
        };
        utterance.onend = () => {
            if (status) status.textContent = 'Preview finished. Device voices and accents may differ from your final video.';
        };
        utterance.onerror = () => {
            if (status) status.textContent = 'The device could not play this speech sample. Try another installed voice or language.';
        };
        synth.cancel();
        if (status) status.textContent = `Preparing a ${config.language} preview; the browser will use its closest installed accent and voice.`;
        synth.speak(utterance);
    };

    document.querySelectorAll('[data-voice-preview-button]').forEach((button) => {
        button.addEventListener('click', () => playSample(button));
    });
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
