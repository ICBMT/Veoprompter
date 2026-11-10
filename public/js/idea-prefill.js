// This optional bridge only fills empty creative fields. It never generates or submits.
export function applyIdeaPrefill(form, draft, makeEvent = () => new Event('change', { bubbles: true })) {
    if (!form || !draft || !draft.answers || typeof draft.answers !== 'object' || Array.isArray(draft.answers)) return false;
    const fields = { project_title: draft.title, topic: draft.answers.topic,
        learning_objective: draft.answers.learning_objective, creative_direction: draft.answers.creative_direction,
        character_idea: draft.answers.character_idea };
    for (const [name, value] of Object.entries(fields)) {
        const field = form.elements.namedItem(name);
        if (field && !field.value && typeof value === 'string') { field.value = value; field.dispatchEvent(makeEvent()); }
    }
    return true;
}
if (typeof document !== 'undefined') {
    const record = document.querySelector('[data-idea-prefill]');
    if (record) {
        try { applyIdeaPrefill(document.querySelector('#prompt-form'), JSON.parse(record.textContent)); }
        catch { /* The existing generator remains usable if an optional draft cannot be read. */ }
    }
}
