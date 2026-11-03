import { router } from '@inertiajs/react';
import { History, Pencil, Plus, Power, Workflow } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import IconButton from '@/Components/IconButton';
import PrimaryButton from '@/Components/PrimaryButton';
import RuleEventsModal from './RuleEventsModal';
import RuleFormModal from './RuleFormModal';

const STATS = [
    { key: 'scheduled', label: 'Programados' },
    { key: 'sent', label: 'Enviados' },
    { key: 'skipped', label: 'Omitidos' },
    { key: 'failed', label: 'Con problema' },
];

const UNITS = { hours: ['hora', 'horas'], days: ['día', 'días'], weeks: ['semana', 'semanas'] };

/** «30 días» / «2 semanas» / «1 hora»: the wait of a rule in words. */
const waitLabel = (rule) => `${rule.delayAmount} ${UNITS[rule.delayUnit][rule.delayAmount === 1 ? 0 : 1]}`;

/**
 * Automatic reminders by service. A rule says «N days after a customer had service X, send this message» and Ava does the rest: it looks for the services Agenda marked as done
 * and schedules one reminder per customer, each with its own date. Nobody picks customers here. The server decides what each role may do and which channels exist.
 */
export default function RulesPanel({ rules, channels, variables, limits, timezone, can }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null); // null = closed, 'new' = creating, otherwise the rule
    const [historyOf, setHistoryOf] = useState(null);
    const anyChannel = channels.some((channel) => channel.available);

    const toggle = async (rule) => {
        if (rule.isActive) {
            const confirmed = await confirm({ description: `«${rule.name}» dejará de programar recordatorios y se cancelarán los que programó y todavía no salieron.`, confirmLabel: 'Desactivar regla' });

            if (!confirmed) return;
        }

        router.post(route(rule.isActive ? 'reminders.rules.deactivate' : 'reminders.rules.activate', rule.id), {}, { preserveScroll: true });
    };

    return (
        <>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="max-w-3xl text-sm text-ink-muted">
                    Define una vez cuánto esperar después de un servicio y Ava programa sola un recordatorio para cada cliente que lo realice, con su propia fecha. Solo cuentan los servicios que Agenda marca como realizados desde que la regla está activa. No cambia las confirmaciones ni los recordatorios de las citas.
                </p>
                {can.create && (
                    <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2" disabled={!anyChannel || rules.services.length === 0}>
                        <Plus className="size-4" aria-hidden="true" />
                        Nueva regla
                    </PrimaryButton>
                )}
            </div>

            {!anyChannel && <Alert tone="warning">Ningún canal está disponible para enviar recordatorios: {channels.map((channel) => `${channel.label} (${channel.reason})`).join(' · ')}</Alert>}
            {anyChannel && rules.services.length === 0 && <Alert tone="warning">Este Workspace no tiene servicios activos: crea uno en Servicios para poder usarlo en una regla.</Alert>}

            {rules.data.length === 0 ? (
                <EmptyState icon={Workflow} title="Todavía no hay reglas" description="Crea la primera: por ejemplo, «30 días después de un mantenimiento de uñas, recordar al cliente que reserve de nuevo».">
                    {can.create && anyChannel && rules.services.length > 0 && (
                        <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                            <Plus className="size-4" aria-hidden="true" />
                            Nueva regla
                        </PrimaryButton>
                    )}
                </EmptyState>
            ) : (
                <ul className="grid gap-4 lg:grid-cols-2" aria-label="Reglas de recordatorio">
                    {rules.data.map((rule) => (
                        <li key={rule.id} className="min-w-0">
                            <Card className="flex h-full flex-col gap-4 p-4 sm:p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h3 className="truncate text-base font-bold text-ink" title={rule.name}>{rule.name}</h3>
                                        <p className="mt-0.5 text-sm text-ink-muted">
                                            {waitLabel(rule)} después del servicio{rule.sendTime ? `, a las ${rule.sendTime}` : ''} · {channels.find((channel) => channel.key === rule.channel)?.label ?? rule.channel}
                                        </p>
                                    </div>
                                    <Badge tone={rule.isActive ? 'green' : 'neutral'}>{rule.isActive ? 'Activa' : 'Desactivada'}</Badge>
                                </div>

                                <ul className="flex flex-wrap gap-1.5" aria-label="Servicios de la regla">
                                    {rule.services.map((service) => (
                                        <li key={service.id} className="max-w-full truncate rounded-full bg-canvas px-2.5 py-1 text-xs font-medium text-ink ring-1 ring-line">{service.name}</li>
                                    ))}
                                </ul>

                                <dl className="grid grid-cols-4 gap-2 rounded-xl bg-canvas/60 p-3 text-center">
                                    {STATS.map((stat) => (
                                        <div key={stat.key} className="min-w-0">
                                            <dt className="truncate text-[11px] font-semibold text-ink-muted">{stat.label}</dt>
                                            <dd className={`text-lg font-bold tabular-nums ${stat.key === 'failed' && rule.stats.failed > 0 ? 'text-danger' : 'text-ink'}`}>{rule.stats[stat.key]}</dd>
                                        </div>
                                    ))}
                                </dl>

                                <div className="mt-auto flex justify-end gap-1">
                                    <IconButton icon={History} label="Ver historial" context={rule.name} onClick={() => setHistoryOf(rule)} />
                                    {can.edit && <IconButton icon={Pencil} label="Editar" context={rule.name} onClick={() => setEditing(rule)} />}
                                    {can.activate && <IconButton icon={Power} label={rule.isActive ? 'Desactivar' : 'Activar'} context={rule.name} tone={rule.isActive ? 'danger' : undefined} onClick={() => toggle(rule)} />}
                                </div>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}

            {editing && <RuleFormModal key={editing === 'new' ? 'new' : editing.id} rule={editing === 'new' ? null : editing} rules={rules} channels={channels} variables={variables} limits={limits} timezone={timezone} onClose={() => setEditing(null)} />}
            {historyOf && <RuleEventsModal rule={historyOf} onClose={() => setHistoryOf(null)} />}
        </>
    );
}
