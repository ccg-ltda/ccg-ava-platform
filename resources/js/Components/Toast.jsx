import { Transition } from '@headlessui/react';
import { usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, X, XCircle } from 'lucide-react';
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

const TONES = {
    success: { icon: CheckCircle2, stripe: 'border-l-accent-green', color: 'text-accent-green', role: 'status', ms: 4500 },
    warning: { icon: AlertTriangle, stripe: 'border-l-accent-amber', color: 'text-accent-amber', role: 'status', ms: 6000 },
    error: { icon: XCircle, stripe: 'border-l-danger', color: 'text-danger', role: 'alert', ms: 7000 },
};

function ToastItem({ toast, onDone }) {
    const { icon: Icon, stripe, color, role, ms } = TONES[toast.tone];
    const [show, setShow] = useState(true);

    useEffect(() => {
        const timer = setTimeout(() => setShow(false), ms);

        return () => clearTimeout(timer);
    }, [ms]);

    return (
        <Transition
            appear
            show={show}
            afterLeave={() => onDone(toast.id)}
            enter="transition duration-200 ease-out"
            enterFrom="translate-x-4 opacity-0"
            enterTo="translate-x-0 opacity-100"
            leave="transition duration-150 ease-in"
            leaveFrom="opacity-100"
            leaveTo="opacity-0"
        >
            <div role={role} className={`pointer-events-auto flex items-start gap-3 rounded-lg border border-line border-l-4 bg-card p-3 text-sm text-ink shadow-card-hover ${stripe}`}>
                <Icon className={`mt-0.5 size-4 shrink-0 ${color}`} aria-hidden="true" />
                <p className="min-w-0 flex-1">{toast.message}</p>
                <button
                    type="button"
                    onClick={() => setShow(false)}
                    aria-label="Cerrar notificación"
                    className="-m-1 grid size-6 shrink-0 place-items-center rounded text-ink-muted transition-colors duration-150 hover:bg-canvas hover:text-ink focus-visible:outline-2 focus-visible:outline-primary"
                >
                    <X className="size-4" aria-hidden="true" />
                </button>
            </div>
        </Transition>
    );
}

const ToastContext = createContext(null);

/**
 * Global notifications (top right, under the top bar, never blocking the page). Mount once (AppLayout).
 * `const toast = useToast(); toast.success('...')`; also `toast.warning` and `toast.error`.
 * Messages flashed by the server (`flash.success|warning|error`) arrive through <FlashToasts>.
 */
export function ToastProvider({ children }) {
    const [toasts, setToasts] = useState([]);
    const nextId = useRef(0);

    const push = useCallback((tone, message) => setToasts((current) => [...current, { id: ++nextId.current, tone, message }]), []);
    const remove = useCallback((id) => setToasts((current) => current.filter((toast) => toast.id !== id)), []);

    const api = useMemo(
        () => ({ success: (message) => push('success', message), warning: (message) => push('warning', message), error: (message) => push('error', message) }),
        [push],
    );

    return (
        <ToastContext.Provider value={api}>
            {children}
            <div role="region" aria-label="Notificaciones" className="pointer-events-none fixed top-[4.75rem] right-4 z-[60] flex w-[calc(100vw-2rem)] max-w-sm flex-col gap-2 sm:right-6">
                {toasts.map((toast) => (
                    <ToastItem key={toast.id} toast={toast} onDone={remove} />
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast() {
    return useContext(ToastContext);
}

/** Turns the server's flash messages into toasts after every visit (replaces the old inline FlashAlert). */
export function FlashToasts() {
    const flash = usePage().props.flash;
    const toast = useToast();
    const lastShown = useRef(null);

    useEffect(() => {
        if (!flash || lastShown.current === flash) return;

        lastShown.current = flash;
        ['success', 'warning', 'error'].forEach((tone) => flash[tone] && toast[tone](flash[tone]));
    }, [flash, toast]);

    return null;
}
