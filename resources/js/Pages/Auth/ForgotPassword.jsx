import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import Alert from '@/Components/Alert';
import { Head, Link, useForm } from '@inertiajs/react';

export default function ForgotPassword({ status, mailUsable, expireMinutes }) {
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
                Indica tu correo y, si corresponde a una cuenta activa, te enviaremos un enlace para elegir una contraseña nueva. El enlace caduca en {expireMinutes} minutos.
            </p>

            {!mailUsable && (
                <Alert tone="warning" className="mb-4">
                    El envío de correos no está configurado en este entorno, así que no podemos enviar el enlace. Pide al administrador de la plataforma que restablezca tu contraseña.
                </Alert>
            )}

            {status && (
                <Alert tone="success" className="mb-4">
                    {status}
                </Alert>
            )}

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
                    <PrimaryButton disabled={processing || !mailUsable}>Enviar enlace</PrimaryButton>
                </div>
            </form>

            <p className="mt-4 text-center text-sm">
                <Link href={route('pre-login')} className="text-primary hover:underline">
                    Volver al acceso
                </Link>
            </p>
        </GuestLayout>
    );
}
