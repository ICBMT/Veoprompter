const form = document.querySelector('[data-ideas-form]');
if (form) {
    form.addEventListener('submit', () => {
        form.querySelector('button[type="submit"]').disabled = true;
        form.querySelector('[data-ideas-feedback]').textContent = 'Fetching today’s search evidence and asking the AI for ten ideas. Keep this tab open; this can take a few minutes.';
    });
    const initiallyDisabled = form.querySelector('button[type="submit"]').disabled;
    window.addEventListener('pageshow', () => { form.querySelector('button[type="submit"]').disabled = initiallyDisabled; });
}
