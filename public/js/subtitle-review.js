export function initTranscriptReview(panel, onApproved, { fetcher = globalThis.fetch, onUncertain = async () => {}, confirmDiscard = () => globalThis.confirm('Discard unsaved edits and reload the saved draft?'), requestTimeout = 30000 } = {}) {
    const doc = panel.ownerDocument;
    const get = (name) => panel.querySelector(`[data-${name}]`);
    const feedback = get('review-feedback');
    const form = get('transcript-form');
    const list = get('review-words');
    const player = get('review-video');
    const confirmation = get('review-confirm');
    // Native required-field validation can prevent submit from reaching our handler,
    // leaving no useful feedback (including invalid words on a different page).
    // Validate the entire transcript explicitly below; server validation remains mandatory.
    form.noValidate = true;
    const pageSize = 80;
    let words = [], revision = 0, page = 0, loaded = false, busy = false, dirty = false;
    const localUrl = (value) => {
        const url = new URL(value, globalThis.location.origin);
        if (url.origin !== globalThis.location.origin) throw new Error('Transcript requests must stay on this site.');
        return url;
    };
    const request = async (url, options = {}) => {
        const controller = new AbortController();
        let timer;
        try {
            const response = await Promise.race([
                fetcher(localUrl(url), { credentials: 'same-origin', cache: 'no-store', ...options, signal: controller.signal,
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': doc.querySelector('meta[name="csrf-token"]')?.content || '' } }),
                new Promise((_, reject) => { timer = setTimeout(() => {
                    controller.abort();
                    reject(new Error('The request took too long to confirm. Your edits remain here. Approval may have reached the server; checking progress before you retry.'));
                }, requestTimeout); }),
            ]);
            const data = await response.json().catch(() => null);
            if (!response.ok || response.redirected) {
                if ([401, 403, 419].includes(response.status) || response.redirected) throw new Error('Your session or security token expired. Save a copy of your edits, sign in again in another tab, then reload this draft before approving.');
                if (response.status === 429) throw new Error('Too many requests. Your edits remain here; wait a minute before retrying approval.');
                throw new Error(Object.values(data?.errors || {}).flat()[0] || data?.message || 'Could not confirm this request. Your edits remain in this tab; check the saved state before retrying.');
            }
            if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('The server did not return a valid confirmation. Your edits remain here; check progress before retrying.');
            return data;
        } finally {
            clearTimeout(timer);
        }
    };
    const controls = () => {
        ['review-save', 'review-approve', 'review-load', 'review-previous', 'review-next', 'review-confirm'].forEach(name => { get(name).disabled = busy || !loaded; });
        get('review-previous').disabled ||= page === 0;
        get('review-next').disabled ||= (page + 1) * pageSize >= words.length;
        list.querySelectorAll('input').forEach(input => { input.disabled = busy; });
    };
    const paragraph = () => { get('transcript-paragraph').textContent = words.map(word => word.text).join(' '); };
    const draw = () => {
        list.replaceChildren();
        words.slice(page * pageSize, (page + 1) * pageSize).forEach((word, offset) => {
            const index = page * pageSize + offset;
            const row = doc.createElement('div'); row.className = 'subtitle-word-row';
            const play = doc.createElement('button'); play.type = 'button'; play.className = 'button-secondary';
            play.textContent = `${word.start.toFixed(2)}–${word.end.toFixed(2)}s ▶`;
            play.setAttribute('aria-label', `Play original audio from word ${index + 1}`);
            play.addEventListener('click', () => { player.currentTime = word.start; player.play().catch(() => {}); });
            const source = doc.createElement('span'); source.textContent = `Heard: ${word.original}`;
            const label = doc.createElement('label'); label.textContent = `Caption word ${index + 1}`;
            const input = doc.createElement('input'); input.type = 'text'; input.maxLength = 120; input.required = true; input.value = word.text; input.dataset.captionWord = String(index);
            label.append(input); row.append(play, source, label); list.append(row);
        });
        get('review-page').textContent = `Words ${page * pageSize + 1}–${Math.min((page + 1) * pageSize, words.length)} of ${words.length}`;
        paragraph(); controls();
    };
    const load = async (force = false) => {
        if (busy || (loaded && !force)) return;
        if (force && dirty && !confirmDiscard()) return;
        busy = true; controls();
        try {
            const data = await request(panel.dataset.reviewUrl);
            if (typeof data.editable !== 'boolean' || !Number.isInteger(data.revision) || data.revision < 1
                || !Array.isArray(data.words) || !data.words.length
                || data.words.some(word => !Number.isFinite(word.start) || !Number.isFinite(word.end) || typeof word.text !== 'string' || typeof word.original !== 'string')) {
                throw new Error('The transcript response is incomplete. Reload the saved draft to retry; no approval was sent.');
            }
            if (!data.editable) { await onApproved(data); return; }
            words = data.words; revision = data.revision; page = 0; loaded = true; dirty = false; confirmation.checked = false;
            player.src = localUrl(panel.dataset.originalUrl).href;
            feedback.textContent = 'Review the words at your own pace. No captions have been rendered yet.';
            draw();
        } catch (error) { feedback.textContent = error.message; }
        finally { busy = false; controls(); get('review-load').disabled = false; }
    };
    panel.addEventListener('input', (event) => {
        if (event.target.dataset.captionWord === undefined || busy) return;
        words[Number(event.target.dataset.captionWord)].text = event.target.value;
        confirmation.checked = false; dirty = true; paragraph();
        feedback.textContent = 'Unsaved wording changes. Save the draft or explicitly approve this exact version.';
    });
    get('review-load').addEventListener('click', () => load(true));
    get('review-previous').addEventListener('click', () => { if (!busy && page > 0) { page--; draw(); } });
    get('review-next').addEventListener('click', () => { if (!busy && (page + 1) * pageSize < words.length) { page++; draw(); } });
    const save = async (approve) => {
        if (busy || !loaded) return;
        if (approve && !confirmation.checked) { feedback.textContent = 'Explicitly confirm that you reviewed these captions before rendering.'; return; }
        const invalid = words.findIndex(word => !word.text.trim() || [...word.text].length > 120 || /[\x00-\x1F\x7F]/.test(word.text));
        if (invalid >= 0) { page = Math.floor(invalid / pageSize); draw(); feedback.textContent = `Correct caption word ${invalid + 1}: nonempty text, at most 120 characters, no line breaks.`; return; }
        busy = true; controls();
        feedback.textContent = approve ? 'Confirming your exact transcript and queueing the render…' : 'Saving your draft…';
        try {
            const data = await request(approve ? panel.dataset.approveUrl : panel.dataset.saveUrl, {
                method: approve ? 'POST' : 'PUT', body: JSON.stringify({ revision, words: words.map(word => word.text), ...(approve ? { confirmed: true } : {}) }),
            });
            const statuses = approve ? ['approved', 'generating_subtitles', 'rendering', 'completed', 'failed'] : ['awaiting_review'];
            if (!Number.isInteger(data.revision) || data.revision < revision || !statuses.includes(data.status)) {
                throw new Error('The server did not confirm the saved transcript state. Your edits remain here; checking progress before retrying.');
            }
            revision = data.revision; dirty = false;
            if (approve) { player.pause(); await onApproved(data); }
            else { feedback.textContent = 'Draft saved. Rendering is still paused until you approve.'; confirmation.checked = false; }
        } catch (error) {
            feedback.textContent = error.message;
            if (approve) await onUncertain(); // The server may have committed approval before a response was lost.
        }
        finally { busy = false; controls(); }
    };
    get('review-save').addEventListener('click', () => save(false));
    form.addEventListener('submit', (event) => { event.preventDefault(); return save(true); });
    return { load };
}
