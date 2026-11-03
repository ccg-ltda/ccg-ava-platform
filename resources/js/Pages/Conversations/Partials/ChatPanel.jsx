import { Link } from '@inertiajs/react';
import { Activity, AlertCircle, ArrowLeft, AudioLines, Check, CheckCheck, Clock, File, Image, MapPin, MessageSquareText, Sticker, ThumbsDown, Video } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ActivityPanel from './ActivityPanel';
import Badge from '@/Components/Badge';
import { channelIcon } from '@/config/chatbots';
import { handlingStates, messageStatuses } from '@/config/conversations';
import ChatActions from './ChatActions';
import ContextPanel from './ContextPanel';
import { SimulationBar, StatusSimulator } from './DemoTools';
import Composer from './Composer';
import FeedbackDialog from './FeedbackDialog';
import Operations from './Operations';
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

/** Delivery ticks of an outgoing message: one tick sent, two delivered, two highlighted read. Each state has its own icon, never only a colour. */
const TICKS = { pending: Clock, sent: Check, delivered: CheckCheck, read: CheckCheck, failed: AlertCircle, unconfirmed: AlertCircle };

/** The time of a message and, for an outgoing one, its delivery state, on one line at the end of the bubble (floats right, or drops under a long text). */
function Meta({ message, mine, failed }) {
    const Tick = mine ? TICKS[message.status] : null;
    const detail = [messageStatuses[message.status], message.status === 'delivered' && message.deliveredTime, message.status === 'read' && message.readTime, message.simulated && 'simulado'].filter(Boolean).join(' · ');

    return (
        <span className={`float-right -mb-0.5 ml-3 mt-1.5 inline-flex items-center gap-1 text-[11px] leading-none text-ink-muted`}>
            {message.simulated && (
                <span className="rounded bg-primary/10 px-1 py-0.5 text-[10px] font-bold uppercase ring-1 ring-current" title="Mensaje ficticio de la demo: no pasó por ningún proveedor real">
                    Simulado
                </span>
            )}
            <time>{message.time}</time>
            {Tick && (
                <span title={detail} className={`inline-flex items-center gap-1 ${failed ? 'font-semibold' : ''}`}>
                    <Tick className={`size-3.5 ${message.status === 'read' ? 'text-primary' : ''}`} aria-hidden="true" />
                    {failed ? <span>{messageStatuses[message.status]}</span> : <span className="sr-only">{detail}</span>}
                </span>
            )}
        </span>
    );
}

/** Who wrote an outgoing message: the AI, or the agent by name. */
const author = (message) => (message.sender === 'agent' ? (message.senderName ?? 'Agente') : 'IA');

/** Files sent with a message (support requests): a thumbnail for an image, a player that loads only when played for a video, a link for a PDF. Each url authorizes every read. */
function Attachments({ items }) {
    if (!items?.length) return null;

    const size = (bytes) => (bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / (1024 * 1024)).toFixed(1)} MB`);

    return (
        <ul className="mt-2 space-y-2" aria-label="Archivos adjuntos">
            {items.map((item) => (
                <li key={item.id}>
                    {item.kind === 'image' && (
                        <a href={item.url} target="_blank" rel="noreferrer" aria-label={`Abrir ${item.name}`}>
                            <img src={item.url} alt={item.name} loading="lazy" className="max-h-60 w-full rounded-lg object-cover" />
                        </a>
                    )}
                    {item.kind === 'video' && <video src={item.url} controls preload="none" className="max-h-60 w-full rounded-lg bg-black" aria-label={item.name} />}
                    <a href={item.url} target="_blank" rel="noreferrer" className={`mt-1 flex items-center gap-2 rounded-lg px-2 py-1 text-xs font-medium underline-offset-2 hover:underline bg-canvas/70 ring-1 ring-line`}>
                        <File className="size-4 shrink-0" aria-hidden="true" />
                        <span className="min-w-0 truncate">{item.name}</span>
                        <span className="shrink-0 opacity-75">{size(item.size)}</span>
                    </a>
                </li>
            ))}
        </ul>
    );
}

/** One message: who wrote it, its text, what kind of content it is when it is not plain text, and the state of an outgoing one. */
function Bubble({ message, simulate, conversationId, onFeedback }) {
    // A system line (a session that ended): not a message of the contact or of the team, it has no state and goes nowhere.
    if (message.sender === 'system') {
        return (
            <div className="flex justify-center">
                <p className="rounded-full bg-canvas px-3 py-1 text-center text-xs text-ink-muted">
                    {message.body} · <time>{message.day} {message.time}</time>
                </p>
            </div>
        );
    }

    const mine = message.direction === 'out';
    const type = TYPES[message.type];
    const Icon = type?.icon;
    // A message Meta may or may not have delivered is flagged like a failed one: someone must look before sending it again.
    const failed = message.status === 'failed' || message.status === 'unconfirmed';
    const hasAttachments = Boolean(message.attachments?.length);
    // The meta floats after the last word of the text so a short message stays on one line; with files or no text it sits under the content.
    const meta = <Meta message={message} mine={mine} failed={failed} />;

    return (
        <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
            <div className={`flow-root w-fit min-w-[5.5rem] max-w-[88%] rounded-2xl px-3 py-1.5 text-sm shadow-sm sm:max-w-[78%] ${mine ? `rounded-br-md bubble-out text-ink ${failed ? 'ring-2 ring-danger' : ''}` : 'rounded-bl-md border border-line bg-card text-ink'}`}>
                {mine && <p className="mb-0.5 text-[11px] font-bold text-ink-muted">{author(message)}</p>}
                {type && (
                    <p className={`mb-1 flex items-center gap-1.5 text-xs font-semibold text-accent-blue`}>
                        <Icon className="size-3.5" aria-hidden="true" />
                        {type.label}
                        {message.mediaMime && <span className="font-normal opacity-80">· {message.mediaMime}</span>}
                    </p>
                )}
                {message.body ? (
                    <p className="break-words whitespace-pre-wrap">
                        {message.body}
                        {!hasAttachments && meta}
                    </p>
                ) : type && !message.attachments?.length && <p className="italic opacity-80">El contenido multimedia no se guarda en Ava.</p>}
                <Attachments items={message.attachments} />
                {failed && message.failureReason && <p className="mt-1 rounded bg-danger/10 px-2 py-1 text-xs text-danger">{message.failureReason}</p>}
                {(!message.body || hasAttachments) && <div className="flow-root">{meta}</div>}
                {simulate && mine && message.simulated && <StatusSimulator conversationId={conversationId} messageId={message.id} />}
                {onFeedback && mine && message.sender === 'ai' && message.body && (
                    <button type="button" onClick={onFeedback} className="mt-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-semibold text-ink-muted underline-offset-2 hover:bg-primary/10 hover:text-ink hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                        <ThumbsDown className="size-3" aria-hidden="true" />
                        No me gustó
                    </button>
                )}
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
    const [flagged, setFlagged] = useState(null);
    const [activityOpen, setActivityOpen] = useState(false);

    useEffect(() => setActivityOpen(false), [selected.id]);

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
                    {selected.support && (
                        <p className="truncate text-xs font-semibold text-ink">
                            Soporte Zonno · Workspace: {selected.support.workspaceName ?? 'desconocido'}
                            {selected.support.workspaceCode ? ` (${selected.support.workspaceCode})` : ''} · Solicitó: {selected.support.userName ?? 'desconocido'}
                        </p>
                    )}
                </div>
                <div className="flex w-full shrink-0 flex-wrap items-center gap-1 pl-12 sm:w-auto sm:justify-end sm:pl-0">
                    <Badge tone={state.tone}>{selected.closed ? selected.closed.label : state.label}</Badge>
                    {selected.support && <Badge tone="blue">Soporte Zonno</Badge>}
                    {selected.demo && <Badge tone="violet">Demo</Badge>}
                    {selected.activity && (
                        <button
                            type="button"
                            onClick={() => setActivityOpen((open) => !open)}
                            aria-expanded={activityOpen}
                            className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-semibold outline-none transition-colors focus-visible:outline-2 focus-visible:outline-primary ${activityOpen ? 'border-primary bg-primary/15 text-accent-blue' : 'border-line bg-card text-ink hover:border-primary hover:bg-canvas'}`}
                        >
                            <Activity className="size-3.5" aria-hidden="true" />
                            Actividad técnica
                        </button>
                    )}
                </div>
            </header>

            {selected.closed && (
                <p role="status" className="border-b border-line bg-canvas px-3 py-2 text-xs font-semibold text-ink-muted">
                    {selected.closed.label}{selected.closed.by ? ` · ${selected.closed.by}` : ''} · {selected.closed.at}. La sesión está cerrada: no admite mensajes nuevos y el cliente empieza una conversación nueva si vuelve a escribir.
                </p>
            )}
            {selected.simulate && <SimulationBar conversationId={selected.id} />}
            <ChatActions selected={selected} agents={agents} />
            <ContextPanel key={`context-${selected.id}`} selected={selected} />
            <Operations operations={selected.operations} tools={selected.tools} />

            <div ref={log} className="min-h-0 flex-1 space-y-2 overflow-y-auto bg-canvas/60 p-3 sm:p-4" role="log" aria-label="Mensajes de la conversación">
                {selected.truncated && <p className="pb-2 text-center text-xs text-ink-muted">Se muestran los últimos mensajes de la conversación.</p>}

                {selected.items.map((message, index) => (
                    <div key={message.id} className="space-y-2">
                        {(index === 0 || selected.items[index - 1].day !== message.day) && (
                            <p className="py-1 text-center">
                                <span className="rounded-full bg-card px-3 py-1 text-[11px] font-semibold text-ink-muted ring-1 ring-line">{message.day}</span>
                            </p>
                        )}
                        <Bubble message={message} simulate={selected.simulate} conversationId={selected.id} onFeedback={selected.feedback ? () => setFlagged(message) : null} />
                    </div>
                ))}
            </div>

            <Composer key={selected.id} selected={selected} />
            {activityOpen && selected.activity && <ActivityPanel key={`activity-${selected.id}`} conversationId={selected.id} onClose={() => setActivityOpen(false)} />}
            {flagged && selected.feedback && <FeedbackDialog conversation={selected} message={flagged} feedback={selected.feedback} onClose={() => setFlagged(null)} />}
        </div>
    );
}
