import { router } from '@inertiajs/react';
import { useState } from 'react';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';

/** Cancels an appointment with an optional reason that stays on it (and in the audit trail of the change of status). */
export default function CancelDialog({ appointment, onClose }) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = (event) => {
        event.preventDefault();
        router.post(route('bookings.cancel', appointment.id), { reason: reason || undefined }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="md">
            <form onSubmit={submit} className="p-6">
                <h2 className="text-lg font-bold text-ink">Cancelar cita</h2>
                <p className="mt-2 text-sm text-ink-muted">
                    Se cancelará la cita de {appointment.customer?.name ?? 'esta persona'} ({appointment.service}, {appointment.dateLabel} {appointment.timeLabel}). El horario quedará libre.
                </p>
                <div className="mt-4">
                    <InputLabel htmlFor="cancel_reason" value="Motivo (opcional)" />
                    <Textarea id="cancel_reason" rows={2} className="mt-1" value={reason} maxLength={255} onChange={(e) => setReason(e.target.value)} />
                </div>
                <div className="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" onClick={onClose}>
                        Volver
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={busy}>
                        Cancelar cita
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
