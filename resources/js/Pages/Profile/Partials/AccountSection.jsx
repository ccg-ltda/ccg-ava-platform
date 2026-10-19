import { Link, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useEffect } from 'react';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

function Fact({ label, children }) {
    return (
        <div className="min-w-0 rounded-lg border border-line bg-canvas/50 px-4 py-3">
            <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{label}</dt>
            <dd className="mt-1 text-sm font-medium break-words text-ink">{children}</dd>
        </div>
    );
}

/** The account data: a summary and, on «Editar», the form (name and email). Saving or cancelling returns to the summary. */
export default function AccountSection({ account, mustVerifyEmail, status, editing, onEdit, onClose }) {
    const { data, setData, patch, errors, processing, reset, clearErrors } = useForm({ name: account.name, email: account.email });

    const submit = (e) => {
        e.preventDefault();
        patch(route('profile.update'), { preserveScroll: true, onSuccess: onClose });
    };

    const cancel = () => {
        reset();
        clearErrors();
        onClose();
    };

    // The form always opens with what is saved now (the hero's «Editar perfil» opens it too).
    useEffect(() => {
        if (editing) {
            setData({ name: account.name, email: account.email });
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing]);

    return (
        <Card className="overflow-hidden">
            <div className="flex items-start justify-between gap-3 p-4 sm:p-6">
                <div>
                    <h2 className="text-lg font-bold text-ink">Información de la cuenta</h2>
                    <p className="mt-1 text-sm text-ink-muted">Tu nombre y el correo con el que entras.</p>
                </div>
                {!editing && (
                    <SecondaryButton type="button" onClick={onEdit} className="gap-2">
                        <Pencil className="size-4" aria-hidden="true" />
                        Editar
                    </SecondaryButton>
                )}
            </div>

            <div className="border-t border-line p-4 sm:p-6">
                {editing ? (
                    <form onSubmit={submit} className="space-y-5" aria-label="Editar la información de la cuenta">
                        <div>
                            <InputLabel htmlFor="profile_name" value="Nombre" />
                            <TextInput id="profile_name" className="mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} required isFocused autoComplete="name" />
                            <InputError className="mt-2" message={errors.name} />
                        </div>
                        <div>
                            <InputLabel htmlFor="profile_email" value="Correo electrónico" />
                            <TextInput id="profile_email" type="email" className="mt-1" value={data.email} onChange={(e) => setData('email', e.target.value)} invalid={Boolean(errors.email)} required autoComplete="username" />
                            <InputError className="mt-2" message={errors.email} />
                            <p className="mt-1 text-xs text-ink-muted">Si cambias el correo, tendrás que verificarlo de nuevo.</p>
                        </div>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={cancel} disabled={processing}>
                                Cancelar
                            </SecondaryButton>
                            <PrimaryButton type="submit" disabled={processing}>
                                {processing ? 'Guardando...' : 'Guardar cambios'}
                            </PrimaryButton>
                        </div>
                    </form>
                ) : (
                    <>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Fact label="Nombre">{account.name}</Fact>
                            <Fact label="Correo electrónico">{account.email}</Fact>
                            <Fact label="Miembro desde">{account.memberSince}</Fact>
                            <Fact label="Workspace actual">{account.workspace}</Fact>
                        </dl>
                        {mustVerifyEmail && !account.verified && (
                            <p className="mt-4 text-sm text-ink">
                                Tu correo no está verificado.{' '}
                                <Link href={route('verification.send')} method="post" as="button" className="rounded-md text-sm text-primary underline hover:text-primary-strong focus-visible:outline-2 focus-visible:outline-primary">
                                    Reenviar el correo de verificación.
                                </Link>
                                {status === 'verification-link-sent' && <span className="mt-2 block font-medium text-accent-green">Te enviamos un nuevo enlace de verificación.</span>}
                            </p>
                        )}
                    </>
                )}
            </div>
        </Card>
    );
}
