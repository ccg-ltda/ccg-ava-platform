import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import SearchInput from '@/Components/SearchInput';
import { contactLabel, initial } from './contact';

/** The conversations of the channel, newest first, with a server-side search by name or number. */
export default function ConversationList({ conversations, selectedId, search, hrefFor, baseUrl, baseParams }) {
    const [text, setText] = useState(search);
    const first = useRef(true);

    // The search runs on the server after a short pause, keeping the Workspace being looked at.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return undefined;
        }

        const timer = setTimeout(() => router.get(baseUrl, { ...baseParams, q: text || undefined }, { preserveState: true, preserveScroll: true, replace: true, only: ['conversations', 'filters'] }), 300);

        return () => clearTimeout(timer);
    }, [text]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="flex min-h-0 flex-col">
            <div className="border-b border-line p-3">
                <SearchInput value={text} onChange={setText} placeholder="Buscar por nombre o número" label="Buscar conversaciones" />
            </div>

            {conversations.length === 0 ? (
                <p className="p-6 text-center text-sm text-ink-muted">{search ? 'Ninguna conversación coincide con la búsqueda.' : 'Todavía no hay conversaciones.'}</p>
            ) : (
                <ul className="min-h-0 flex-1 divide-y divide-line overflow-y-auto" aria-label="Conversaciones">
                    {conversations.map((conversation) => {
                        const active = conversation.id === selectedId;
                        const label = contactLabel(conversation);

                        return (
                            <li key={conversation.id}>
                                <Link
                                    href={hrefFor(conversation.id)}
                                    preserveScroll
                                    aria-current={active ? 'true' : undefined}
                                    className={`flex items-start gap-3 px-4 py-3 outline-none transition-colors focus-visible:bg-primary-soft ${active ? 'bg-primary-soft' : 'hover:bg-canvas'}`}
                                >
                                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-primary/15 text-sm font-bold text-accent-blue" aria-hidden="true">
                                        {initial(conversation)}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-baseline justify-between gap-2">
                                            <span className="truncate text-sm font-semibold text-ink">{label}</span>
                                            <span className="shrink-0 text-[11px] text-ink-muted">{conversation.lastAt}</span>
                                        </span>
                                        <span className="mt-0.5 block truncate text-sm text-ink-muted">{conversation.preview ?? 'Sin mensajes'}</span>
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
