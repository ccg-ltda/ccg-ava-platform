/**
 * Badge tone for each Workspace role (shared by every page that shows a role).
 * Blue is the main color; violet marks the most privileged role; anything else stays neutral.
 */
const ROLE_TONES = { admin: 'violet', supervisor: 'blue' };

export const roleTone = (role) => ROLE_TONES[role] ?? 'neutral';
