import { usePage } from '@inertiajs/react';
import { File as FileIcon, LifeBuoy, Mail, Paperclip, Send, Video, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useConfirm } from '@/Components/ConfirmDialog';
import { addFiles, formatSize } from '@/lib/attachments';
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
    closed: 'text-ink-muted',
};

/** A compact preview of a file about to be sent: the image itself, or an icon for a video or a PDF (the file is not read until it is sent). */
function Chip({ file, previewUrl, onRemove, disabled }) {
    const Icon = file.type.startsWith('video/') ? Video : FileIcon;

    return (
        <li className="flex max-w-full items-center gap-2 rounded-lg border border-line bg-canvas py-1 pr-1 pl-1.5 text-xs text-ink">
            {previewUrl ? <img src={previewUrl} alt="" className="size-8 shrink-0 rounded object-cover" /> : <Icon className="size-8 shrink-0 rounded bg-card p-1.5 text-ink-muted" aria-hidden="true" />}
            <span className="min-w-0">
                <span className="block max-w-32 truncate font-medium">{file.name}</span>
                <span className="block text-ink-muted">{formatSize(file.size)}</span>
            </span>
            {!disabled && (
                <button type="button" onClick={onRemove} aria-label={`Quitar ${file.name}`} className="grid size-6 shrink-0 place-items-center rounded-full text-ink-muted hover:bg-card focus-visible:outline-2 focus-visible:outline-primary">
                    <X className="size-3.5" aria-hidden="true" />
                </button>
            )}
        </li>
    );
}

/** The files a message already carries, as the server returned them: a thumbnail for an image, a link for a video or a PDF (nothing heavy is loaded). */
function Sent({ attachments, mine }) {
    if (!attachments?.length) return null;

    return (
        <ul className="mt-1.5 space-y-1.5">
            {attachments.map((item) => (
                <li key={item.id}>
                    {item.kind === 'image' ? (
                        <a href={item.url} target="_blank" rel="noreferrer" aria-label={`Abrir ${item.name}`}>
                            <img src={item.url} alt={item.name} loading="lazy" className="max-h-40 w-full rounded-lg object-cover" />
                        </a>
                    ) : (
                        <a href={item.url} target="_blank" rel="noreferrer" className={`flex items-center gap-2 rounded-lg px-2 py-1.5 text-xs font-medium underline-offset-2 hover:underline ${mine ? 'bg-white/15' : 'bg-card'}`}>
                            {item.kind === 'video' ? <Video className="size-4 shrink-0" aria-hidden="true" /> : <FileIcon className="size-4 shrink-0" aria-hidden="true" />}
                            <span className="min-w-0 truncate">{item.name}</span>
                            <span className="shrink-0 opacity-75">{formatSize(item.size)}</span>
                        </a>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * The support panel of the administrators of a client Workspace: a floating button on the Dashboard (and the Ayuda panel, anywhere) that opens a chat panel in place. The
 * conversation lives in Conversaciones of CCG's administrative Workspace; the server decides who may write and where it lands, so this component only reads `supportDesk`
 * (null = not an administrator = nothing is drawn). It never says a person has the request until the server says so: «En espera» is shown until somebody of the team takes it.
 * A message can carry screenshots, videos and PDFs: they are chosen here, checked again by the server on their real content, and shown only through routes that authorize every read.
 */
export default function SupportDesk() {
    const page = usePage();
    const confirm = useConfirm();
    const { supportDesk, help } = page.props;
    const [open, setOpen] = useState(false);
    const [thread, setThread] = useState(null);
    const [loading, setLoading] = useState(false);
    const [problem, setProblem] = useState(null);
    const [text, setText] = useState('');
    const [files, setFiles] = useState([]);
    const [fileErrors, setFileErrors] = useState([]);
    const [queue, setQueue] = useState([]);
    // The session this window shows (null = none yet). The server decides when it ended; the window only reflects it and then offers a NEW conversation.
    const session = useRef(null);
    const [finishing, setFinishing] = useState(false);
    const sending = useRef(false);
    const field = useRef(null);
    const list = useRef(null);
    const picker = useRef(null);
    const limits = supportDesk?.attachments;
    const previews = useMemo(() => files.map((file) => (file.type.startsWith('image/') ? URL.createObjectURL(file) : null)), [files]);

    useEffect(() => () => previews.forEach((url) => url && URL.revokeObjectURL(url)), [previews]);

    const load = useCallback(async (quiet = false) => {
        if (!quiet) setLoading(true);

        try {
            // `seen` only when the panel is open AND the tab is visible: that is what makes the team's messages «read» (a background poll proves nothing).
            const query = new URLSearchParams({ ...(session.current ? { session: session.current } : {}), ...(document.visibilityState === 'visible' ? { seen: '1' } : {}) });
            const next = await jsonRequest(route('support.thread', undefined, false) + (query.size ? `?${query}` : ''));
            session.current = next.state === 'none' ? null : (next.session ?? null);
            setThread(next);
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

    const choose = (event) => {
        const result = addFiles(files, Array.from(event.target.files ?? []), limits);
        setFiles(result.files);
        setFileErrors(result.errors);
        event.target.value = ''; // the same file can be chosen again after removing it
    };

    const deliver = async (item) => {
        if (sending.current) return;

        sending.current = true;
        setQueue((items) => items.map((entry) => (entry.id === item.id ? { ...entry, status: 'sending', error: null } : entry)));

        try {
            const body = new FormData();
            if (session.current) body.append('session', String(session.current));
            body.append('message', item.text);
            body.append('message_id', item.id);
            item.files.forEach((file) => body.append('files[]', file));
            const result = await jsonRequest(route('support.messages', undefined, false), { method: 'POST', body });
            session.current = result.session ?? null;
            setThread(result);
            setQueue((items) => items.filter((entry) => entry.id !== item.id));
        } catch (error) {
            setQueue((items) => items.map((entry) => (entry.id === item.id ? { ...entry, status: 'failed', error: error.message } : entry)));
            // The session ended while the message was being written: show the ending now (the text stays in the failed message, to correct or copy).
            if (error.status === 422 && /finalizada/.test(error.message)) load(true);
        } finally {
            sending.current = false;
        }
    };

    const submit = (event) => {
        event.preventDefault();
        const body = text.trim();

        if ((!body && files.length === 0) || sending.current) return;

        const item = { id: newId(), text: body, files, status: 'sending', error: null };
        setQueue((items) => [...items, item]);
        setText('');
        setFiles([]);
        setFileErrors([]);
        deliver(item);
    };

    // A message the server refused (a file that is not what it claims, too big…) goes back to the composer with its text and files, so it can be corrected instead of retried as it is.
    const edit = (item) => {
        setQueue((items) => items.filter((entry) => entry.id !== item.id));
        setText(item.text);
        setFiles(item.files);
        setFileErrors([]);
        field.current?.focus();
    };

    const closed = thread?.state === 'closed';
    const canFinish = Boolean(thread?.session) && !closed && thread?.state !== 'none';

    const finish = async () => {
        const confirmed = await confirm({ description: 'Se finalizará esta conversación y no podrás escribir más en ella. El equipo conserva el historial y, si necesitas ayuda otra vez, podrás iniciar una nueva.', confirmLabel: 'Finalizar conversación' });

        if (!confirmed) return;

        setFinishing(true);

        try {
            setThread(await jsonRequest(route('support.close', undefined, false), { method: 'POST', body: { session: thread.session } }));
        } catch (error) {
            setProblem(error.message);
        } finally {
            setFinishing(false);
        }
    };

    // The ended session is left behind: the next message opens a NEW conversation with its own id and none of the old messages.
    const startNew = () => {
        session.current = null;
        setQueue([]);
        setThread({ state: 'none', label: 'Aún no has enviado ninguna solicitud.', agent: null, session: null, messages: [] });
        field.current?.focus();
    };

    const messages = thread?.messages ?? [];
    const busy = queue.some((item) => item.status === 'sending');

    return (
        <>
            {open && (
                <section
                    role="dialog"
                    aria-label="Soporte de Zonno"
                    className="fixed inset-x-3 bottom-20 z-50 flex h-[min(34.5rem,calc(100dvh-6.5rem))] flex-col overflow-hidden rounded-2xl border border-line bg-card shadow-2xl sm:inset-x-auto sm:right-5 sm:w-96"
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
                            {thread && (
                                <div className="flex items-center justify-between gap-2 border-b border-line px-4 py-2">
                                    <p role="status" className={`min-w-0 text-xs font-semibold ${BANNER[thread.state] ?? 'text-ink-muted'}`}>{thread.label}</p>
                                    {canFinish && (
                                        <button type="button" onClick={finish} disabled={finishing} className="shrink-0 rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-ink-muted hover:bg-canvas hover:text-ink disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-primary">
                                            Finalizar
                                        </button>
                                    )}
                                </div>
                            )}

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
                                {thread && messages.length === 0 && queue.length === 0 && <p className="text-sm text-ink-muted">Cuéntanos qué necesitas. Puedes adjuntar capturas, videos o PDF. Tu mensaje llega al equipo de soporte de CCG y la respuesta aparece aquí.</p>}
                                {messages.map((message) => (
                                    <div key={message.id} className={`flex ${message.mine ? 'justify-end' : 'justify-start'}`}>
                                        <div className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap ${message.mine ? 'bg-primary text-white' : 'bg-canvas text-ink'}`}>
                                            {!message.mine && message.author && <p className="mb-0.5 text-[11px] font-semibold text-ink-muted">{message.author}</p>}
                                            {message.text}
                                            <Sent attachments={message.attachments} mine={message.mine} />
                                        </div>
                                    </div>
                                ))}
                                {queue.map((item) => (
                                    <div key={item.id} className="flex flex-col items-end gap-1">
                                        <div className="max-w-[85%] rounded-2xl bg-primary/70 px-3 py-2 text-sm whitespace-pre-wrap text-white">
                                            {item.text}
                                            {item.files.length > 0 && <p className="mt-1 text-xs opacity-90">{item.files.length} archivo{item.files.length === 1 ? '' : 's'} adjunto{item.files.length === 1 ? '' : 's'}</p>}
                                        </div>
                                        {item.status === 'sending' && <span className="text-[11px] text-ink-muted">{item.files.length > 0 ? 'Enviando y subiendo archivos...' : 'Enviando...'}</span>}
                                        {item.status === 'failed' && (
                                            <span role="alert" className="text-[11px] text-danger">
                                                {item.error}{' '}
                                                <button type="button" onClick={() => deliver(item)} className="font-semibold underline">
                                                    Reintentar
                                                </button>{' · '}
                                                <button type="button" onClick={() => edit(item)} className="font-semibold underline">
                                                    Corregir
                                                </button>
                                            </span>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {closed ? (
                                <div className="border-t border-line p-3">
                                    <button type="button" onClick={startNew} className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                        Iniciar una nueva conversación
                                    </button>
                                </div>
                            ) : (
                            <form onSubmit={submit} className="border-t border-line p-3">
                                {fileErrors.length > 0 && (
                                    <ul role="alert" className="mb-2 space-y-0.5 text-xs text-danger">
                                        {fileErrors.map((message) => <li key={message}>{message}</li>)}
                                    </ul>
                                )}
                                {files.length > 0 && (
                                    <ul aria-label="Archivos por enviar" className="mb-2 flex max-h-24 flex-wrap gap-1.5 overflow-y-auto">
                                        {files.map((file, index) => (
                                            <Chip key={`${file.name}-${file.size}-${index}`} file={file} previewUrl={previews[index]} disabled={busy} onRemove={() => setFiles((current) => current.filter((_, position) => position !== index))} />
                                        ))}
                                    </ul>
                                )}
                                <div className="flex items-center gap-2">
                                    <input ref={picker} type="file" multiple accept={limits?.accept} onChange={choose} className="sr-only" tabIndex={-1} aria-hidden="true" />
                                    <button type="button" onClick={() => picker.current?.click()} disabled={busy || !limits} className="grid size-11 shrink-0 place-items-center rounded-lg border border-line text-ink-muted hover:bg-canvas disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-primary" aria-label="Adjuntar capturas, videos o PDF" title="Adjuntar capturas, videos o PDF">
                                        <Paperclip className="size-5" aria-hidden="true" />
                                    </button>
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
                                        className="min-h-11 min-w-0 flex-1 resize-none rounded-lg border border-line bg-card px-3 py-2 text-sm text-ink focus-visible:outline-2 focus-visible:outline-primary"
                                    />
                                    <button
                                        type="submit"
                                        disabled={(!text.trim() && files.length === 0) || busy}
                                        className="grid size-11 shrink-0 place-items-center rounded-lg bg-primary text-white disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                        aria-label="Enviar mensaje"
                                    >
                                        <Send className="size-5" aria-hidden="true" />
                                    </button>
                                </div>
                                {limits && <p className="mt-1.5 text-[11px] text-ink-muted">PNG, JPG, WebP, MP4, WebM o PDF · hasta {limits.maxFiles} archivos · imágenes {limits.maxMb.image} MB, PDF {limits.maxMb.pdf} MB, videos {limits.maxMb.video} MB.</p>}
                            </form>
                            )}
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
