import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';

export default function VerifyEmail({ status }) {
    const { post, processing } = useForm({});

    const submit = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout title="Verificar correo">
            <Head title="Verificar correo" />

            <p className="mb-4 text-sm text-ink-muted">
                Antes de continuar, verifica tu correo con el enlace que acabamos de enviarte. Si no lo recibiste, podemos enviarte otro.
            </p>

            {status === 'verification-link-sent' && (
                <p className="mb-4 text-sm font-medium text-accent-green">Te enviamos un nuevo enlace de verificación.</p>
            )}

            <form onSubmit={submit}>
                <div className="mt-4 flex items-center justify-between gap-4">
                    <PrimaryButton disabled={processing}>Reenviar correo</PrimaryButton>

                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="rounded-md text-sm text-ink-muted underline transition-colors duration-150 hover:text-ink focus-visible:outline-2 focus-visible:outline-primary"
                    >
                        Cerrar sesión
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
