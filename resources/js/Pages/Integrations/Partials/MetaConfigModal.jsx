import axios from 'axios';
import { router } from '@inertiajs/react';
import { Check, Circle, Link2, PlugZap, Power, RefreshCw, Unplug } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import { useConfirm } from '@/Components/ConfirmDialog';
import CopyBlock from '@/Components/CopyBlock';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/Components/Toast';

// The fields of the platform's Meta app that each channel reads. The verify token and the Graph version are shared by every Meta channel (one webhook for all).
const APP_FIELDS = {
    instagram: [
        { key: 'instagram_app_id', label: 'App ID de Instagram', secret: false, hint: 'Meta for Developers > tu app > Instagram > Configuración de la API con inicio de sesión de Instagram.' },
        { key: 'instagram_app_secret', label: 'Secreto de la app de Instagram', secret: true, hint: 'En la misma pantalla. Firma los eventos de Instagram.' },
    ],
    messenger: [
        { key: 'app_id', label: 'App ID de Facebook', secret: false, hint: 'Meta for Developers > tu app > Configuración > Básica.' },
        { key: 'app_secret', label: 'Secreto de la app de Facebook', secret: true, hint: 'En la misma pantalla. Firma los eventos de Messenger y WhatsApp.' },
    ],
};
const SHARED_FIELDS = [
    { key: 'webhook_verify_token', label: 'Token de verificación del webhook', secret: true, hint: 'Lo inventas tú (16 a 100 caracteres) y lo escribes igual en el webhook de Meta. Lo comparten Instagram, Messenger y WhatsApp.' },
    { key: 'graph_version', label: 'Versión de Graph', secret: false, hint: 'Por ejemplo v26.0. Vacío usa la versión por defecto. Compartida por los canales de Meta.' },
];
const SOURCE = { database: 'Guardado en Ava', environment: 'Viene del servidor (.env)', none: 'Sin configurar' };

/** The stages of THIS Workspace's connection: each is done only when it was really observed. */
function Stages({ connection }) {
    return (
        <ol className="space-y-2" aria-label="Etapas de la conexión">
            {connection.stages.map((stage) => (
                <li key={stage.key} className="flex items-start gap-2 text-sm">
                    {stage.done ? <Check className="mt-0.5 size-4 shrink-0 text-accent-green" aria-label="Hecho" /> : <Circle className="mt-0.5 size-4 shrink-0 text-ink-muted" aria-label="Pendiente" />}
                    <span>
                        <span className="font-medium text-ink">{stage.label}</span>
                        <span className="block text-xs text-ink-muted">{stage.detail}</span>
                    </span>
                </li>
            ))}
        </ol>
    );
}

/** The platform's Meta app: configured ONCE for every Workspace by the platform administration. Secrets are write-only (the server returns whether each is set and its last four characters). */
function PlatformApp({ channel, platform }) {
    const toast = useToast();
    const [values, setValues] = useState({});
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const fields = [...APP_FIELDS[channel.key], ...SHARED_FIELDS];
    const set = (key) => (event) => setValues((previous) => ({ ...previous, [key]: event.target.value }));

    const save = (event) => {
        event.preventDefault();
        setBusy(true);
        router.put(route('meta-settings.update'), values, {
            preserveScroll: true,
            only: ['metaPlatform', 'channels', 'flash'],
            onError: (e) => { setErrors(e); toast.error('Revisa los campos marcados.'); },
            onSuccess: () => { setErrors({}); setValues({}); },
            onFinish: () => setBusy(false),
        });
    };

    const check = () => {
        setBusy(true);
        axios.post(route('meta-settings.check'))
            .then((response) => { setResult(response.data); response.data.ok ? toast.success(response.data.message) : toast.error(response.data.message); router.reload({ only: ['metaPlatform', 'channels'] }); })
            .catch(() => toast.error('No se pudo comprobar la app.'))
            .finally(() => setBusy(false));
    };

    return (
        <form onSubmit={save} noValidate className="space-y-4 rounded-xl border border-line p-4">
            <div>
                <h3 className="text-sm font-bold text-ink">App de Meta de la plataforma</h3>
                <p className="text-xs text-ink-muted">La configura CCG una sola vez para todos los Workspaces: cada negocio conecta después su propia cuenta con «Conectar». No se edita el .env.</p>
            </div>
            <div className="grid gap-x-4 gap-y-3 sm:grid-cols-2">
                {fields.map((field) => {
                    const state = platform.fields[field.key];

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
                                disabled={!platform.canEdit}
                            />
                            <InputError message={errors[field.key]} className="mt-1" />
                            <p className="mt-1 text-xs text-ink-muted">{SOURCE[state.source]} · {field.hint}</p>
                        </div>
                    );
                })}
            </div>
            <div className="space-y-1.5 rounded-lg bg-canvas/50 p-3 text-xs text-ink-muted">
                <p className="font-semibold text-ink">Qué pegar en Meta</p>
                <CopyBlock value={platform.urls.redirect} label="URI de redireccionamiento de OAuth válida" />
                <CopyBlock value={platform.urls.webhook} label="URL de devolución de llamada del webhook (campo «messages»)" />
                {!platform.urls.public && <p className="text-warning">Estas direcciones no son HTTPS públicas: Meta no las aceptará. Abre Ava desde su dominio publicado.</p>}
            </div>
            {result && (
                <ul className="space-y-1 text-xs text-ink-muted" aria-label="Resultado de la comprobación">
                    {Object.entries(result.results).map(([name, item]) => <li key={name}><strong>{name === 'facebook' ? 'Facebook' : 'Instagram'}:</strong> {item.message}</li>)}
                </ul>
            )}
            {platform.validation.message && <p className="text-xs text-ink-muted">Última comprobación: {platform.validation.message}</p>}
            {platform.canEdit ? (
                <div className="flex flex-wrap justify-end gap-3">
                    <SecondaryButton type="button" disabled={busy} onClick={check}>Comprobar con Meta</SecondaryButton>
                    <PrimaryButton type="submit" disabled={busy || Object.values(values).every((value) => !value)}>Guardar app</PrimaryButton>
                </div>
            ) : (
                <p className="text-xs text-ink-muted">Tu rol puede consultar esta configuración, no cambiarla.</p>
            )}
        </form>
    );
}

/**
 * «Configurar» of Instagram and Messenger. Two levels, said plainly: (1) the platform's Meta app, which CCG configures once and only the platform administration sees and edits here; (2) THIS
 * Workspace's own connection (account or Page, token, webhook, expiry), made with Meta's authorization: a token is never pasted by hand, so it can not leak or belong to someone else.
 * Instagram and Messenger have separate fields and separate connections; neither touches WhatsApp or Web.
 */
export default function MetaConfigModal({ channel, platform, can, testing, onTest, onToggle, onClose }) {
    const confirm = useConfirm();
    const { integration, connection, label } = channel;
    const webhookDone = connection?.stages?.find((stage) => stage.key === 'webhook')?.done;
    const brand = channel.key === 'instagram' ? 'Instagram' : 'Facebook';

    const disconnect = async () => {
        const confirmed = await confirm({ description: `Ava dejará de recibir y enviar mensajes por ${label} y se borrará la autorización guardada. Las conversaciones ya registradas se conservan.`, confirmLabel: 'Desconectar' });

        if (confirmed) router.delete(route('integrations.meta.disconnect', channel.key), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="3xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">Configurar {label}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Conexión propia de este Workspace. Ningún dato se comparte con otro Workspace.</p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    {platform && <PlatformApp channel={channel} platform={platform} />}
                    {!platform && !channel.available && <Alert tone="warning">{channel.unavailable}</Alert>}

                    <section className="space-y-3 rounded-xl border border-line p-4" aria-label={`Conexión de ${label} de este Workspace`}>
                        <div>
                            <h3 className="text-sm font-bold text-ink">Conexión de este Workspace</h3>
                            <p className="text-xs text-ink-muted">
                                {channel.key === 'instagram' ? 'Autoriza la cuenta profesional de Instagram de tu negocio.' : 'Autoriza y elige la página de Facebook de tu negocio.'} El permiso se concede en Meta y Ava guarda el token cifrado: nunca se pega a mano ni se muestra.
                            </p>
                        </div>
                        {connection && <Stages connection={connection} />}
                        {integration && (
                            <dl className="space-y-1 text-sm">
                                {integration.summary.rows.map(({ label: rowLabel, value }) => (
                                    <div key={rowLabel} className="flex items-center justify-between gap-3">
                                        <dt className="shrink-0 text-ink-muted">{rowLabel}</dt>
                                        <dd className="min-w-0 truncate font-semibold text-ink" title={value}>{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {integration?.lastTest && <p className={`rounded-lg border p-3 text-sm ${integration.lastTest.ok ? 'border-accent-green/25 bg-accent-green/10' : 'border-danger/25 bg-danger/10'}`}>{integration.lastTest.message}</p>}
                        {connection?.oauth && <p className="text-xs text-ink-muted">Permisos que se piden: {connection.oauth.scopes.join(', ')}. Mientras la app esté en modo Development solo pueden autorizar las personas con un rol en la app; para atender al público hace falta la revisión de Meta (App Review).</p>}

                        {channel.available && (can.edit || can.test || can.toggle) && (
                            <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
                                {can.edit && (
                                    <a href={route('integrations.meta.connect', channel.key)} className="inline-flex items-center gap-2 rounded-md border border-transparent bg-primary px-4 py-2 text-xs font-semibold tracking-widest text-white uppercase transition duration-150 hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
                                        <Link2 className="size-4" aria-hidden="true" />
                                        {integration ? `Reconectar con ${brand}` : `Conectar con ${brand}`}
                                    </a>
                                )}
                                {integration && can.edit && !webhookDone && (
                                    <SecondaryButton type="button" onClick={() => router.post(route('integrations.meta.subscribe', channel.key), {}, { preserveScroll: true })}>
                                        <RefreshCw className="mr-2 size-4" aria-hidden="true" />
                                        Reintentar suscripción
                                    </SecondaryButton>
                                )}
                                {integration && can.test && <SecondaryButton type="button" onClick={onTest} disabled={testing || !integration.isActive}><PlugZap className="mr-2 size-4" aria-hidden="true" />{testing ? 'Probando...' : 'Probar conexión'}</SecondaryButton>}
                                {integration && can.toggle && <SecondaryButton type="button" onClick={onToggle}><Power className="mr-2 size-4" aria-hidden="true" />{integration.isActive ? 'Desactivar' : 'Activar'}</SecondaryButton>}
                                {integration && can.edit && <SecondaryButton type="button" onClick={disconnect}><Unplug className="mr-2 size-4" aria-hidden="true" />Desconectar</SecondaryButton>}
                            </div>
                        )}
                    </section>
                </div>

                <div className="flex justify-end border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>Cerrar</SecondaryButton>
                </div>
            </div>
        </Modal>
    );
}
