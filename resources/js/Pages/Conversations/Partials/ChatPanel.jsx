import { Link } from '@inertiajs/react';
import { AudioLines, ArrowLeft, File, Image, MapPin, MessageSquareText, Sticker, Video } from 'lucide-react';
import { useEffect, useRef } from 'react';
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

const STATUSES = { sent: 'Enviado', delivered: 'Entregado', read: 'Leído', failed: 'No se pudo enviar' };

/** One message: its text, what kind of content it is when it is not plain text, and the state of an outgoing one. */
function Bubble({ message }) {
    const mine = message.direction === 'out';
    const type = TYPES[message.type];
    const Icon = type?.icon;

    return (
        <div className={`flex ${mine ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[85%] rounded-2xl px-3.5 py-2 text-sm shadow-sm sm:max-w-[75%] ${mine ? 'rounded-br-md bg-primary text-white' : 'rounded-bl-md border border-line bg-card text-ink'}`}>
                {type && (
                    <p className={`mb-1 flex items-center gap-1.5 text-xs font-semibold ${mine ? 'text-white/85' : 'text-accent-blue'}`}>
                        <Icon className="size-3.5" aria-hidden="true" />
                        {type.label}
                        {message.mediaMime && <span className="font-normal opacity-80">· {message.mediaMime}</span>}
                    </p>
                )}
                {message.body ? <p className="break-words whitespace-pre-wrap">{message.body}</p> : type && <p className="italic opacity-80">El contenido multimedia no se guarda en Ava.</p>}
                <p className={`mt-1 flex items-center justify-end gap-2 text-[11px] ${mine ? 'text-white/80' : 'text-ink-muted'}`}>
                    {mine && message.status && <span className={message.status === 'failed' ? 'font-semibold' : ''}>{STATUSES[message.status]}</span>}
                    <time>{message.time}</time>
                </p>
            </div>
        </div>
    );
}

/** The selected conversation as a chat: day separators, incoming on the left, outgoing on the right. Read only. */
export default function ChatPanel({ selected, backHref }) {
    const end = useRef(null);
    const last = selected.items.at(-1)?.id;

    // Opening a conversation, or a new message arriving, shows the newest message.
    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [selected.id, last]);

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <header className="flex items-center gap-3 border-b border-line px-4 py-3">
                <Link href={backHref} preserveScroll className="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted outline-none hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary lg:hidden" aria-label="Volver a las conversaciones">
                    <ArrowLeft className="size-5" aria-hidden="true" />
                </Link>
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-primary/15 text-sm font-bold text-accent-blue" aria-hidden="true">
                    {initial(selected)}
                </span>
                <div className="min-w-0">
                    <h2 className="truncate text-base font-bold text-ink">{contactLabel(selected)}</h2>
                    {selected.contactName && <p className="truncate text-xs text-ink-muted">{contactNumber(selected.contactId)}</p>}
                </div>
            </header>

            <div className="min-h-0 flex-1 space-y-2 overflow-y-auto bg-canvas/60 p-4" role="log" aria-label="Mensajes de la conversación">
                {selected.truncated && <p className="pb-2 text-center text-xs text-ink-muted">Se muestran los últimos mensajes de la conversación.</p>}

                {selected.items.map((message, index) => (
                    <div key={message.id} className="space-y-2">
                        {(index === 0 || selected.items[index - 1].day !== message.day) && (
                            <p className="py-1 text-center">
                                <span className="rounded-full bg-card px-3 py-1 text-[11px] font-semibold text-ink-muted ring-1 ring-line">{message.day}</span>
                            </p>
                        )}
                        <Bubble message={message} />
                    </div>
                ))}
                <div ref={end} />
            </div>

            <p className="border-t border-line px-4 py-2.5 text-center text-xs text-ink-muted">Vista de solo lectura: las respuestas las envía n8n por el canal.</p>
        </div>
    );
}
