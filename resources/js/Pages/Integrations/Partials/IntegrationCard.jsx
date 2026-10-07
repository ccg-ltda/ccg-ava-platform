import { CheckCircle2, CircleDashed, Pencil, Plug, PlugZap, Power, XCircle } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import IconButton from '@/Components/IconButton';

/** State of the last connection test, as a badge plus the short message the server kept. */
function ConnectionState({ lastTest }) {
    if (!lastTest) {
        return (
            <p className="flex items-center gap-1.5 text-sm text-ink-muted">
                <CircleDashed className="size-4" aria-hidden="true" />
                Conexión sin probar
            </p>
        );
    }

    const Icon = lastTest.ok ? CheckCircle2 : XCircle;

    return (
        <div>
            <p className={`flex items-center gap-1.5 text-sm font-semibold ${lastTest.ok ? 'text-accent-green' : 'text-danger'}`}>
                <Icon className="size-4" aria-hidden="true" />
                {lastTest.ok ? 'Conexión correcta' : 'Falló la conexión'}
            </p>
            <p className="mt-0.5 text-xs text-ink-muted">
                {lastTest.message} · {lastTest.at}
            </p>
        </div>
    );
}

/** One integration as an independent entity: identity, state, connection health and its three actions. */
export default function IntegrationCard({ integration, delay, testing, readOnly = false, onEdit, onTest, onToggle }) {
    const { name, provider, description, typeLabel, isActive, summary, lastTest, updatedAt } = integration;

    return (
        <Card hover delay={delay} className={`flex flex-col p-5 ${isActive ? '' : 'bg-canvas/40'}`}>
            <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <Plug className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="truncate text-base font-bold text-ink" title={name}>
                        {name}
                    </h2>
                    <p className="truncate text-sm text-ink-muted">{provider || 'Sin proveedor'}</p>
                </div>
                <Badge tone={isActive ? 'green' : 'neutral'}>{isActive ? 'Activa' : 'Inactiva'}</Badge>
            </div>

            {description && <p className="mt-3 line-clamp-2 text-sm text-ink-muted">{description}</p>}

            <dl className="mt-4 space-y-1.5 text-sm">
                <div className="flex items-center justify-between gap-3">
                    <dt className="text-ink-muted">Tipo</dt>
                    <dd className="font-semibold text-ink">{typeLabel}</dd>
                </div>
                <div className="flex items-center justify-between gap-3">
                    <dt className="text-ink-muted">Solicitud</dt>
                    <dd className="min-w-0 truncate font-mono text-[13px] text-ink" title={`${summary.method} ${summary.host}${summary.endpoint}`}>
                        {summary.method} {summary.host}
                        {summary.endpoint}
                    </dd>
                </div>
                <div className="flex items-center justify-between gap-3">
                    <dt className="text-ink-muted">Autenticación</dt>
                    <dd className="text-ink">{summary.auth}</dd>
                </div>
            </dl>

            <div className="mt-4 rounded-lg border border-line bg-canvas/60 p-3">
                <ConnectionState lastTest={lastTest} />
            </div>

            <div className="mt-4 flex items-center justify-between gap-3 border-t border-line pt-3">
                <p className="min-w-0 truncate text-xs text-ink-muted">Actualizada {updatedAt}</p>
                {!readOnly && (
                    <div className="flex shrink-0 items-center gap-1">
                        <IconButton icon={Pencil} label="Editar" context={name} onClick={onEdit} />
                        <IconButton icon={PlugZap} label={testing ? 'Probando...' : 'Probar conexión'} context={name} onClick={onTest} disabled={testing || !isActive} />
                        <IconButton icon={Power} label={isActive ? 'Desactivar' : 'Activar'} context={name} tone={isActive ? 'danger' : 'primary'} onClick={onToggle} />
                    </div>
                )}
            </div>
        </Card>
    );
}
