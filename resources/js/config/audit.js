import { Pencil, Plus, Trash2 } from 'lucide-react';

/** Look of each audit action: badge tone, icon and the color of the before/after cells. */
export const auditActions = {
    created: { tone: 'green', icon: Plus, bar: 'bg-accent-green' },
    updated: { tone: 'amber', icon: Pencil, bar: 'bg-accent-amber' },
    deleted: { tone: 'red', icon: Trash2, bar: 'bg-danger' },
};

export const actionTone = (action) => auditActions[action]?.tone ?? 'neutral';
