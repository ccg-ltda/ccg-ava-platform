/**
 * Badge tone for each Workspace role (shared by every page that shows a role).
 * Blue is the main color; violet marks the most privileged role; anything else stays neutral.
 */
const ROLE_TONES = { admin: 'violet', supervisor: 'blue' };

/**
 * Permissions of the commercial administration of Ava Platform. They only operate inside Desarrollo_DEV (the server ignores
 * them in any other Workspace), no role receives them automatically and they do not widen anything else a role can do.
 */
export const platformPermissions = {
    'manage-plans': 'Administra planes y los asigna a Workspaces. Solo opera en Desarrollo_DEV.',
    'manage-modules': 'Administra el catálogo de módulos y categorías. Solo opera en Desarrollo_DEV.',
};

export const roleTone = (role) => ROLE_TONES[role] ?? 'neutral';
