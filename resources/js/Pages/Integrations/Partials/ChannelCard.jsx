import { Check, Circle, Pencil, PlugZap, Power, Settings2 } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import CopyBlock from '@/Components/CopyBlock';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { channelIcon } from '@/config/chatbots';

/**
 * What Ava really knows about the channel in this Workspace. Saved settings are only "Configurado"; "Conexión
 * verificada" or "Error de conexión" appear only after a real test, and only for channels that can be tested.
 */
const GATEWAY_STATES = {
    plan_disabled: { label: 'Deshabilitada por el plan', tone: 'neutral' },
    admin_disabled: { label: 'Desactivada por administración', tone: 'neutral' },
    not_configured: { label: 'No configurada', tone: 'amber' },
    incomplete: { label: 'Configuración incompleta', tone: 'amber' },
    saved: { label: 'Guardada, sin validar', tone: 'blue' },
    validated: { label: 'Validada', tone: 'green' },
    error: { label: 'Error de conexión', tone: 'red' },
    inactive: { label: 'Inactiva', tone: 'neutral' },
    blocked_environment: { label: 'Producción no autorizada', tone: 'amber' },
    incompatible: { label: 'Moneda o país no compatibles', tone: 'amber' },
};

function status(channel) {
    const { integration } = channel;

    // A payment gateway shows the state the checkout itself computes: a saved configuration is never «validada» without a real connection test.
    if (channel.state) return GATEWAY_STATES[channel.state] ?? { label: channel.state, tone: 'neutral' };

    if (!channel.available) return { label: 'No disponible todavía', tone: 'neutral' };
    if (!integration) return { label: 'No configurado', tone: 'amber' };
    if (!integration.isActive) return { label: 'Inactivo', tone: 'neutral' };
    // WhatsApp: «conectado» only when credentials, webhook, Meta's verification and real traffic are all proven (see App\Services\WhatsAppConnection).
    if (channel.connection) return { label: channel.connection.label, tone: channel.connection.tone };
    if (integration.lastTest) return integration.lastTest.ok ? { label: 'Conexión verificada', tone: 'green' } : { label: 'Error de conexión', tone: 'red' };

    return { label: 'Configurado', tone: 'blue' };
}

/** The stages of a channel connection (and the webhook URL to paste in Meta, for WhatsApp). Each stage is done only when it was really observed. */
function ConnectionStages({ connection }) {
    return (
        <details className="mt-3 rounded-lg border border-line px-3 py-2 text-sm">
            <summary className="cursor-pointer font-semibold text-ink">{connection.webhook || connection.oauth ? 'Webhook y estado de la conexión' : 'Estado de la conexión'}</summary>
            <ol className="mt-3 space-y-2" aria-label="Etapas de la conexión">
                {connection.stages.map((stage) => (
                    <li key={stage.key} className="flex items-start gap-2">
                        {stage.done ? <Check className="mt-0.5 size-4 shrink-0 text-accent-green" aria-label="Hecho" /> : <Circle className="mt-0.5 size-4 shrink-0 text-ink-muted" aria-label="Pendiente" />}
                        <span>
                            <span className="font-medium text-ink">{stage.label}</span>
                            <span className="block text-xs text-ink-muted">{stage.detail}</span>
                        </span>
                    </li>
                ))}
            </ol>
            {connection.oauth && (
                <div className="mt-3 space-y-2">
                    <p className="text-xs font-semibold text-ink">Datos para configurar la app de Meta (los configura CCG una sola vez)</p>
                    <CopyBlock value={connection.oauth.redirect} label="URI de redireccionamiento de OAuth válida" />
                    <CopyBlock value={connection.oauth.webhook} label="URL de devolución de llamada del webhook" />
                    <p className="text-xs text-ink-muted">Permisos que se piden: {connection.oauth.scopes.join(', ')}. El webhook usa el campo «messages» y el mismo Verify Token que el de WhatsApp (META_WEBHOOK_VERIFY_TOKEN).</p>
                    {!connection.oauth.public && (
                        <p role="alert" className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            Estas direcciones se generaron desde una dirección local o sin HTTPS: Meta no puede usarlas. Abre Zonno desde su dominio público (https).
                        </p>
                    )}
                    <p className="text-xs text-ink-muted">Mientras la app de Meta esté en modo Development, solo pueden autorizar y escribir las personas con un rol en la app. Para atender al público hace falta la revisión de Meta (App Review) y el acceso avanzado a los permisos de mensajería.</p>
                </div>
            )}
            {connection.webhook && (
            <div className="mt-3 space-y-1.5">
                <p className="text-xs font-semibold text-ink">URL del webhook para Meta (Callback URL)</p>
                <CopyBlock value={connection.webhook.url} label="URL del webhook de WhatsApp" />
                {connection.webhook.public === false && (
                    <p role="alert" className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        Esta URL se generó desde una dirección local o sin HTTPS: Meta no puede llamarla. Abre Zonno desde su dominio público (https) y vuelve a copiarla, o publica esta instalación con un túnel HTTPS. Que la URL exista no significa que Meta la haya verificado.
                    </p>
                )}
                <p className="text-xs text-ink-muted">Pégala en Meta for Developers &gt; WhatsApp &gt; Configuración &gt; Webhook, con el Verify Token que guardaste en esta conexión (por seguridad no se vuelve a mostrar), y suscribe el campo «messages». Meta solo alcanza una URL pública con HTTPS: una dirección local no sirve.</p>
            </div>
            )}
        </details>
    );
}

/**
 * One channel of the GLOBAL catalog as it stands in the Workspace being shown. The catalog is the same for every
 * Workspace; only this card's state and data are the Workspace's own. Credentials never reach it.
 */
export default function ChannelCard({ channel, delay, readOnly, can = { edit: true, test: true, toggle: true }, testing, platformEditable = false, onConfigure, onConfigureMeta, onToggle, onTest }) {
    const { label, description, available, unavailable, integration, testable } = channel;
    const { label: stateLabel, tone } = status(channel);
    const Icon = channelIcon(channel.key);

    return (
        <Card hover delay={delay} className={`flex h-full flex-col p-5 ${available ? '' : 'bg-canvas/40'}`}>
            <div className="flex flex-wrap items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <Icon className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-[11rem] flex-1">
                    <h3 className="truncate text-base font-bold text-ink">{label}</h3>
                    <p className="text-sm text-ink-muted">{description}</p>
                </div>
                <Badge tone={tone}>{stateLabel}</Badge>
            </div>

            {/* Instagram and Messenger explain themselves inside «Configurar», not on the card. */}
            {!available && !channel.oauth && <p className="mt-3 text-sm text-ink-muted">{unavailable}</p>}

            {integration && (
                <dl className="mt-4 space-y-1.5 text-sm">
                    {integration.summary.rows.map(({ label: rowLabel, value }) => (
                        <div key={rowLabel} className="flex items-center justify-between gap-3">
                            <dt className="shrink-0 text-ink-muted">{rowLabel}</dt>
                            <dd className="min-w-0 truncate font-semibold text-ink" title={value}>
                                {value}
                            </dd>
                        </div>
                    ))}
                    <div className="flex items-center justify-between gap-3">
                        <dt className="text-ink-muted">Actualizada</dt>
                        <dd className="text-ink">{integration.updatedAt}</dd>
                    </div>
                </dl>
            )}

            {channel.reasons?.length > 0 && integration && <p className="mt-3 text-sm text-ink-muted">{channel.reasons.join(' ')}</p>}
            {channel.blockedBy?.length > 0 && (
                <details className="mt-3 rounded-lg border border-line px-3 py-2 text-sm">
                    <summary className="cursor-pointer font-semibold text-ink">Pendiente del proveedor</summary>
                    <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-ink-muted">
                        {channel.blockedBy.map((item) => (
                            <li key={item}>{item}</li>
                        ))}
                    </ul>
                </details>
            )}
            {integration?.lastTest && (
                <p className={`mt-3 rounded-lg border p-3 text-sm ${integration.lastTest.ok ? 'border-accent-green/25 bg-accent-green/10' : 'border-danger/25 bg-danger/10'}`}>
                    {integration.lastTest.message}
                    <span className="mt-0.5 block text-xs text-ink-muted">{integration.lastTest.at}</span>
                </p>
            )}
            {channel.connection && integration && !channel.oauth && <ConnectionStages connection={channel.connection} />}
            {integration && testable && !readOnly && can.test && !integration.lastTest && <p className="mt-3 text-sm text-ink-muted">Conexión sin verificar. Usa «Probar conexión» para comprobarla con el proveedor.</p>}

            {/* One footer for every card, pinned to the bottom and aligned to the right edge (cards of different heights line up). Instagram and Messenger have a single «Configurar»
                (the modal holds the explanation, the connection, its stages and the platform app); the others keep Editar / Probar / Activar. */}
            {!readOnly && !channel.restricted && channel.oauth && (can.edit || can.test || can.toggle || platformEditable) && (
                <div className="mt-auto flex flex-wrap items-center justify-end gap-2 pt-5">
                    <PrimaryButton type="button" onClick={onConfigureMeta} className="gap-2">
                        <Settings2 className="size-4" aria-hidden="true" />
                        Configurar
                    </PrimaryButton>
                </div>
            )}

            {available && !readOnly && !channel.oauth && (integration ? can.edit || can.test || can.toggle : can.edit) && (
                <div className="mt-auto flex flex-wrap items-center justify-end gap-2 pt-5">
                    {integration ? (
                        <>
                            {can.edit && <IconButton icon={Pencil} label="Editar" context={label} onClick={onConfigure} />}
                            {can.test && testable && <IconButton icon={PlugZap} label={testing ? 'Probando...' : 'Probar conexión'} context={label} onClick={onTest} disabled={testing || !integration.isActive} />}
                            {can.toggle && <IconButton icon={Power} label={integration.isActive ? 'Desactivar' : 'Activar'} context={label} tone={integration.isActive ? 'danger' : 'primary'} onClick={onToggle} />}
                        </>
                    ) : (
                        <PrimaryButton type="button" onClick={onConfigure} className="gap-2">
                            <Settings2 className="size-4" aria-hidden="true" />
                            Configurar
                        </PrimaryButton>
                    )}
                </div>
            )}
        </Card>
    );
}
