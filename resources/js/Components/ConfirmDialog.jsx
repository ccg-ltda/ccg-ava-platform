import { TriangleAlert } from 'lucide-react';
import { createContext, useCallback, useContext, useRef, useState } from 'react';
import DangerButton from './DangerButton';
import Modal from './Modal';
import PrimaryButton from './PrimaryButton';

/**
 * The only confirmation dialog of Ava (never window.confirm). Presentational: Cancel is the first control
 * (it receives the focus), Escape and the overlay also cancel. Most code should use `useConfirm` instead.
 */
export function ConfirmDialog({ show, title = '¿Estás seguro?', description, confirmLabel = 'Confirmar', cancelLabel = 'Cancelar', onConfirm, onCancel }) {
    return (
        <Modal show={show} onClose={onCancel} maxWidth="sm">
            <div role="alertdialog" aria-label={title} className="p-6">
                <div className="flex items-start gap-3">
                    <span className="grid size-10 shrink-0 place-items-center rounded-full bg-accent-amber/10 text-accent-amber" aria-hidden="true">
                        <TriangleAlert className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="text-lg font-bold text-ink">{title}</h2>
                        <p className="mt-1 text-sm text-ink-muted">{description}</p>
                    </div>
                </div>

                <div className="mt-6 flex justify-end gap-3">
                    <DangerButton type="button" onClick={onCancel}>
                        {cancelLabel}
                    </DangerButton>
                    <PrimaryButton type="button" onClick={onConfirm}>
                        {confirmLabel}
                    </PrimaryButton>
                </div>
            </div>
        </Modal>
    );
}

const ConfirmContext = createContext(null);

/**
 * Mount once (AppLayout). `const confirm = useConfirm(); if (await confirm({ description, confirmLabel })) { ... }`
 * resolves true only when the user confirms; the action must not run before that.
 */
export function ConfirmProvider({ children }) {
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState({});
    const resolver = useRef(null);

    const confirm = useCallback(
        (next) =>
            new Promise((resolve) => {
                resolver.current = resolve;
                setOptions(next);
                setOpen(true);
            }),
        [],
    );

    // `options` is kept while the dialog fades out, so its text does not vanish mid-transition.
    const settle = (result) => {
        resolver.current?.(result);
        resolver.current = null;
        setOpen(false);
    };

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <ConfirmDialog show={open} {...options} onConfirm={() => settle(true)} onCancel={() => settle(false)} />
        </ConfirmContext.Provider>
    );
}

export function useConfirm() {
    return useContext(ConfirmContext);
}
