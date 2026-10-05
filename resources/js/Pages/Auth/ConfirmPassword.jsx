import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit = (e) => {
        e.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout title="Confirmar contraseña">
            <Head title="Confirmar contraseña" />

            <p className="mb-4 text-sm text-ink-muted">Esta es una zona segura. Confirma tu contraseña para continuar.</p>

            <form onSubmit={submit}>
                <InputLabel htmlFor="password" value="Contraseña" />
                <TextInput
                    id="password"
                    type="password"
                    name="password"
                    value={data.password}
                    className="mt-1"
                    isFocused={true}
                    autoComplete="current-password"
                    invalid={Boolean(errors.password)}
                    onChange={(e) => setData('password', e.target.value)}
                />

                <InputError message={errors.password} className="mt-2" />

                <div className="mt-4 flex items-center justify-end">
                    <PrimaryButton disabled={processing}>Confirmar</PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
