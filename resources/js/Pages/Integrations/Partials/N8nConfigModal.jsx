import { router, useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

/**
 * Connects the n8n instance of the active Workspace: its address and an API Key. The API Key is write-only: a stored
 * one is never sent here (only "is set"), and leaving it blank while editing keeps it. "Guardar y probar" saves and
 * then runs the real connection test, because saved data alone is never a connection.
 */
export default function N8nConfigModal({ connection, onClose }) {
    const editing = connection.state !== 'not_configured';
    const form = useForm({ base_url: connection.form?.base_url ?? '', api_key: '' });
    const { data, setData, errors } = form;
    const keyStored = Boolean(connection.form?.api_key_set);

    const save = (andTest) => (event) => {
        event.preventDefault();

        form.put(route('integrations.n8n.update'), {
            preserveScroll: true,
            onSuccess: (page) => {
                onClose();

                // The test needs the saved connection, which the refreshed page now carries.
                if (andTest) router.post(route('integrations.test', page.props.automation.connection.id), {}, { preserveScroll: true });
            },
        });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={save(false)} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Cambiar la configuración de n8n' : 'Configurar n8n'}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Con estos dos datos Ava podrá comunicarse con tu instancia de n8n. Son propios de este Workspace.</p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    <div>
                        <InputLabel htmlFor="n8n_base_url" value="URL de tu n8n" />
                        <TextInput id="n8n_base_url" className="mt-1" value={data.base_url} onChange={(e) => setData('base_url', e.target.value)} placeholder="https://tu-instancia.app.n8n.cloud" invalid={Boolean(errors.base_url)} maxLength={2048} autoComplete="off" spellCheck={false} />
                        <InputError message={errors.base_url} className="mt-1" />
                        <p className="mt-1.5 text-xs text-ink-muted">Es la dirección que aparece en la barra de direcciones cuando estás dentro de n8n. Ejemplo: https://miempresa.app.n8n.cloud</p>
                    </div>

                    <div>
                        <InputLabel htmlFor="n8n_api_key" value="API Key de n8n" />
                        <TextInput
                            id="n8n_api_key"
                            type="password"
                            className="mt-1"
                            value={data.api_key}
                            onChange={(e) => setData('api_key', e.target.value)}
                            placeholder={keyStored ? '•••••••• (guardada)' : 'Pega aquí la API Key'}
                            invalid={Boolean(errors.api_key)}
                            maxLength={4096}
                            autoComplete="new-password"
                        />
                        <InputError message={errors.api_key} className="mt-1" />
                        <p className="mt-1.5 text-xs text-ink-muted">En n8n ve a Settings → n8n API y crea una API Key. Copia la clave y pégala aquí.</p>
                        {keyStored && <p className="mt-1 text-xs text-ink-muted">Escribe una clave nueva solo para reemplazarla. Nunca se muestra la guardada.</p>}
                    </div>

                    <Alert tone="info">
                        Esta API Key es la que Ava usa para entrar a tu n8n. No es el token que usa tu workflow para hablar con Ava: ese se genera para cada chatbot más abajo, en «Configura tu workflow».
                    </Alert>
                </div>

                <div className="flex flex-col-reverse gap-3 border-t border-line bg-card px-5 py-4 sm:flex-row sm:justify-end sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <SecondaryButton type="button" onClick={save(true)} disabled={form.processing}>
                        Guardar y probar conexión
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {form.processing ? 'Guardando...' : 'Guardar'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
