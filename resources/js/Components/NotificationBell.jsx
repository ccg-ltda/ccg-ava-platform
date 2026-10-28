import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Bell, CheckCheck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

const POLL_MS = 30000;

/**
 * The bell of the top bar, common to every Workspace: the unread count and the recent notifications of the active Workspace. Opening the list reads nothing:
 * a notification is read when the person opens it or marks it, or with «Marcar todas como leídas». The server decides what is visible; this only draws it.
 */
export default function NotificationBell() {
    const initial = usePage().props.notifications ?? { unread: 0, items: [] };
    const [state, setState] = useState(initial);

    const refresh = useCallback(() => {
        axios.get(route('notifications.index')).then((response) => setState(response.data)).catch(() => {});
    }, []);

    // Pages carry only the count; the list is fetched once on mount and then every poll.
    useEffect(() => {
        refresh();
    }, [refresh]);

    useEffect(() => {
        const timer = setInterval(() => {
            if (document.visibilityState === 'visible') refresh();
        }, POLL_MS);

        return () => clearInterval(timer);
    }, [refresh]);

    const markRead = (id) => axios.post(route('notifications.read', id)).then((response) => setState(response.data)).catch(() => {});
    const markAll = () => axios.post(route('notifications.read-all')).then((response) => setState(response.data)).catch(() => {});

    const open = (item, close) => {
        markRead(item.id);
        close();
        if (item.url) router.visit(item.url);
    };

    return (
        <Popover className="relative">
            <PopoverButton
                onClick={refresh}
                aria-label={state.unread > 0 ? `Notificaciones: ${state.unread} sin leer` : 'Notificaciones'}
                className="relative grid size-10 place-items-center rounded-full transition-colors duration-150 hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white"
            >
                <Bell className="size-5" aria-hidden="true" />
                {state.unread > 0 && (
                    <span className="absolute -top-0.5 -right-0.5 grid min-w-5 place-items-center rounded-full bg-danger px-1 text-[10px] font-bold leading-5 text-white" aria-hidden="true">
                        {state.unread > 99 ? '99+' : state.unread}
                    </span>
                )}
            </PopoverButton>

            <PopoverPanel
                transition
                anchor={{ to: 'bottom end', gap: 8 }}
                className="z-50 w-[22rem] max-w-[calc(100vw-1.5rem)] rounded-card border border-line bg-card text-ink shadow-card-hover transition duration-150 ease-out data-closed:scale-95 data-closed:opacity-0"
            >
                {({ close }) => (
                    <div role="region" aria-label="Notificaciones">
                        <div className="flex items-center justify-between border-b border-line px-4 py-3">
                            <h2 className="text-sm font-bold">Notificaciones</h2>
                            <button
                                type="button"
                                onClick={markAll}
                                disabled={state.unread === 0}
                                className="flex items-center gap-1 text-xs font-semibold text-primary disabled:opacity-40 focus-visible:outline-2"
                            >
                                <CheckCheck className="size-4" aria-hidden="true" />
                                Marcar todas como leídas
                            </button>
                        </div>

                        {state.items.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-ink-muted">No tienes notificaciones.</p>
                        ) : (
                            <ul className="max-h-96 divide-y divide-line overflow-y-auto">
                                {state.items.map((item) => (
                                    <li key={item.id} className={item.read ? '' : 'bg-primary/10'}>
                                        <div className="flex items-start gap-2 px-4 py-3">
                                            <button type="button" onClick={() => open(item, close)} className="min-w-0 flex-1 text-left focus-visible:outline-2">
                                                <span className="flex items-center gap-2">
                                                    {!item.read && <span className="size-2 shrink-0 rounded-full bg-primary" aria-label="Sin leer" />}
                                                    <span className="text-sm font-semibold break-words">{item.title}</span>
                                                </span>
                                                {item.body && <span className="mt-0.5 block text-xs text-ink-muted">{item.body}</span>}
                                                <span className="mt-1 block text-[11px] text-ink-muted">{item.createdAt ? new Date(item.createdAt).toLocaleString() : ''}</span>
                                            </button>
                                            {!item.read && (
                                                <button type="button" onClick={() => markRead(item.id)} className="shrink-0 text-xs font-semibold text-primary focus-visible:outline-2">
                                                    Marcar leída
                                                </button>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </PopoverPanel>
        </Popover>
    );
}
