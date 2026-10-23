export function savedRunReviewLink(sourceOrigin, savedRunId, connectedOrigins = []) {
    const idText = String(savedRunId ?? '').trim();
    if (!/^\d+$/.test(idText)) return null;

    const id = Number(idText);
    if (!Number.isSafeInteger(id) || id <= 0) return null;

    let appUrl;
    try {
        appUrl = new URL(sourceOrigin);
    } catch {
        return null;
    }

    if (!['http:', 'https:'].includes(appUrl.protocol)
        || appUrl.origin !== sourceOrigin
        || !Array.isArray(connectedOrigins)
        || !connectedOrigins.includes(appUrl.origin)) {
        return null;
    }

    return new URL(`/history/${id}`, appUrl.origin).href;
}
