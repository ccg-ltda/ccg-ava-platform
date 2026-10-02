import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useAccess } from '@/Components/Access/AccessContext';
import { AccessInput } from '@/Components/Access/AccessInput';
import AccessLayout from '@/Layouts/AccessLayout';

const REQUIRED = 'Ingrese el código de su Workspace';

/** Step 1 of access: choose the Workspace. The server validates the code and keeps it in the session. */
export default function PreLogin() {
    const { showToast } = useAccess();
    const { data, setData, post, processing, errors, clearErrors } = useForm({ workspace_code: '' });
    const [clientError, setClientError] = useState('');

    const submit = (e) => {
        e.preventDefault();

        if (data.workspace_code.trim() === '') {
            setClientError(REQUIRED);
            showToast('Por favor, corrija los errores del formulario', 'error');
            return;
        }

        showToast('Verificando Workspace...', 'success');
        post(route('pre-login'));
    };

    return (
        <>
            <Head title="Workspace" />
            <form onSubmit={submit} noValidate>
                <AccessInput
                    id="workspace_code"
                    label="Workspace code"
                    value={data.workspace_code}
                    onChange={(e) => {
                        setData('workspace_code', e.target.value);
                        setClientError('');
                        clearErrors('workspace_code');
                    }}
                    message={clientError || errors.workspace_code || ''}
                    placeholder="DESARROLLO_DEV"
                    autoComplete="off"
                    autoCapitalize="characters"
                    spellCheck={false}
                    maxLength={100}
                    autoFocus
                    connect
                />

                <button type="submit" className="login-btn" disabled={processing}>
                    Continuar
                </button>
            </form>
        </>
    );
}

PreLogin.layout = (page) => <AccessLayout>{page}</AccessLayout>;
