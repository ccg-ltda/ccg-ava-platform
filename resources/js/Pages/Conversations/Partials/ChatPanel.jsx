import { Link } from '@inertiajs/react';
import { AudioLines, ArrowLeft, File, Image, MapPin, MessageSquareText, Sticker, Video } from 'lucide-react';
import { useEffect, useRef } from 'react';
import Badge from '@/Components/Badge';
import { channelIcon } from '@/config/chatbots';
import { handlingStates, messageStatuses } from '@/config/conversations';
import ChatActions from './ChatActions';
import { SimulationBar, StatusSimulator } from './DemoTools';
import Composer from './Composer';
import { contactLabel, contactNumber, initial } from './contact';

const TYPES = {
    audio: { label: 'Audio', icon: AudioLines },
    image: { label: 'Imagen', icon: Image },
    video: { label: 'Video', icon: Video },
    document: { label: 'Documento', icon: File },
    sticker: { label: 'Sticker', icon: Sticker },
    location: { label: 'Ubicación', icon: MapPin },
    other: { label: 'Mensaje', icon: MessageSquareText },
};

/** Who wrote an outgoing message: the AI, or the agent by name. */
const author = (message) => (message.sender === 'agent' ? (message.senderName ?? 'Agente') : 'IA');

/** One message: who wrote it, its text, what kind of content it is when it is not plain text, and the state of an outgoing one. */
function Bubble({ message, simulate, conversationId }) {
    const mine = message.direction === 'out';
    const type = TYPES[message.type];
    const Icon = type?.icon;
    // A message Meta may or may not have delivered is flagged like a failed one: someone must look before sending it again.
    const failed = message.status === 'failed' || message.status === 'unconfirmed';

    return (
        <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[85%] rounded-2xl px-3.5 py-2 text-sm shadow-sm sm:max-w-[75%] ${mine ? `rounded-br-md bg-primary text-white ${failed ? 'ring-2 ring-danger' : ''}` : 'rounded-bl-md border border-line bg-card text-ink'}`}>
                {mine && <p className="mb-0.5 text-[11px] font-bold text-white/85">{author(message)}</p>}
                {type && (
                    <p className={`mb-1 flex items-center gap-1.5 text-xs font-semibold ${mine ? 'text-white/85' : 'text-accent-blue'}`}>
                        <Icon className="size-3.5" aria-hidden="true" />
                        {type.label}
                        {message.mediaMime && <span className="font-normal opacity-80">· {message.mediaMime}</span>}
                    </p>
                )}
                {message.body ? <p className="break-words whitespace-pre-wrap">{message.body}</p> : type && <p className="italic opacity-80">El contenido multimedia no se guarda en Ava.</p>}
                {failed && message.failureReason && <p className="mt-1 rounded bg-white/15 px-2 py-1 text-xs">{message.failureReason}</p>}
                <p className={`mt-1 flex items-center justify-end gap-2 text-[11px] ${mine ? 'text-white/80' : 'text-ink-muted'}`}>
                    {message.simulated && (
                        <span className="rounded bg-white/20 px-1.5 py-0.5 font-bold uppercase ring-1 ring-current" title="Mensaje ficticio de la demo: no pasó por ningún proveedor real">
                            Simulado
                        </span>
                    )}
                    {mine && message.status && (
                        <span className={failed ? 'font-semibold' : ''}>
                            {messageStatuses[message.status]}
                            {message.simulated && ' (simulado)'}
                        </span>
                    )}
                    <time>{message.time}</time>
                </p>
                {simulate && mine && message.simulated && <StatusSimulator conversationId={conversationId} messageId={message.id} />}
            </div>
        </div>
    );
}

/** The selected conversation: who it is, who answers, the chat (incoming on the left, outgoing on the right) and the composer. */
export default function ChatPanel({ selected, backHref, agents }) {
    const log = useRef(null);
    const last = selected.items.at(-1)?.id;
    const lastStatus = selected.items.at(-1)?.status;
    const ChannelIcon = channelIcon(selected.channel.key);
    const state = handlingStates[selected.handling];

    // Opening a conversation, or a new message arriving, shows the newest message.
    useEffect(() => {
        log.current?.scrollTo({ top: log.current.scrollHeight });
    }, [selected.id, last, lastStatus]);

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-3 py-2 sm:flex-nowrap">
                <Link href={backHref} preserveScroll className="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted outline-none hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary lg:hidden" aria-label="Volver a las conversaciones">
                    <ArrowLeft className="size-5" aria-hidden="true" />
                </Link>
                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-primary/15 text-sm font-bold text-accent-blue" aria-hidden="true">
                    {initial(selected)}
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-base font-bold text-ink">{contactLabel(selected)}</h2>
                    <p className="flex items-center gap-1 truncate text-xs text-ink-muted">
                        <ChannelIcon className="size-3.5 shrink-0" aria-hidden="true" />
                        <span className="truncate">
                            {selected.channel.label}
                            {selected.contactName && ` · ${contactNumber(selected.contactId)}`} · {selected.chatbot.name}
                        </span>
                    </p>
                </div>
                <div className="flex w-full shrink-0 flex-wrap items-center gap-1 pl-12 sm:w-auto sm:justify-end sm:pl-0">
                    <Badge tone={state.tone}>{state.label}</Badge>
                    {selected.demo && <Badge tone="violet">Demo</Badge>}
                </div>
            </header>

            {selected.simulate && <SimulationBar conversationId={selected.id} />}
            <ChatActions selected={selected} agents={agents} />

            <div ref={log} className="min-h-0 flex-1 space-y-2 overflow-y-auto bg-canvas/60 p-3 sm:p-4" role="log" aria-label="Mensajes de la conversación">
                {selected.truncated && <p className="pb-2 text-center text-xs text-ink-muted">Se muestran los últimos mensajes de la conversación.</p>}

                {selected.items.map((message, index) => (
                    <div key={message.id} className="space-y-2">
                        {(index === 0 || selected.items[index - 1].day !== message.day) && (
                            <p className="py-1 text-center">
                                <span className="rounded-full bg-card px-3 py-1 text-[11px] font-semibold text-ink-muted ring-1 ring-line">{message.day}</span>
                            </p>
                        )}
                        <Bubble message={message} simulate={selected.simulate} conversationId={selected.id} />
                    </div>
                ))}
            </div>

            <Composer key={selected.id} selected={selected} />
        </div>
    );
}
