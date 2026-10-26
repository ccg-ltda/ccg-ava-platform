import { Head, useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';

/** Acceptance of an invitation: the person chooses their name and password; nothing else about the invitation can be changed from here. */
export default function AcceptInvitation({ valid, token, email, name, workspace, message }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: name ?? '', password: '', password_confirmation: '' });

    const submit = (event) => {
        event.preventDefault();
        post(route('invitations.store', token), { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout title="Invitación">
            <Head title="Invitación" />

            {!valid ? (
                <Alert tone="danger">{message}</Alert>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <p className="text-sm text-ink-muted">
                        Te invitaron a unirte a <strong className="text-ink">{workspace}</strong>. Elige tu nombre y una contraseña para crear tu cuenta con <strong className="text-ink">{email}</strong>.
                    </p>
                    {errors.token && <Alert tone="danger">{errors.token}</Alert>}

                    <div>
                        <InputLabel htmlFor="name" value="Nombre" />
                        <TextInput id="name" name="name" value={data.name} className="mt-1" autoComplete="name" isFocused invalid={Boolean(errors.name)} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="password" value="Contraseña" />
                        <TextInput id="password" type="password" name="password" value={data.password} className="mt-1" autoComplete="new-password" invalid={Boolean(errors.password)} onChange={(e) => setData('password', e.target.value)} />
                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <div>
                        <InputLabel htmlFor="password_confirmation" value="Confirmar contraseña" />
                        <TextInput type="password" id="password_confirmation" name="password_confirmation" value={data.password_confirmation} className="mt-1" autoComplete="new-password" invalid={Boolean(errors.password_confirmation)} onChange={(e) => setData('password_confirmation', e.target.value)} />
                        <InputError message={errors.password_confirmation} className="mt-2" />
                    </div>

                    <div className="flex items-center justify-end">
                        <PrimaryButton disabled={processing}>Crear mi cuenta</PrimaryButton>
                    </div>
                </form>
            )}
        </GuestLayout>
    );
}
