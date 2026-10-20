/**
 * Helpers over the permission catalog the server shares (config/permissions.php): what each permission means and the resource it
 * belongs to. Pure functions, so they can be tested without a browser. The catalog has the shape
 * `{ groups: { [id]: { label, description, list, module, moduleName } }, items: [{ name, label, description, group, platform, requires }] }`
 * where `requires` is the permission that opens the resource's page (every operation is done from it), or null.
 */

/** The catalog items grouped by resource, in the order of the server's groups; `names` limits it to those permissions. */
export function groupPermissions(catalog, names) {
    const allowed = names ? new Set(names) : null;

    return Object.entries(catalog.groups)
        .map(([id, group]) => ({ id, ...group, items: catalog.items.filter((item) => item.group === id && (!allowed || allowed.has(item.name))) }))
        .filter((group) => group.items.length > 0);
}

/** The readable label of a permission (its own name when the catalog does not describe it). */
export function permissionLabel(catalog, name) {
    return catalog.items.find((item) => item.name === name)?.label ?? name;
}

/** Case- and accent-insensitive text for searching. */
export function normalize(text) {
    return String(text ?? '')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/** Whether a catalog item matches a search text (label, description or group name; the technical identifier is never shown, so it is not searched). */
export function matchesSearch(item, group, query) {
    const needle = normalize(query).trim();

    if (!needle) return true;

    return [item.label, item.description, group?.label].some((text) => normalize(text).includes(needle));
}

/**
 * One row per resource with how many of its permissions a role holds: `{ id, label, granted, total, names }`, only for resources the role holds
 * at least one of. This replaces an opaque "+23" with something a person can read ("Clientes 3/6").
 */
export function summarizeRole(catalog, names) {
    const held = new Set(names);

    return groupPermissions(catalog)
        .map((group) => ({
            id: group.id,
            label: group.label,
            total: group.items.length,
            granted: group.items.filter((item) => held.has(item.name)).length,
            names: group.items.filter((item) => held.has(item.name)).map((item) => item.name),
        }))
        .filter((row) => row.granted > 0);
}

/**
 * The permissions of `selected` whose resource page they need but that the selection does not hold: `[{ name, label, requires, requiresLabel }]`.
 * It never selects anything on its own; it only tells the person that the operation could not be reached.
 */
export function missingRequirements(catalog, selected) {
    const held = new Set(selected);

    return catalog.items
        .filter((item) => held.has(item.name) && item.requires && !held.has(item.requires))
        .map((item) => ({ name: item.name, label: item.label, requires: item.requires, requiresLabel: permissionLabel(catalog, item.requires) }));
}

/** Permissions added and removed between two selections, for the "unsaved changes" summary. */
export function selectionDiff(before, after) {
    const was = new Set(before);
    const now = new Set(after);

    return {
        added: [...now].filter((name) => !was.has(name)),
        removed: [...was].filter((name) => !now.has(name)),
    };
}
