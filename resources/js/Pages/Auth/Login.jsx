import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useAccess } from '@/Components/Access/AccessContext';
import { AccessInput, AccessPasswordInput } from '@/Components/Access/AccessInput';
import WorkspaceBadge from '@/Components/Access/WorkspaceBadge';
import AccessLayout from '@/Layouts/AccessLayout';

const EMAIL_INVALID = 'Ingrese un correo electrónico válido';
const PASSWORD_REQUIRED = 'Ingrese su contraseña';
const isEmail = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);

/**
 * Step 2 of access. The Workspace comes from the server session (shown here only as a badge);
 * credentials and Workspace membership are checked by the server on submit.
 */
export default function Login({ workspace, rememberedEmail }) {
    const { showToast } = useAccess();
    const { data, setData, post, processing, errors, clearErrors } = useForm({
        email: rememberedEmail ?? '',
        password: '',
        remember: Boolean(rememberedEmail),
    });
    const [client, setClient] = useState({ email: '', password: '' });
    const [showPassword, setShowPassword] = useState(false);

    const emailTyped = data.email.trim() !== '';
    const emailMessage = client.email || errors.email || '';
    const passwordMessage = client.password || errors.password || '';

    const onEmail = (e) => {
        const value = e.target.value;
        setData('email', value);
        clearErrors('email');
        setClient((c) => ({ ...c, email: value.trim() !== '' && !isEmail(value.trim()) ? EMAIL_INVALID : '' }));
    };

    const onPassword = (e) => {
        setData('password', e.target.value);
        clearErrors('password');
        setClient((c) => ({ ...c, password: '' }));
    };

    const submit = (e) => {
        e.preventDefault();

        const next = {
            email: isEmail(data.email.trim()) ? '' : EMAIL_INVALID,
            password: data.password.length === 0 ? PASSWORD_REQUIRED : '',
        };
        setClient(next);

        if (next.email || next.password) {
            showToast('Por favor, corrija los errores del formulario', 'error');
            return;
        }

        showToast('Iniciando sesión...', 'success');
        post(route('login'), { onFinish: () => setData('password', '') });
    };

    return (
        <>
            <Head title="Login" />
            <WorkspaceBadge code={workspace.code} />

            <form onSubmit={submit} noValidate>
                <AccessInput
                    id="email"
                    label="Correo Electrónico"
                    type="email"
                    name="email"
                    value={data.email}
                    onChange={onEmail}
                    message={emailMessage}
                    valid={emailTyped && !emailMessage}
                    placeholder="usuario@ccg.platform"
                    autoComplete="email"
                    connect
                />

                <AccessPasswordInput
                    id="password"
                    label="Contraseña"
                    name="password"
                    value={data.password}
                    onChange={onPassword}
                    message={passwordMessage}
                    placeholder="••••••••"
                    autoComplete="current-password"
                    visible={showPassword}
                    onToggle={() => setShowPassword((v) => !v)}
                    connect
                />

                <div className="remember-row">
                    <label>
                        <input
                            type="checkbox"
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', e.target.checked)}
                        />
                        Recordar sesión
                    </label>
                    <a
                        href="#"
                        onClick={(e) => {
                            e.preventDefault();
                            showToast('Funcionalidad de recuperación en desarrollo', 'warning');
                        }}
                    >
                        ¿Olvidó su contraseña?
                    </a>
                </div>

                <button type="submit" className="login-btn" disabled={processing}>
                    Iniciar Sesión
                </button>
            </form>

            <p className="footer-text">
                ¿No tiene cuenta? <a href="#">Regístrese aquí</a>
            </p>
        </>
    );
}

Login.layout = (page) => <AccessLayout>{page}</AccessLayout>;
