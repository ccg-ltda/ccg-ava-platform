import { router, useForm } from '@inertiajs/react';
import Badge from '@/Components/Badge';
import { useConfirm } from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';

const FIELD = 'mt-1';

const INVITATION_STATUS = { pending: ['Pendiente', 'amber'], accepted: ['Aceptada', 'green'], expired: ['Caducada', 'neutral'], revoked: ['Cancelada', 'red'] };

/**
 * Invites a person by e-mail to a Workspace. The target is fixed by the routes the caller passes (the platform administration picks a Workspace; a Workspace administrator
 * only has their own) and the server checks it again. The person receives a one-time link; the list shows the state of each invitation.
 */
export default function InvitePeople({ roles, invitations, routes, className = 'mt-6 border-t border-line pt-6' }) {
    const confirm = useConfirm();
    const invite = useForm({ email: '', name: '', role: '' });

    const submit = (e) => {
        e.preventDefault();
        invite.post(routes.store(), { preserveScroll: true, onSuccess: () => invite.reset() });
    };

    const resend = (invitation) => router.post(routes.resend(invitation), {}, { preserveScroll: true });

    const cancel = async (invitation) => {
        const confirmed = await confirm({ description: `El enlace enviado a ${invitation.email} dejará de funcionar.`, confirmLabel: 'Cancelar invitación' });

        if (confirmed) router.delete(routes.destroy(invitation), { preserveScroll: true });
    };

    return (
        <section className={`space-y-4 ${className}`} aria-label="Invitaciones">
            <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[1fr_1fr_10rem_auto] sm:items-end">
                <div>
                    <InputLabel htmlFor="invite_email" value="Invitar por correo" />
                    <TextInput id="invite_email" type="email" className={FIELD} value={invite.data.email} placeholder="persona@empresa.com" autoComplete="off" invalid={Boolean(invite.errors.email)} onChange={(e) => invite.setData('email', e.target.value)} />
                </div>
                <div>
                    <InputLabel htmlFor="invite_name" value="Nombre (opcional)" />
                    <TextInput id="invite_name" className={FIELD} value={invite.data.name} autoComplete="off" onChange={(e) => invite.setData('name', e.target.value)} />
                </div>
                <div>
                    <InputLabel htmlFor="invite_role" value="Rol" />
                    <Select id="invite_role" className={FIELD} value={invite.data.role} onChange={(role) => invite.setData('role', role)} options={roles.map((role) => ({ value: role, label: role }))} invalid={Boolean(invite.errors.role)} />
                </div>
                <PrimaryButton type="submit" disabled={invite.processing} className="justify-center">
                    Invitar
                </PrimaryButton>
                <div className="sm:col-span-4">
                    <InputError message={invite.errors.email || invite.errors.role} />
                    <p className="mt-1 text-xs text-ink-muted">La persona elige su propia contraseña desde el enlace, que caduca y sirve una sola vez. No se crea ninguna cuenta hasta que acepte.</p>
                </div>
            </form>

            {invitations.length > 0 && (
                <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Estado de las invitaciones">
                    {invitations.map((invitation) => {
                        const [label, tone] = INVITATION_STATUS[invitation.status] ?? [invitation.status, 'neutral'];

                        return (
                            <li key={invitation.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                                <div className="min-w-0">
                                    <span className="font-medium text-ink">{invitation.email}</span> <span className="text-xs text-ink-muted">· {invitation.role}</span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <Badge tone={tone}>{label}</Badge>
                                    {(invitation.status === 'pending' || invitation.status === 'expired') && (
                                        <>
                                            <button type="button" onClick={() => resend(invitation)} className="rounded-lg px-2 py-1 text-sm font-semibold text-accent-blue hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                                                Reenviar
                                            </button>
                                            <button type="button" onClick={() => cancel(invitation)} className="rounded-lg px-2 py-1 text-sm font-semibold text-danger hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-primary">
                                                Cancelar
                                            </button>
                                        </>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
