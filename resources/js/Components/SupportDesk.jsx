import { usePage } from '@inertiajs/react';
import { LifeBuoy, Mail, Send, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import jsonRequest from '@/lib/jsonRequest';

export const OPEN_SUPPORT_EVENT = 'zonno:open-support';

/** Opens the support panel from anywhere (the Ayuda panel uses it). */
export const openSupport = () => window.dispatchEvent(new Event(OPEN_SUPPORT_EVENT));

const newId = () => (window.crypto?.randomUUID ? window.crypto.randomUUID().replace(/-/g, '') : `${Date.now()}${Math.random().toString(36).slice(2, 12)}`);

const BANNER = {
    none: 'text-ink-muted',
    waiting: 'text-accent-amber',
    attending: 'text-accent-green',
    resolved: 'text-ink-muted',
};

/**
 * The support panel of the administrators of a client Workspace: a floating button on the Dashboard (and the Ayuda panel, anywhere) that opens a chat panel in place. The
 * conversation lives in Conversaciones of CCG's administrative Workspace; the server decides who may write and where it lands, so this component only reads `supportDesk`
 * (null = not an administrator = nothing is drawn). It never says a person has the request until the server says so: «En espera» is shown until somebody of the team takes it.
 */
export default function SupportDesk() {
    const page = usePage();
    const { supportDesk, help } = page.props;
    const [open, setOpen] = useState(false);
    const [thread, setThread] = useState(null);
    const [loading, setLoading] = useState(false);
    const [problem, setProblem] = useState(null);
    const [text, setText] = useState('');
    const [queue, setQueue] = useState([]);
    const sending = useRef(false);
    const field = useRef(null);
    const list = useRef(null);

    const load = useCallback(async (quiet = false) => {
        if (!quiet) setLoading(true);

        try {
            setThread(await jsonRequest(route('support.thread', undefined, false)));
            setProblem(null);
        } catch (error) {
            if (!quiet) setProblem(error.status === 403 ? 'El soporte está disponible solo para los administradores del Workspace.' : error.message);
        } finally {
            if (!quiet) setLoading(false);
        }
    }, []);

    useEffect(() => {
        const show = () => setOpen(true);
        window.addEventListener(OPEN_SUPPORT_EVENT, show);

        return () => window.removeEventListener(OPEN_SUPPORT_EVENT, show);
    }, []);

    useEffect(() => {
        if (!open || supportDesk?.state !== 'ready') return undefined;

        load();
        field.current?.focus();
        const timer = setInterval(() => document.visibilityState === 'visible' && !sending.current && load(true), (supportDesk.poll ?? 8) * 1000);
        const onKey = (event) => event.key === 'Escape' && setOpen(false);
        document.addEventListener('keydown', onKey);

        return () => {
            clearInterval(timer);
            document.removeEventListener('keydown', onKey);
        };
    }, [open, supportDesk?.state, load]);

    useEffect(() => {
        list.current?.scrollTo({ top: list.current.scrollHeight });
    }, [thread, queue, open]);

    if (!supportDesk) return null;

    const onDashboard = String(page.url ?? '').split('?')[0].startsWith('/dashboard');
    const ready = supportDesk.state === 'ready';

    const deliver = async (item) => {
        if (sending.current) return;

        sending.current = true;
        setQueue((items) => items.map((entry) => (entry.id === item.id ? { ...entry, status: 'sending', error: null } : entry)));

        try {
            const result = await jsonRequest(route('support.messages', undefined, false), { method: 'POST', body: { message: item.text, message_id: item.id } });
            setThread(result);
            setQueue((items) => items.filter((entry) => entry.id !== item.id));
        } catch (error) {
            setQueue((items) => items.map((entry) => (entry.id === item.id ? { ...entry, status: 'failed', error: error.message } : entry)));
        } finally {
            sending.current = false;
        }
    };

    const submit = (event) => {
        event.preventDefault();
        const body = text.trim();

        if (!body || sending.current) return;

        const item = { id: newId(), text: body, status: 'sending', error: null };
        setQueue((items) => [...items, item]);
        setText('');
        deliver(item);
    };

    const messages = thread?.messages ?? [];

    return (
        <>
            {open && (
                <section
                    role="dialog"
                    aria-label="Soporte de Zonno"
                    className="fixed inset-x-3 bottom-20 z-50 flex max-h-[min(34rem,calc(100dvh-7rem))] flex-col overflow-hidden rounded-2xl border border-line bg-card shadow-2xl sm:inset-x-auto sm:right-5 sm:w-96"
                >
                    <header className="flex items-center gap-3 border-b border-line px-4 py-3">
                        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                            <LifeBuoy className="size-5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <h2 className="truncate text-sm font-bold text-ink">Soporte de Zonno</h2>
                            <p className="truncate text-xs text-ink-muted">Te responde el equipo de CCG</p>
                        </div>
                        <button type="button" onClick={() => setOpen(false)} className="grid size-9 place-items-center rounded-lg text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary" aria-label="Cerrar el soporte">
                            <X className="size-5" aria-hidden="true" />
                        </button>
                    </header>

                    {!ready ? (
                        <div className="space-y-3 p-5 text-sm text-ink-muted" role="status">
                            <p>El soporte por chat no está disponible en este momento.</p>
                            {help?.mailto ? (
                                <a href={help.mailto} className="inline-flex items-center gap-2 rounded-lg border border-line px-4 py-2 font-semibold text-ink hover:bg-canvas">
                                    <Mail className="size-4" aria-hidden="true" />
                                    Escribir por correo
                                </a>
                            ) : (
                                <p>Tampoco hay un correo de soporte configurado todavía.</p>
                            )}
                        </div>
                    ) : (
                        <>
                            {thread && <p role="status" className={`border-b border-line px-4 py-2 text-xs font-semibold ${BANNER[thread.state] ?? 'text-ink-muted'}`}>{thread.label}</p>}

                            <div ref={list} className="min-h-40 flex-1 space-y-2 overflow-y-auto overscroll-contain px-4 py-3" aria-live="polite" aria-label="Mensajes de la conversación de soporte">
                                {loading && !thread && <p className="text-sm text-ink-muted">Cargando la conversación...</p>}
                                {problem && (
                                    <p role="alert" className="text-sm text-danger">
                                        {problem}{' '}
                                        <button type="button" onClick={() => load()} className="font-semibold underline">
                                            Reintentar
                                        </button>
                                    </p>
                                )}
                                {thread && messages.length === 0 && queue.length === 0 && <p className="text-sm text-ink-muted">Cuéntanos qué necesitas. Tu mensaje llega al equipo de soporte de CCG y la respuesta aparece aquí.</p>}
                                {messages.map((message) => (
                                    <div key={message.id} className={`flex ${message.mine ? 'justify-end' : 'justify-start'}`}>
                                        <div className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap ${message.mine ? 'bg-primary text-white' : 'bg-canvas text-ink'}`}>
                                            {!message.mine && message.author && <p className="mb-0.5 text-[11px] font-semibold text-ink-muted">{message.author}</p>}
                                            {message.text}
                                        </div>
                                    </div>
                                ))}
                                {queue.map((item) => (
                                    <div key={item.id} className="flex flex-col items-end gap-1">
                                        <div className="max-w-[85%] rounded-2xl bg-primary/70 px-3 py-2 text-sm whitespace-pre-wrap text-white">{item.text}</div>
                                        {item.status === 'sending' && <span className="text-[11px] text-ink-muted">Enviando...</span>}
                                        {item.status === 'failed' && (
                                            <span role="alert" className="text-[11px] text-danger">
                                                {item.error}{' '}
                                                <button type="button" onClick={() => deliver(item)} className="font-semibold underline">
                                                    Reintentar
                                                </button>
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>

                            <form onSubmit={submit} className="flex items-end gap-2 border-t border-line p-3">
                                <textarea
                                    ref={field}
                                    value={text}
                                    onChange={(e) => setText(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !e.shiftKey) submit(e);
                                    }}
                                    rows={2}
                                    maxLength={2000}
                                    aria-label="Escribe tu mensaje"
                                    placeholder="Escribe tu mensaje..."
                                    className="min-h-11 flex-1 resize-none rounded-lg border border-line bg-card px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-primary"
                                />
                                <button
                                    type="submit"
                                    disabled={!text.trim() || queue.some((item) => item.status === 'sending')}
                                    className="grid size-11 shrink-0 place-items-center rounded-lg bg-primary text-white disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                    aria-label="Enviar mensaje"
                                >
                                    <Send className="size-5" aria-hidden="true" />
                                </button>
                            </form>
                        </>
                    )}
                </section>
            )}

            {onDashboard && !open && (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="fixed right-4 bottom-4 z-50 inline-flex h-14 items-center gap-2 rounded-full bg-primary px-5 text-sm font-bold text-white shadow-xl hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:right-6 sm:bottom-6"
                    aria-label="Abrir ayuda y soporte"
                >
                    <LifeBuoy className="size-5" aria-hidden="true" />
                    <span className="hidden sm:inline">Ayuda y soporte</span>
                </button>
            )}
        </>
    );
}
