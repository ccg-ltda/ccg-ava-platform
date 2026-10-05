import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function ForgotPassword({ status }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout title="Recuperar contraseña">
            <Head title="Recuperar contraseña" />

            <p className="mb-4 text-sm text-ink-muted">
                Indica tu correo y te enviaremos un enlace para elegir una contraseña nueva.
            </p>

            {status && <p className="mb-4 text-sm font-medium text-accent-green">{status}</p>}

            <form onSubmit={submit}>
                <InputLabel htmlFor="email" value="Correo electrónico" />
                <TextInput
                    id="email"
                    type="email"
                    name="email"
                    value={data.email}
                    className="mt-1"
                    isFocused={true}
                    autoComplete="username"
                    invalid={Boolean(errors.email)}
                    onChange={(e) => setData('email', e.target.value)}
                />

                <InputError message={errors.email} className="mt-2" />

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton disabled={processing}>Enviar enlace</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
