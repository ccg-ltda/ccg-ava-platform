/** Customer tags: the same normalization as the server (`CustomerTagCatalog::clean` / `normalize`): Unicode NFC, trimmed, single spaces, lower case. Accents are kept. */
export const MAX_TAG_LENGTH = 40;

export const cleanTag = (name) => String(name ?? '').normalize('NFC').replace(/\s+/gu, ' ').trim();

export const normalizeTag = (name) => cleanTag(name).toLowerCase();

/** The list with `name` added, unless an equivalent tag (any capitalization, spacing or Unicode form) is already in it. Returns the same list when nothing is added. */
export function withTag(list, name) {
    const clean = cleanTag(name);

    if (clean === '' || clean.length > MAX_TAG_LENGTH || list.some((item) => normalizeTag(item) === normalizeTag(clean))) return list;

    return [...list, normalizeTag(clean)];
}
