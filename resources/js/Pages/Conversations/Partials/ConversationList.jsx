import { Link, router } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Badge from '@/Components/Badge';
import SearchInput from '@/Components/SearchInput';
import { channelIcon } from '@/config/chatbots';
import { handlingStates } from '@/config/conversations';
import { contactLabel, initial } from './contact';

/** The conversations of the inbox, newest first, with a server-side search by name or number. */
export default function ConversationList({ conversations, selectedId, search, hrefFor, baseUrl, baseParams, showChannel, filtered }) {
    const [text, setText] = useState(search);
    const first = useRef(true);

    // A search applied from outside (a saved filter) fills the box.
    useEffect(() => setText(search), [search]);

    // The search runs on the server after a short pause, keeping the filters and the Workspace being looked at.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return undefined;
        }

        if (text === search) return undefined;

        const timer = setTimeout(() => router.get(baseUrl, { ...baseParams, q: text || undefined }, { preserveState: true, preserveScroll: true, replace: true, only: ['conversations', 'filters', 'counts'] }), 300);

        return () => clearTimeout(timer);
    }, [text]); // eslint-disable-line react-hooks/exhaustive-deps

    return (
        <div className="flex min-h-0 flex-col">
            <div className="border-b border-line p-3">
                <SearchInput value={text} onChange={setText} placeholder="Buscar por nombre o número" label="Buscar conversaciones" />
            </div>

            {conversations.length === 0 ? (
                <div className="grid flex-1 place-items-center p-6 text-center">
                    <div>
                        <MessagesSquare className="mx-auto size-8 text-ink-muted" aria-hidden="true" />
                        <p className="mt-2 text-sm font-semibold text-ink">{search || filtered ? 'Sin resultados' : 'Todavía no hay conversaciones'}</p>
                        <p className="mt-1 text-sm text-ink-muted">{search || filtered ? 'Ninguna conversación coincide con los filtros. Cambia el canal, el asistente o el estado para ver otras.' : 'Aparecerán aquí cuando los contactos escriban.'}</p>
                    </div>
                </div>
            ) : (
                <ul className="min-h-0 flex-1 divide-y divide-line overflow-y-auto" aria-label="Conversaciones">
                    {conversations.map((conversation) => {
                        const active = conversation.id === selectedId;
                        const state = handlingStates[conversation.handling];
                        const ChannelIcon = channelIcon(conversation.channel.key);

                        return (
                            <li key={conversation.id}>
                                <Link
                                    href={hrefFor(conversation.id)}
                                    preserveScroll
                                    aria-current={active ? 'true' : undefined}
                                    className={`flex items-start gap-3 px-4 py-3 outline-none transition-colors focus-visible:bg-primary/15 ${active ? 'bg-primary/15' : 'hover:bg-canvas'}`}
                                >
                                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-primary/15 text-sm font-bold text-accent-blue" aria-hidden="true">
                                        {initial(conversation)}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-baseline justify-between gap-2">
                                            <span className="truncate text-sm font-semibold text-ink">{contactLabel(conversation)}</span>
                                            <span className="shrink-0 text-[11px] text-ink-muted">{conversation.lastAt}</span>
                                        </span>
                                        <span className="mt-0.5 block truncate text-sm text-ink-muted">{conversation.preview ?? 'Sin mensajes'}</span>
                                        <span className="mt-1.5 flex items-center gap-1.5 overflow-hidden">
                                            <Badge tone={state.tone} className="shrink-0 px-2! py-0.5 text-[10px]! tracking-wide! whitespace-nowrap">
                                                {state.label}
                                            </Badge>
                                            {showChannel && (
                                                <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-canvas px-2 py-0.5 text-[10px] font-semibold whitespace-nowrap text-ink-muted ring-1 ring-line">
                                                    <ChannelIcon className="size-3" aria-hidden="true" />
                                                    {conversation.channel.label}
                                                </span>
                                            )}
                                            {conversation.handling === 'human' && conversation.assignedName && <span className="min-w-0 truncate text-[11px] text-ink-muted">{conversation.assignedName}</span>}
                                            {conversation.demo && (
                                                <span className="ml-auto shrink-0 rounded px-1 text-[9px] font-bold tracking-wider text-accent-violet uppercase ring-1 ring-accent-violet/40" title="Conversación simulada de la demo">
                                                    Demo
                                                </span>
                                            )}
                                        </span>
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
