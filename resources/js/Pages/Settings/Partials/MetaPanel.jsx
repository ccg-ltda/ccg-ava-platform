import axios from 'axios';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/Components/Toast';

const FIELDS = [
    { key: 'app_id', label: 'App ID de Facebook', hint: 'Meta for Developers > tu app > Configuración > Básica. Lo usa Messenger.', secret: false },
    { key: 'app_secret', label: 'Secreto de la app de Facebook', hint: 'En la misma pantalla. Firma los webhooks de Messenger y WhatsApp.', secret: true },
    { key: 'instagram_app_id', label: 'App ID de Instagram', hint: 'Instagram > Configuración de la API con inicio de sesión de Instagram.', secret: false },
    { key: 'instagram_app_secret', label: 'Secreto de la app de Instagram', hint: 'En la misma pantalla de Instagram.', secret: true },
    { key: 'webhook_verify_token', label: 'Token de verificación del webhook', hint: 'Lo inventas tú (16 a 100 caracteres) y lo escribes igual en el webhook de Meta.', secret: true },
    { key: 'graph_version', label: 'Versión de Graph', hint: 'Por ejemplo v26.0. Vacío usa la versión por defecto.', secret: false },
];
const SOURCE = { database: 'Guardado en Ava', environment: 'Viene del servidor (.env)', none: 'Sin configurar' };

/**
 * The platform's Meta app (Configuraciones > Meta, platform administration only). It is configured ONCE for every Workspace; each Workspace then connects its own Instagram account or
 * Facebook Page from Integraciones. Secrets are write-only: the server returns only whether each is set, where it comes from and its last four characters; a blank field keeps the stored value.
 */
export default function MetaPanel({ meta }) {
    const toast = useToast();
    const [values, setValues] = useState({});
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const set = (key) => (event) => setValues((previous) => ({ ...previous, [key]: event.target.value }));

    const save = (event) => {
        event.preventDefault();
        setBusy(true);
        router.put(route('meta-settings.update'), values, {
            preserveScroll: true,
            onError: (e) => { setErrors(e); toast.error('Revisa los campos marcados.'); },
            onSuccess: () => { setErrors({}); setValues({}); },
            onFinish: () => setBusy(false),
        });
    };

    const check = () => {
        setBusy(true);
        axios.post(route('meta-settings.check'))
            .then((response) => { setResult(response.data); response.data.ok ? toast.success(response.data.message) : toast.error(response.data.message); router.reload({ only: ['meta'] }); })
            .catch(() => toast.error('No se pudo comprobar la app.'))
            .finally(() => setBusy(false));
    };

    const missing = Object.entries(meta.missing).filter(([, reason]) => reason);
    const validation = meta.validation;

    return (
        <div className="space-y-6">
            <Card className="space-y-4 p-5 sm:p-6">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-lg font-bold text-ink">App de Meta de la plataforma</h2>
                    {validation.status === 'ok' && <Badge tone="green">Verificada con Meta</Badge>}
                    {validation.status === 'failed' && <Badge tone="red">Verificación fallida</Badge>}
                    {!validation.status && <Badge tone="amber">Sin verificar</Badge>}
                </div>
                <p className="text-sm text-ink-muted">Se configura una sola vez para todos los Workspaces. Cada negocio conecta después su propia cuenta de Instagram o página de Facebook desde Integraciones: ningún cliente ve ni necesita estos datos.</p>
                {missing.length > 0 ? (
                    <ul className="space-y-1 text-sm text-warning" aria-label="Lo que falta">
                        {missing.map(([channel, reason]) => <li key={channel}><strong>{channel === 'instagram' ? 'Instagram' : 'Messenger'}:</strong> {reason}</li>)}
                    </ul>
                ) : (
                    <p className="text-sm text-ink">La app está completa: Instagram y Messenger pueden conectarse desde Integraciones.</p>
                )}
                {validation.message && <p className="text-xs text-ink-muted">Última comprobación: {validation.message}{validation.at ? ` (${new Date(validation.at).toLocaleString()})` : ''}</p>}
                {result && (
                    <ul className="space-y-1 text-xs text-ink-muted" aria-label="Resultado de la comprobación">
                        {Object.entries(result.results).map(([name, item]) => <li key={name}><strong>{name === 'facebook' ? 'Facebook' : 'Instagram'}:</strong> {item.message}</li>)}
                    </ul>
                )}
            </Card>

            <Card className="p-5 sm:p-6">
                <form onSubmit={save} noValidate className="space-y-5">
                    <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                        {FIELDS.map((field) => {
                            const state = meta.fields[field.key];

                            return (
                                <div key={field.key} className="min-w-0">
                                    <InputLabel htmlFor={`meta_${field.key}`} value={field.label} />
                                    <TextInput
                                        id={`meta_${field.key}`}
                                        type={field.secret ? 'password' : 'text'}
                                        className="mt-1"
                                        value={values[field.key] ?? ''}
                                        onChange={set(field.key)}
                                        invalid={Boolean(errors[field.key])}
                                        placeholder={state.set ? (field.secret ? `Guardado ${state.hint ?? ''} · escribe para cambiarlo` : `${state.hint ?? ''}`) : 'Sin configurar'}
                                        autoComplete="off"
                                    />
                                    <InputError message={errors[field.key]} className="mt-1" />
                                    <p className="mt-1 text-xs text-ink-muted">{SOURCE[state.source]} · {field.hint}</p>
                                </div>
                            );
                        })}
                    </div>
                    <div className="rounded-xl border border-line bg-canvas/50 p-4 text-xs text-ink-muted">
                        <p className="font-semibold text-ink">Qué pegar en Meta</p>
                        <p className="mt-1 break-all">URI de redirección OAuth: <code>{meta.urls.redirect}</code></p>
                        <p className="break-all">URL del webhook: <code>{meta.urls.webhook}</code></p>
                        {!meta.urls.public && <p className="mt-1 text-warning">Estas direcciones no son HTTPS públicas: Meta no las aceptará. Usa el dominio publicado de la plataforma.</p>}
                    </div>
                    {meta.canEdit ? (
                        <div className="flex flex-wrap justify-end gap-3">
                            <SecondaryButton type="button" disabled={busy} onClick={check}>Comprobar con Meta</SecondaryButton>
                            <PrimaryButton type="submit" disabled={busy || Object.values(values).every((value) => !value)}>Guardar</PrimaryButton>
                        </div>
                    ) : (
                        <p className="text-xs text-ink-muted">Tu rol puede consultar esta configuración, no cambiarla.</p>
                    )}
                </form>
            </Card>
        </div>
    );
}
