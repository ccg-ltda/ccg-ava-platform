import { router, usePage } from '@inertiajs/react';
import { Bell, BellOff, MessageSquareText, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { alreadyOpen, describeEvents, nextDelay } from '@/lib/conversationAlerts';
import jsonRequest from '@/lib/jsonRequest';

const SOUND_KEY = 'zonno:alerts-sound';
const SHOW_MS = 12000;

const readSound = () => {
    try {
        return window.localStorage.getItem(SOUND_KEY) !== 'off';
    } catch {
        return true;
    }
};

/** A short, soft two-note «pop» generated with the Web Audio API (no file, no dependency, and nothing like the WhatsApp sound). Resolves false when the browser does not allow it yet. */
async function pop(context) {
    if (!context) return false;

    if (context.state === 'suspended') {
        try {
            await context.resume();
        } catch {
            return false;
        }
    }

    if (context.state !== 'running') return false;

    const now = context.currentTime;
    const gain = context.createGain();
    gain.connect(context.destination);
    gain.gain.setValueAtTime(0.0001, now);
    gain.gain.exponentialRampToValueAtTime(0.18, now + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.32);

    [[523.25, 0], [783.99, 0.09]].forEach(([frequency, offset]) => {
        const oscillator = context.createOscillator();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(frequency, now + offset);
        oscillator.connect(gain);
        oscillator.start(now + offset);
        oscillator.stop(now + offset + 0.2);
    });

    return true;
}

/**
 * The notification of the team: when a conversation or a message arrives in the Workspace, a small card appears (bottom, never over a dialog) and a soft sound plays once.
 * The browser asks the server every few seconds «anything after message N?»: the first answer only sets where «now» is, so nothing from the past is announced, and each event is
 * announced once because the cursor only moves forward. The card says THAT something arrived (and from which channel), never what it says. `conversationAlerts` is null for who cannot
 * read conversations, and then nothing runs. Without a connection it stays quiet and tries again more slowly; the sound is a convenience (the card shows even if it is muted or blocked).
 */
export default function ConversationAlerts() {
    const page = usePage();
    const config = page.props.conversationAlerts;
    const [alert, setAlert] = useState(null);
    const [sound, setSound] = useState(readSound);
    const [blocked, setBlocked] = useState(false);
    const cursor = useRef(null);
    const failures = useRef(0);
    const audio = useRef(null);
    const soundOn = useRef(sound);
    const dismiss = useRef(null);
    const poll = config?.poll;

    soundOn.current = sound;

    // Browsers only allow audio after the person has interacted with the page: the first click or key press prepares it.
    useEffect(() => {
        if (!poll) return undefined;

        const unlock = () => {
            const Context = window.AudioContext ?? window.webkitAudioContext;

            if (Context && !audio.current) audio.current = new Context();
            audio.current?.resume?.().catch(() => {});
            setBlocked(false);
        };

        window.addEventListener('pointerdown', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });

        return () => {
            window.removeEventListener('pointerdown', unlock);
            window.removeEventListener('keydown', unlock);
        };
    }, [poll]);

    const show = useCallback(async (next) => {
        setAlert({ ...next, key: Date.now() });
        clearTimeout(dismiss.current);
        dismiss.current = setTimeout(() => setAlert(null), SHOW_MS);

        if (soundOn.current) setBlocked(!(await pop(audio.current)));
    }, []);

    useEffect(() => {
        if (!poll) return undefined;

        let stopped = false;
        let timer;

        const tick = async () => {
            try {
                const query = cursor.current === null ? '' : `?after=${cursor.current}`;
                const body = await jsonRequest(route('conversations.alerts', undefined, false) + query);
                const first = cursor.current === null;
                cursor.current = body.cursor;
                failures.current = 0;

                const next = first ? null : describeEvents(body.events);

                if (!stopped && next && !alreadyOpen(next, window.location.search, document.visibilityState === 'visible')) show(next);
            } catch {
                failures.current += 1; // silent: a broken connection must not produce alerts of its own
            }

            if (!stopped) timer = setTimeout(tick, nextDelay(poll, failures.current, document.visibilityState === 'hidden'));
        };

        tick();

        return () => {
            stopped = true;
            clearTimeout(timer);
            clearTimeout(dismiss.current);
        };
    }, [poll, show]);

    if (!poll || !alert) return null;

    const toggleSound = () => {
        const next = !sound;
        setSound(next);

        try {
            window.localStorage.setItem(SOUND_KEY, next ? 'on' : 'off');
        } catch {
            // the choice just is not remembered
        }

        if (next) pop(audio.current);
    };

    const open = () => {
        router.visit(alert.conversationId ? route('conversations.index', { c: alert.conversationId }) : route('conversations.index'));
        setAlert(null);
    };

    return (
        <div className="pointer-events-none fixed inset-x-4 bottom-24 z-[45] mx-auto flex max-w-sm justify-center sm:bottom-6" role="region" aria-label="Aviso de conversaciones">
            <div key={alert.key} role="status" aria-live="polite" className="pointer-events-auto w-full rounded-xl border border-line border-l-4 border-l-primary bg-card p-3 text-sm text-ink shadow-card-hover motion-safe:animate-fade-in">
                <div className="flex items-start gap-3">
                    <MessageSquareText className="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                        <p className="font-semibold">{alert.title}</p>
                        {alert.detail && <p className="text-xs text-ink-muted">{alert.detail}</p>}
                    </div>
                    <button type="button" onClick={() => setAlert(null)} aria-label="Cerrar aviso" className="-m-1 grid size-7 shrink-0 place-items-center rounded text-ink-muted hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                        <X className="size-4" aria-hidden="true" />
                    </button>
                </div>
                <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <button type="button" onClick={open} className="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                        {alert.conversationId ? 'Abrir conversación' : 'Ir a Conversaciones'}
                    </button>
                    <button type="button" onClick={toggleSound} className="inline-flex items-center gap-1 rounded px-1.5 py-1 text-xs text-ink-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-primary" aria-pressed={sound}>
                        {sound ? <Bell className="size-3.5" aria-hidden="true" /> : <BellOff className="size-3.5" aria-hidden="true" />}
                        {sound ? 'Silenciar sonido' : 'Activar sonido'}
                    </button>
                </div>
                {sound && blocked && <p className="mt-1.5 text-[11px] text-ink-muted">El navegador aún no permite el sonido: haz clic en cualquier parte de la página para activarlo.</p>}
            </div>
        </div>
    );
}
