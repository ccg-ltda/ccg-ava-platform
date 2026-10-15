/**
 * Helpers over the permission catalog the server shares (config/permissions.php): what each permission means and the area it
 * belongs to. Pure functions, so they can be tested without a browser.
 */

/** The catalog items grouped by area, in the order of the server's groups; `names` limits it to those permissions. */
export function groupPermissions(catalog, names) {
    const allowed = names ? new Set(names) : null;

    return Object.entries(catalog.groups)
        .map(([id, label]) => ({ id, label, items: catalog.items.filter((item) => item.group === id && (!allowed || allowed.has(item.name))) }))
        .filter((group) => group.items.length > 0);
}

/** The readable label of a permission (its own name when the catalog does not describe it). */
export function permissionLabel(catalog, name) {
    return catalog.items.find((item) => item.name === name)?.label ?? name;
}
