import { Pencil, PlugZap, Power, Settings2 } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { channelIcon } from '@/config/chatbots';

/**
 * What Ava really knows about the channel in this Workspace. Saved settings are only "Configurado"; "Conexión
 * verificada" or "Error de conexión" appear only after a real test, and only for channels that can be tested.
 */
function status(channel) {
    const { integration } = channel;

    if (!channel.available) return { label: 'No disponible todavía', tone: 'neutral' };
    if (!integration) return { label: 'No configurado', tone: 'amber' };
    if (!integration.isActive) return { label: 'Inactivo', tone: 'neutral' };
    if (integration.lastTest) return integration.lastTest.ok ? { label: 'Conexión verificada', tone: 'green' } : { label: 'Error de conexión', tone: 'red' };

    return { label: 'Configurado', tone: 'blue' };
}

/**
 * One channel of the GLOBAL catalog as it stands in the Workspace being shown. The catalog is the same for every
 * Workspace; only this card's state and data are the Workspace's own. Credentials never reach it.
 */
export default function ChannelCard({ channel, delay, readOnly, testing, onConfigure, onToggle, onTest }) {
    const { label, description, available, unavailable, integration, testable } = channel;
    const { label: stateLabel, tone } = status(channel);
    const Icon = channelIcon(channel.key);

    return (
        <Card hover delay={delay} className={`flex flex-col p-5 ${available ? '' : 'bg-canvas/40'}`}>
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

            {!available && <p className="mt-3 text-sm text-ink-muted">{unavailable}</p>}

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

            {integration?.lastTest && (
                <p className={`mt-3 rounded-lg border p-3 text-sm ${integration.lastTest.ok ? 'border-accent-green/25 bg-accent-green/10' : 'border-danger/25 bg-danger/10'}`}>
                    {integration.lastTest.message}
                    <span className="mt-0.5 block text-xs text-ink-muted">{integration.lastTest.at}</span>
                </p>
            )}
            {integration && testable && !readOnly && !integration.lastTest && <p className="mt-3 text-sm text-ink-muted">Conexión sin verificar. Usa «Probar conexión» para comprobarla con el proveedor.</p>}

            {available && !readOnly && (
                <div className="mt-4 flex items-center justify-end gap-1 border-t border-line pt-3">
                    {integration ? (
                        <>
                            <IconButton icon={Pencil} label="Editar" context={label} onClick={onConfigure} />
                            {testable && <IconButton icon={PlugZap} label={testing ? 'Probando...' : 'Probar conexión'} context={label} onClick={onTest} disabled={testing || !integration.isActive} />}
                            <IconButton icon={Power} label={integration.isActive ? 'Desactivar' : 'Activar'} context={label} tone={integration.isActive ? 'danger' : 'primary'} onClick={onToggle} />
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
