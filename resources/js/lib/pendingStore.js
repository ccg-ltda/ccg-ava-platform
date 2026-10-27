import { useSyncExternalStore } from 'react';

// How many conversations wait for a person (the sidebar badge). One value for the whole page: the notification poller writes it, the sidebar reads it. The server computes it
// (ConversationAlerts::pending), so a refresh, a second tab or a visit never changes it by itself.
let pending = 0;
const listeners = new Set();

export function setPending(next) {
    const value = Number.isFinite(next) && next > 0 ? Math.floor(next) : 0;

    if (value === pending) return;

    pending = value;
    listeners.forEach((listener) => listener());
}

export function usePending() {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => pending,
        () => 0,
    );
}

/** The text of the badge: the number, and «99+» above that. */
export const pendingLabel = (count) => (count > 99 ? '99+' : String(count));
