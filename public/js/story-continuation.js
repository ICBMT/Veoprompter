(() => {
    // randomUUID is restricted to secure contexts in several browsers. getRandomValues
    // remains available on ordinary HTTP development origins; never use Math.random for IDs.
    const requestUuid = () => {
        try {
            if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
        } catch { /* Try the cryptographic fallback if a browser exposes an unusable method. */ }
        if (typeof globalThis.crypto?.getRandomValues !== 'function') return null;
        let bytes;
        try { bytes = globalThis.crypto.getRandomValues(new Uint8Array(16)); } catch { return null; }
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };
    const rotateRequestId = (form) => {
        const id = requestUuid();
        if (id) {
            form.elements.request_id.value = id;
            delete form.dataset.requestIdUnavailable;
            return true;
        }
        form.dataset.requestIdUnavailable = 'true';
        form.querySelector('[data-story-message]').textContent = 'This browser cannot create a secure request ID. Use a current browser or HTTPS before generating another batch.';
        return false;
    };

    // Studio creates its result asynchronously; saved library forms are already present.
    document.addEventListener('veoprompter:run-ready', (event) => {
        const panel = document.querySelector('[data-results] [data-story-panel]');
        if (!panel) return;
        const run = event.detail?.run;
        panel.hidden = !run?.continuation_url;
        if (panel.hidden) return;
        const form = panel.querySelector('[data-story-form]');
        form.action = run.continuation_url;
        form.dataset.storyStatus = run.story_status;
        panel.querySelector('[data-story-heading]').textContent = run.story_status === 'open' ? 'Story ongoing · choose the next batch' : 'Story complete · create Part 2';
        panel.querySelectorAll('[data-story-intent]').forEach((button) => {
            button.hidden = run.story_status === 'open' ? button.value === 'sequel' : button.value !== 'sequel';
        });
        form.elements.direction.value = '';
        form.elements.allow_new_subjects.checked = false;
        form.querySelector('[data-story-message]').textContent = '';
        delete form.dataset.requestIntent;
        rotateRequestId(form);
    });

    document.addEventListener('input', (event) => {
        const form = event.target.closest('[data-story-form]');
        if (form && event.target.name !== 'request_id' && form.dataset.busy !== 'true') {
            rotateRequestId(form);
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-story-form]');
        if (!form) return;
        event.preventDefault();
        if (form.dataset.busy === 'true' || !form.reportValidity()) return;
        const intent = event.submitter?.value;
        if (!['continue', 'finish', 'sequel'].includes(intent)) return;
        if (form.dataset.requestIntent && form.dataset.requestIntent !== intent && !rotateRequestId(form)) return;
        if (form.dataset.requestIdUnavailable === 'true') return;
        form.dataset.requestIntent = intent;
        const data = new FormData(form);
        data.set('intent', intent);
        const message = form.querySelector('[data-story-message]');
        const controls = [...form.querySelectorAll('button, textarea, select, input[type="checkbox"]')];
        const disabledStates = controls.map(control => control.disabled);
        form.dataset.busy = 'true';
        form.setAttribute('aria-busy', 'true');
        controls.forEach((control) => { control.disabled = true; });
        message.textContent = 'Building the next batch with your saved story context and quality checks…';
        try {
            const endpoint = new URL(form.action, location.origin);
            if (endpoint.origin !== location.origin) throw new Error('The continuation endpoint must be on this site.');
            const response = await fetch(endpoint, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const details = Array.isArray(payload.opening_issues) ? payload.opening_issues.map(issue => `${issue.field}: ${issue.reason}`).join('; ') : '';
                const explanation = Object.values(payload.errors || {}).flat()[0] || payload.message || 'Generation could not finish. Your source is unchanged.';
                throw new Error(`${explanation}${details ? ` ${details}` : ''} (HTTP ${response.status})`);
            }
            if (!payload.url) throw new Error('No saved continuation was returned. Retry this same request.');
            const target = new URL(payload.url, location.origin);
            if (target.origin !== location.origin) throw new Error('The saved prompt URL must be on this site.');
            location.assign(target.href);
        } catch (error) {
            message.textContent = error.message || 'Connection interrupted. Retry this same request to avoid a duplicate batch.';
        } finally {
            form.dataset.busy = 'false';
            form.removeAttribute('aria-busy');
            controls.forEach((control, index) => { control.disabled = disabledStates[index]; });
        }
    });
})();
