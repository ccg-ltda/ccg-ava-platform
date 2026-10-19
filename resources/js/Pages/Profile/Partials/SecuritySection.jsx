import { useForm } from '@inertiajs/react';
import { KeyRound, ShieldCheck } from 'lucide-react';
import { useRef } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

/**
 * Security: the password is shown only as «configurada» (the system keeps a one-way hash and can never show it), and changing it asks for the current
 * one, a new one and its confirmation. Changing it closes the other sessions of the account.
 */
export default function SecuritySection({ account, editing, onEdit, onClose }) {
    const currentInput = useRef();
    const newInput = useRef();
    const { data, setData, errors, put, reset, clearErrors, processing } = useForm({ current_password: '', password: '', password_confirmation: '' });

    const submit = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
            onError: (problems) => {
                if (problems.password) {
                    reset('password', 'password_confirmation');
                    newInput.current?.focus();
                }

                if (problems.current_password) {
                    reset('current_password');
                    currentInput.current?.focus();
                }
            },
        });
    };

    const cancel = () => {
        reset();
        clearErrors();
        onClose();
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex items-start justify-between gap-3 p-4 sm:p-6">
                <div>
                    <h2 className="text-lg font-bold text-ink">Seguridad</h2>
                    <p className="mt-1 text-sm text-ink-muted">Tu contraseña y el acceso a tu cuenta.</p>
                </div>
                {!editing && (
                    <SecondaryButton type="button" onClick={onEdit} className="gap-2">
                        <KeyRound className="size-4" aria-hidden="true" />
                        Cambiar contraseña
                    </SecondaryButton>
                )}
            </div>

            <div className="border-t border-line p-4 sm:p-6">
                {editing ? (
                    <form onSubmit={submit} className="space-y-5" aria-label="Cambiar la contraseña">
                        <div>
                            <InputLabel htmlFor="current_password" value="Contraseña actual" />
                            <TextInput id="current_password" ref={currentInput} type="password" className="mt-1" value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} autoComplete="current-password" invalid={Boolean(errors.current_password)} isFocused />
                            <InputError message={errors.current_password} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="password" value="Contraseña nueva" />
                            <TextInput id="password" ref={newInput} type="password" className="mt-1" value={data.password} onChange={(e) => setData('password', e.target.value)} autoComplete="new-password" invalid={Boolean(errors.password)} />
                            <InputError message={errors.password} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="password_confirmation" value="Confirmar contraseña" />
                            <TextInput id="password_confirmation" type="password" className="mt-1" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} autoComplete="new-password" invalid={Boolean(errors.password_confirmation)} />
                            <InputError message={errors.password_confirmation} className="mt-2" />
                        </div>
                        <p className="text-xs text-ink-muted">Usa una contraseña larga y distinta de la actual. Al cambiarla se cierran tus otras sesiones abiertas.</p>
                        <div className="flex justify-end gap-3">
                            <SecondaryButton type="button" onClick={cancel} disabled={processing}>
                                Cancelar
                            </SecondaryButton>
                            <PrimaryButton type="submit" disabled={processing}>
                                {processing ? 'Guardando...' : 'Cambiar contraseña'}
                            </PrimaryButton>
                        </div>
                    </form>
                ) : (
                    <dl className="space-y-3 text-sm">
                        <div className="flex items-center justify-between gap-3 rounded-lg border border-line bg-canvas/50 px-4 py-3">
                            <dt className="text-ink-muted">Contraseña</dt>
                            <dd className="flex items-center gap-2 font-medium text-ink">
                                <span aria-hidden="true">••••••••••</span>
                                <Badge tone={account.passwordSet ? 'green' : 'amber'}>{account.passwordSet ? 'Configurada' : 'Sin configurar'}</Badge>
                            </dd>
                        </div>
                        <div className="flex items-start gap-3 rounded-lg border border-line bg-canvas/50 px-4 py-3">
                            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                            <p className="text-ink-muted">Nadie puede ver tu contraseña, ni siquiera el equipo de la plataforma: solo se guarda una huella irreversible. Si la olvidas, usa «¿Olvidó su contraseña?» al iniciar sesión.</p>
                        </div>
                    </dl>
                )}
            </div>
        </Card>
    );
}
