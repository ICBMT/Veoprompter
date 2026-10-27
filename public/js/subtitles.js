(() => {
    'use strict';
    const form = document.querySelector('[data-subtitle-upload]');
    if (form) {
        form.addEventListener('submit', () => {
            form.querySelector('button[type="submit"]').disabled = true;
            form.querySelector('[data-upload-feedback]').textContent = 'Uploading your MP4. Keep this tab open until the progress page appears.';
        });
        window.addEventListener('pageshow', () => { form.querySelector('button[type="submit"]').disabled = false; });
    }
    const job = document.querySelector('[data-subtitle-job]');
    if (!job) return;
    const stages = ['uploaded', 'extracting_audio', 'transcribing', 'generating_subtitles', 'rendering', 'completed'];
    const messages = {
        uploaded: 'Queued securely. Waiting for the subtitle worker.',
        extracting_audio: 'Checking your video and extracting the audio track.',
        transcribing: 'Whisper is listening locally and aligning individual words. This may take a few minutes.',
        generating_subtitles: 'Wrapping sentences and composing word-highlighted captions.',
        rendering: 'FFmpeg is burning captions into a lossless video. Larger videos take longer.',
        completed: 'Ready. Review the captions, then download your MP4.',
    };
    let stopped = false;
    let failures = 0;
    const render = (data) => {
        const index = stages.indexOf(data.status);
        job.querySelectorAll('[data-stage]').forEach((step, i) => {
            step.classList.toggle('is-complete', index >= i);
            if (stages[i] === data.status) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });
        job.querySelector('[data-job-heading]').textContent = data.status.replaceAll('_', ' ');
        job.querySelector('[data-job-feedback]').textContent = data.failure || messages[data.status] || 'Checking progress…';
        if (data.status === 'completed' && data.preview_url && data.download_url) {
            job.querySelector('[data-job-preview]').src = data.preview_url;
            job.querySelector('[data-job-download]').href = data.download_url;
            job.querySelector('[data-job-output]').hidden = false;
        }
        stopped = ['completed', 'failed'].includes(data.status);
    };
    render({ status: job.dataset.status, failure: job.dataset.status === 'failed' ? job.querySelector('[data-job-feedback]').textContent : null });
    const poll = async () => {
        if (stopped) return;
        try {
            const response = await fetch(job.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) {
                stopped = true;
                throw new Error('Session ended or this video is no longer available. Sign in again or return to Subtitle Studio.');
            }
            if (!response.ok) throw new Error('Progress is temporarily unavailable. Processing continues; retrying shortly.');
            render(await response.json());
            failures = 0;
        } catch (error) {
            failures++;
            job.querySelector('[data-job-feedback]').textContent = error.message;
        }
        if (!stopped) window.setTimeout(poll, Math.min(30000, 4000 * Math.max(1, failures)));
    };
    if (!stopped) window.setTimeout(poll, 2000);
})();
