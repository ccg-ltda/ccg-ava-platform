import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Link, useForm, usePage } from '@inertiajs/react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing } = useForm({
        name: user.name,
        email: user.email,
    });

    const submit = (e) => {
        e.preventDefault();

        patch(route('profile.update'), { preserveScroll: true });
    };

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-bold text-ink">Información del perfil</h2>

                <p className="mt-1 text-sm text-ink-muted">Actualiza tu nombre y tu correo electrónico.</p>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div>
                    <InputLabel htmlFor="name" value="Nombre" />

                    <TextInput
                        id="name"
                        className="mt-1"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        invalid={Boolean(errors.name)}
                        required
                        isFocused
                        autoComplete="name"
                    />

                    <InputError className="mt-2" message={errors.name} />
                </div>

                <div>
                    <InputLabel htmlFor="email" value="Correo electrónico" />

                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        invalid={Boolean(errors.email)}
                        required
                        autoComplete="username"
                    />

                    <InputError className="mt-2" message={errors.email} />
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className="mt-2 text-sm text-ink">
                            Tu correo no está verificado.{' '}
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="rounded-md text-sm text-primary underline hover:text-primary-strong focus-visible:outline-2 focus-visible:outline-primary"
                            >
                                Reenviar el correo de verificación.
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <p className="mt-2 text-sm font-medium text-accent-green">Te enviamos un nuevo enlace de verificación.</p>
                        )}
                    </div>
                )}

                <PrimaryButton disabled={processing}>Guardar</PrimaryButton>
            </form>
        </section>
    );
}
