// A navigation shortcut only. The extension never researches trends or submits wizard answers.
export function videoIdeasLink(origin, connectedOrigins = []) {
    try {
        const url = new URL(origin);
        if (!['http:', 'https:'].includes(url.protocol) || url.origin !== origin || !connectedOrigins.includes(origin)) return null;
        return new URL('/ideas', url.origin).href;
    } catch { return null; }
}
