import { router, usePage } from '@inertiajs/react';
import { Bell, BellOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useToast } from '@/Components/Toast';
import { alreadyOpen, describeEvents, nextDelay } from '@/lib/conversationAlerts';
import jsonRequest from '@/lib/jsonRequest';
import { setPending } from '@/lib/pendingStore';

const SOUND_KEY = 'zonno:alerts-sound';

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

/** What the toast says: a title, the channel, the way in, and the sound switch. It never contains what a contact wrote. */
function Notice({ alert, soundBlocked, onSound }) {
    const [sound, setSound] = useState(readSound);

    const toggle = () => {
        const next = !sound;
        setSound(next);
        onSound(next);
    };

    return (
        <div>
            <p className="font-semibold">{alert.title}</p>
            {alert.detail && <p className="text-xs text-ink-muted">{alert.detail}</p>}
            <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                <button
                    type="button"
                    onClick={() => router.visit(alert.conversationId ? route('conversations.index', { c: alert.conversationId }) : route('conversations.index'))}
                    className="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                    {alert.conversationId ? 'Abrir conversación' : 'Ir a Conversaciones'}
                </button>
                <button type="button" onClick={toggle} aria-pressed={sound} className="inline-flex items-center gap-1 rounded px-1.5 py-1 text-xs text-ink-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-primary">
                    {sound ? <Bell className="size-3.5" aria-hidden="true" /> : <BellOff className="size-3.5" aria-hidden="true" />}
                    {sound ? 'Silenciar sonido' : 'Activar sonido'}
                </button>
            </div>
            {sound && soundBlocked && <p className="mt-1.5 text-[11px] text-ink-muted">El navegador aún no permite el sonido: haz clic en cualquier parte de la página para activarlo.</p>}
        </div>
    );
}

/**
 * The notification of the team: when a conversation or a message arrives in the Workspace, a toast appears at the top (under the top bar, like the «guardado» notices; several in a row
 * stack and each leaves by itself) and a soft sound plays once. It also keeps the number of conversations waiting for a person current (the sidebar badge).
 * The browser asks the server every few seconds «anything after message N?»: the first answer only sets where «now» is, so nothing from the past is announced, and each event is
 * announced once because the cursor only moves forward. The toast says THAT something arrived (and from which channel), never what it says. `conversationAlerts` is null for who cannot
 * read conversations, and then nothing runs. Without a connection it stays quiet and tries again more slowly; the sound is a convenience (the toast shows even if it is muted or blocked).
 * It is polling, not real time: the delay is at most one poll period.
 */
export default function ConversationAlerts() {
    const page = usePage();
    const config = page.props.conversationAlerts;
    const toast = useToast();
    const cursor = useRef(null);
    const handoffCursor = useRef(null);
    const failures = useRef(0);
    const audio = useRef(null);
    const poll = config?.poll;

    useEffect(() => {
        if (config) setPending(config.pending ?? 0);
    }, [config?.pending]); // eslint-disable-line react-hooks/exhaustive-deps

    // Browsers only allow audio after the person has interacted with the page: the first click or key press prepares it.
    useEffect(() => {
        if (!poll) return undefined;

        const unlock = () => {
            const Context = window.AudioContext ?? window.webkitAudioContext;

            if (Context && !audio.current) audio.current = new Context();
            audio.current?.resume?.().catch(() => {});
        };

        window.addEventListener('pointerdown', unlock, { once: true });
        window.addEventListener('keydown', unlock, { once: true });

        return () => {
            window.removeEventListener('pointerdown', unlock);
            window.removeEventListener('keydown', unlock);
        };
    }, [poll]);

    const show = useCallback(async (alert) => {
        const wantsSound = readSound();
        const blocked = wantsSound && !(await pop(audio.current));
        toast.notice(
            <Notice
                alert={alert}
                soundBlocked={blocked}
                onSound={(on) => {
                    try {
                        window.localStorage.setItem(SOUND_KEY, on ? 'on' : 'off');
                    } catch {
                        // the choice just is not remembered
                    }

                    if (on) pop(audio.current);
                }}
            />,
        );
    }, [toast]);

    useEffect(() => {
        if (!poll) return undefined;

        let stopped = false;
        let timer;

        const tick = async () => {
            try {
                const query = cursor.current === null ? '' : `?after=${cursor.current}&n=${handoffCursor.current ?? 0}`;
                const body = await jsonRequest(route('conversations.alerts', undefined, false) + query);
                const first = cursor.current === null;
                cursor.current = body.cursor;
                handoffCursor.current = body.handoffCursor ?? 0;
                failures.current = 0;
                setPending(body.pending ?? 0);

                // A conversation that moved to a person (shown once per real transition) wins over the message feed.
                const next = first ? null : describeEvents([...(body.handoffs ?? []), ...(body.events ?? [])]);

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
        };
    }, [poll, show]);

    return null;
}
