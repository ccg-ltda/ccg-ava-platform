import { useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import KeyValueRows from './KeyValueRows';

const emptyForm = (catalog) => ({
    name: '',
    provider: '',
    description: '',
    type: catalog.types[0].value,
    is_active: true,
    base_url: '',
    endpoint: '',
    method: 'GET',
    timeout: catalog.defaultTimeout,
    auth_type: 'none',
    auth_name: '',
    auth_location: 'header',
    auth_username: '',
    auth_secret: '',
    auth_secret_set: false,
    headers: [],
    query: [],
    body: '',
});

function Section({ title, hint, children }) {
    return (
        <fieldset className="rounded-xl border border-line bg-canvas/40 p-5 sm:p-6">
            <legend className="px-2 text-sm font-bold tracking-wider text-accent-blue uppercase">{title}</legend>
            {hint && <p className="-mt-1 mb-4 text-sm text-ink-muted">{hint}</p>}
            <div className="space-y-5">{children}</div>
        </fieldset>
    );
}

/**
 * Create / edit an integration of the generic HTTP/REST type. Credentials are write-only: a stored secret is never
 * sent here (only "is set"), and a blank secret field keeps the stored one.
 */
export default function IntegrationFormModal({ integration, catalog, onClose }) {
    const editing = Boolean(integration);
    const form = useForm(integration ? integration.form : emptyForm(catalog));
    const { data, setData, errors } = form;

    const hasBody = data.method !== 'GET';
    const needsAuthSecret = data.auth_type !== 'none';
    const secretStored = editing && integration.form.auth_secret_set && integration.form.auth_type === data.auth_type;

    const submit = (event) => {
        event.preventDefault();

        // UI-only flags are not part of the request.
        form.transform(({ auth_secret_set, headers, query, ...rest }) => ({
            ...rest,
            headers: headers.map(({ secret_set, ...row }) => row),
            query: query.map(({ secret_set, ...row }) => row),
        }));

        const options = { preserveScroll: true, onSuccess: onClose };

        if (editing) {
            form.put(route('integrations.update', integration.id), options);
        } else {
            form.post(route('integrations.store'), options);
        }
    };

    return (
        <Modal show onClose={onClose} maxWidth="4xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">{editing ? 'Editar integración' : 'Nueva integración'}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Conexión genérica a un servicio externo mediante una API HTTP.</p>
                </header>

                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-5 py-6 sm:px-8">
                    <Section title="General">
                        <div>
                            <InputLabel htmlFor="int_name" value="Nombre" />
                            <TextInput id="int_name" className="mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} maxLength={100} />
                            <InputError message={errors.name} className="mt-1" />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel htmlFor="int_provider" value="Proveedor o servicio (opcional)" />
                                <TextInput id="int_provider" className="mt-1" value={data.provider} onChange={(e) => setData('provider', e.target.value)} invalid={Boolean(errors.provider)} maxLength={100} />
                                <InputError message={errors.provider} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="int_type" value="Tipo" />
                                <Select id="int_type" className="mt-1" value={data.type} onChange={(value) => setData('type', value)} options={catalog.types} disabled={editing} invalid={Boolean(errors.type)} />
                                <InputError message={errors.type} className="mt-1" />
                            </div>
                        </div>
                        <div>
                            <InputLabel htmlFor="int_description" value="Descripción (opcional)" />
                            <Textarea id="int_description" className="mt-1" rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} invalid={Boolean(errors.description)} maxLength={500} />
                            <InputError message={errors.description} className="mt-1" />
                        </div>
                        {!editing && <Toggle checked={data.is_active} onChange={(value) => setData('is_active', value)} label="Integración activa" description="Una integración inactiva no se usa para conexiones reales." />}
                    </Section>

                    <Section title="Conexión" hint="Dirección y método de la solicitud que se enviará al servicio.">
                        <div>
                            <InputLabel htmlFor="int_base_url" value="URL base" />
                            <TextInput id="int_base_url" className="mt-1" value={data.base_url} onChange={(e) => setData('base_url', e.target.value)} placeholder="https://api.ejemplo.com/v1" invalid={Boolean(errors.base_url)} maxLength={2048} autoComplete="off" />
                            <InputError message={errors.base_url} className="mt-1" />
                        </div>
                        <div className="grid gap-5 sm:grid-cols-[9rem_1fr_8rem]">
                            <div>
                                <InputLabel htmlFor="int_method" value="Método" />
                                <Select id="int_method" className="mt-1" value={data.method} onChange={(value) => setData('method', value)} options={catalog.methods} invalid={Boolean(errors.method)} />
                                <InputError message={errors.method} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="int_endpoint" value="Endpoint (opcional)" />
                                <TextInput id="int_endpoint" className="mt-1" value={data.endpoint} onChange={(e) => setData('endpoint', e.target.value)} placeholder="/recurso" invalid={Boolean(errors.endpoint)} maxLength={1024} autoComplete="off" />
                                <InputError message={errors.endpoint} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel htmlFor="int_timeout" value="Espera (s)" />
                                <TextInput id="int_timeout" type="number" min="1" max={catalog.maxTimeout} className="mt-1" value={data.timeout} onChange={(e) => setData('timeout', e.target.value)} invalid={Boolean(errors.timeout)} />
                                <InputError message={errors.timeout} className="mt-1" />
                            </div>
                        </div>
                    </Section>

                    <Section title="Autenticación" hint="La credencial se guarda cifrada y no se vuelve a mostrar.">
                        <div>
                            <InputLabel htmlFor="int_auth_type" value="Tipo de autenticación" />
                            <Select id="int_auth_type" className="mt-1" value={data.auth_type} onChange={(value) => setData('auth_type', value)} options={catalog.authTypes} invalid={Boolean(errors.auth_type)} />
                            <InputError message={errors.auth_type} className="mt-1" />
                        </div>

                        {data.auth_type === 'api_key' && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <InputLabel htmlFor="int_auth_name" value="Nombre del header o parámetro" />
                                    <TextInput id="int_auth_name" className="mt-1" value={data.auth_name} onChange={(e) => setData('auth_name', e.target.value)} placeholder="X-API-Key" invalid={Boolean(errors.auth_name)} maxLength={64} autoComplete="off" />
                                    <InputError message={errors.auth_name} className="mt-1" />
                                </div>
                                <div>
                                    <InputLabel htmlFor="int_auth_location" value="Se envía en" />
                                    <Select id="int_auth_location" className="mt-1" value={data.auth_location} onChange={(value) => setData('auth_location', value)} options={catalog.apiKeyLocations} invalid={Boolean(errors.auth_location)} />
                                    <InputError message={errors.auth_location} className="mt-1" />
                                </div>
                            </div>
                        )}

                        {data.auth_type === 'basic' && (
                            <div>
                                <InputLabel htmlFor="int_auth_username" value="Usuario" />
                                <TextInput id="int_auth_username" className="mt-1" value={data.auth_username} onChange={(e) => setData('auth_username', e.target.value)} invalid={Boolean(errors.auth_username)} maxLength={255} autoComplete="off" />
                                <InputError message={errors.auth_username} className="mt-1" />
                            </div>
                        )}

                        {needsAuthSecret && (
                            <div>
                                <InputLabel
                                    htmlFor="int_auth_secret"
                                    value={{ api_key: 'API Key', bearer: 'Token', basic: 'Contraseña' }[data.auth_type]}
                                />
                                <TextInput
                                    id="int_auth_secret"
                                    type="password"
                                    className="mt-1"
                                    value={data.auth_secret}
                                    onChange={(e) => setData('auth_secret', e.target.value)}
                                    placeholder={secretStored ? '•••••••••••• (guardada; vacío = conservar)' : 'Credencial'}
                                    invalid={Boolean(errors.auth_secret)}
                                    autoComplete="new-password"
                                />
                                <InputError message={errors.auth_secret} className="mt-1" />
                                {secretStored && <p className="mt-1.5 text-xs text-ink-muted">Escribe un valor nuevo solo para reemplazarla.</p>}
                            </div>
                        )}
                    </Section>

                    <Section title="Headers" hint="Cabeceras adicionales de la solicitud.">
                        <KeyValueRows id="headers" label="Header" rows={data.headers} onChange={(rows) => setData('headers', rows)} errors={errors} max={catalog.maxHeaders} namePlaceholder="Accept" addLabel="Añadir header" />
                    </Section>

                    <Section title="Parámetros de consulta" hint="Se añaden a la URL como ?clave=valor.">
                        <KeyValueRows id="query" label="Parámetro" rows={data.query} onChange={(rows) => setData('query', rows)} errors={errors} max={catalog.maxQuery} namePlaceholder="page" addLabel="Añadir parámetro" />
                    </Section>

                    {hasBody && (
                        <Section title="Body (JSON)">
                            <div>
                                <Textarea
                                    aria-label="Body JSON"
                                    className="font-mono text-[13px]"
                                    rows={6}
                                    value={data.body}
                                    onChange={(e) => setData('body', e.target.value)}
                                    placeholder='{"clave": "valor"}'
                                    invalid={Boolean(errors.body)}
                                    spellCheck={false}
                                />
                                <InputError message={errors.body} className="mt-1" />
                                <p className="mt-1 text-xs text-ink-muted">El body se muestra al editar: no pongas credenciales aquí; usa la autenticación o un header secreto.</p>
                            </div>
                        </Section>
                    )}

                    {data.method !== 'GET' && (
                        <Alert tone="warning">Al probar la conexión se enviará la solicitud {data.method} configurada tal cual, con su body.</Alert>
                    )}
                </div>

                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {form.processing ? 'Guardando...' : editing ? 'Guardar cambios' : 'Crear integración'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
