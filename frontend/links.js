// Version labels that do not change what a sweeper or jingle says.
const VERSION_LABELS = /\b(?:v(?:ersion)?\s*\d+|take\s*\d+|dry|wet|short|long|radio\s*edit|edit|mix|remix|alt(?:ernate)?|instrumental|a\s*capp?ella|reverb|fx|sfx|\d+\s*(?:s|sec|secs|seconds))\b/g;

export function linkKey(candidate) {
    const source = (candidate.title || candidate.path.split("/").pop().replace(/\.[^.]+$/, "")).toLowerCase();
    return source
        .replace(/[([{][^)\]}]*[)\]}]/g, " ")
        .replace(/[_-]+/g, " ")
        .replace(VERSION_LABELS, " ")
        .replace(/[^\p{L}\p{N}]+/gu, " ")
        .trim()
        .replace(/\s+/g, " ");
}

// Groups unlinked tracks whose titles match once case, punctuation and version labels are ignored.
export function suggestLinks(candidates) {
    const byKey = new Map();
    for (const candidate of candidates) {
        const key = linkKey(candidate);
        if (!key) continue;
        if (!byKey.has(key)) byKey.set(key, []);
        byKey.get(key).push(candidate);
    }
    return [...byKey.entries()]
        .filter(([, members]) => members.length >= 2)
        .map(([key, members]) => ({
            key,
            name: members[0].title || key,
            media_ids: members.slice(0, 50).map((member) => member.id),
        }));
}
