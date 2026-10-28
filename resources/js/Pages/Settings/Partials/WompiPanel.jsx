import axios from 'axios';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { useToast } from '@/Components/Toast';

const ENV_LABEL = { sandbox: 'Sandbox', production: 'Producción' };
const STATE = {
    incomplete: { tone: 'neutral', label: 'Configuración incompleta' },
    saved: { tone: 'amber', label: 'Guardada, sin validar' },
    validated: { tone: 'green', label: 'Conexión validada' },
    failed: { tone: 'red', label: 'Validación fallida' },
};
const STATUS_TONE = { APPROVED: 'green', PENDING: 'amber', DECLINED: 'red', VOIDED: 'red', ERROR: 'red' };
const money = (cents, currency) => (cents == null ? '—' : `${(cents / 100).toLocaleString('es-CO')} ${currency ?? ''}`);
const when = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

/**
 * Wompi inside Impuestos y facturación (platform administration only). The credentials are write-only: the server returns only whether each one is set and its
 * last four characters, and a blank field keeps the stored value. «Guardada» and «Conexión validada» are different states: validation is a real call to Wompi.
 * Below: the persisted payments (one row per transaction, status verified with Wompi) and the state of invoicing, which is a separate integration.
 */
export default function WompiPanel({ wompi }) {
    const toast = useToast();
    const [environment, setEnvironment] = useState(wompi.activeEnvironment ?? 'sandbox');
    const current = wompi.environments[environment];
    const [fields, setFields] = useState({ public_key: current.publicKey ?? '', private_key: '', events_secret: '', integrity_secret: '' });
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        setFields({ public_key: wompi.environments[environment].publicKey ?? '', private_key: '', events_secret: '', integrity_secret: '' });
        setErrors({});
    }, [environment, wompi]);

    const set = (name) => (event) => setFields((previous) => ({ ...previous, [name]: event.target.value }));
    const state = STATE[current.state];

    const save = (event, activate) => {
        event.preventDefault();
        setBusy(true);
        router.put(route('wompi-settings.update'), { environment, ...fields, activate }, {
            preserveScroll: true,
            onError: (e) => { setErrors(e); toast.error('Revisa los campos marcados.'); },
            onSuccess: () => setErrors({}),
            onFinish: () => setBusy(false),
        });
    };

    const validate = () => {
        setBusy(true);
        axios.post(route('wompi-settings.validate'), { environment })
            .then((response) => {
                response.data.ok ? toast.success('Conexión validada con Wompi.') : toast.error(response.data.message);
                router.reload({ only: ['wompi'], preserveScroll: true });
            })
            .catch((error) => toast.error(error.response?.data?.message ?? 'No se pudo validar la conexión.'))
            .finally(() => setBusy(false));
    };

    const secret = (name, label, isSet, hint) => (
        <div>
            <InputLabel htmlFor={`wompi_${name}`} value={label} />
            <TextInput
                id={`wompi_${name}`}
                type="password"
                autoComplete="off"
                className="mt-1"
                value={fields[name]}
                onChange={set(name)}
                disabled={!wompi.canEdit}
                placeholder={isSet ? `Guardada (${hint}). Deja vacío para conservarla.` : 'Sin configurar'}
                invalid={Boolean(errors[name])}
            />
            <InputError message={errors[name]} className="mt-1" />
        </div>
    );

    return (
        <section aria-label="Wompi" className="space-y-4 rounded-xl border border-line p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-bold text-ink">Wompi (pagos de la contratación)</h3>
                <div className="flex items-center gap-2">
                    {wompi.activeEnvironment && <Badge tone="blue">Activo: {ENV_LABEL[wompi.activeEnvironment]}</Badge>}
                    {!wompi.activeEnvironment && <Badge tone="neutral">{wompi.source === 'environment' ? 'Usa variables de entorno' : 'Sin entorno activo'}</Badge>}
                </div>
            </div>

            <div role="tablist" aria-label="Entorno de Wompi" className="inline-flex rounded-lg border border-line p-0.5">
                {Object.keys(ENV_LABEL).map((env) => (
                    <button
                        key={env}
                        type="button"
                        role="tab"
                        aria-selected={environment === env}
                        onClick={() => setEnvironment(env)}
                        className={`rounded-md px-3 py-1.5 text-sm font-semibold focus-visible:outline-2 ${environment === env ? 'bg-primary text-white' : 'text-ink-muted'}`}
                    >
                        {ENV_LABEL[env]}
                    </button>
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-2" aria-live="polite">
                <Badge tone={state.tone}>{state.label}</Badge>
                {current.validatedAt && <span className="text-xs text-ink-muted">Última validación: {when(current.validatedAt)}</span>}
            </div>
            {current.validationMessage && <p className="text-sm text-ink-muted">{current.validationMessage}</p>}
            <p className="text-xs text-ink-muted">Las credenciales de Sandbox y de Producción se guardan por separado y nunca se mezclan: el entorno activo decide cuál se usa.</p>

            <form onSubmit={(event) => save(event, false)} className="grid gap-4 md:grid-cols-2">
                <div>
                    <InputLabel htmlFor="wompi_public_key" value={`Llave pública (${environment === 'production' ? 'pub_prod_…' : 'pub_test_…'})`} />
                    <TextInput id="wompi_public_key" className="mt-1" value={fields.public_key} onChange={set('public_key')} disabled={!wompi.canEdit} autoComplete="off" invalid={Boolean(errors.public_key)} />
                    <InputError message={errors.public_key} className="mt-1" />
                </div>
                {secret('private_key', 'Llave privada', current.privateKeySet, current.privateKeyHint)}
                {secret('events_secret', 'Secreto de eventos', current.eventsSecretSet, current.eventsSecretHint)}
                {secret('integrity_secret', 'Secreto de integridad', current.integritySecretSet, current.integritySecretHint)}

                {wompi.canEdit && (
                    <div className="flex flex-wrap gap-2 md:col-span-2">
                        <PrimaryButton type="submit" disabled={busy}>Guardar / actualizar credenciales</PrimaryButton>
                        <SecondaryButton type="button" onClick={validate} disabled={busy || current.state === 'incomplete'}>Probar conexión</SecondaryButton>
                        {!current.isActive && (
                            <SecondaryButton type="button" onClick={(event) => save(event, true)} disabled={busy}>Guardar y usar este entorno</SecondaryButton>
                        )}
                    </div>
                )}
            </form>

            <p className="text-xs text-ink-muted">
                URL de eventos que debes registrar en Wompi (una por entorno): <code>{wompi.eventsUrl}</code>. «Probar conexión» comprueba la llave pública contra la API oficial; los secretos de eventos e integridad
                solo se confirman con un evento y un pago de prueba reales.
            </p>

            <div className="rounded-lg border border-line bg-canvas/40 p-3 text-sm">
                <strong>Facturación (SIGO):</strong> <Badge tone="neutral">No conectada</Badge> Un pago aprobado no genera una factura: mientras SIGO no esté conectada, los pagos confirmados quedan «pendientes de facturación».
            </div>

            <PaymentHistory />
        </section>
    );
}

function PaymentHistory() {
    const [filters, setFilters] = useState({ q: '', status: '', environment: '', page: 1 });
    const [data, setData] = useState(null);
    const [detail, setDetail] = useState(null);

    useEffect(() => {
        const params = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
        axios.get(route('wompi-settings.history'), { params }).then((response) => setData(response.data)).catch(() => setData({ data: [], last_page: 1 }));
    }, [filters]);

    const open = (id) => axios.get(route('wompi-settings.detail', id)).then((response) => setDetail(response.data));
    const change = (name) => (event) => setFilters((previous) => ({ ...previous, [name]: event.target.value, page: 1 }));

    return (
        <Card className="space-y-3 p-4">
            <h4 className="text-sm font-bold text-ink">Historial de pagos</h4>
            <div className="flex flex-wrap gap-2">
                <TextInput aria-label="Buscar por referencia o transacción" placeholder="Referencia o transacción" value={filters.q} onChange={change('q')} className="max-w-xs" />
                <select aria-label="Estado" value={filters.status} onChange={change('status')} className="rounded-md border border-field bg-card px-2 py-2 text-sm">
                    <option value="">Todos los estados</option>
                    {['APPROVED', 'PENDING', 'DECLINED', 'VOIDED', 'ERROR'].map((status) => <option key={status} value={status}>{status}</option>)}
                </select>
                <select aria-label="Entorno" value={filters.environment} onChange={change('environment')} className="rounded-md border border-field bg-card px-2 py-2 text-sm">
                    <option value="">Ambos entornos</option>
                    <option value="sandbox">Sandbox</option>
                    <option value="production">Producción</option>
                </select>
            </div>

            {data === null ? (
                <p className="text-sm text-ink-muted">Cargando…</p>
            ) : data.data.length === 0 ? (
                <p className="py-4 text-center text-sm text-ink-muted">Aún no se ha recibido ningún pago.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">
                            <tr><th className="py-2 pr-3">Fecha</th><th className="pr-3">Referencia</th><th className="pr-3">Contratación</th><th className="pr-3">Importe</th><th className="pr-3">Estado</th><th className="pr-3">Entorno</th><th /></tr>
                        </thead>
                        <tbody className="divide-y divide-line">
                            {data.data.map((row) => (
                                <tr key={row.id}>
                                    <td className="py-2 pr-3 whitespace-nowrap">{when(row.date)}</td>
                                    <td className="pr-3 font-mono text-xs">{row.reference}</td>
                                    <td className="pr-3">{row.contract ?? '—'}</td>
                                    <td className="pr-3 whitespace-nowrap">{money(row.amountCents, row.currency)}</td>
                                    <td className="pr-3"><Badge tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status ?? row.eventStatus ?? 'sin verificar'}</Badge></td>
                                    <td className="pr-3">{ENV_LABEL[row.environment] ?? row.environment}</td>
                                    <td><button type="button" onClick={() => open(row.id)} className="text-xs font-semibold text-primary focus-visible:outline-2">Ver detalle</button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {data && data.last_page > 1 && (
                <div className="flex items-center justify-between text-sm">
                    <SecondaryButton type="button" disabled={filters.page <= 1} onClick={() => setFilters((p) => ({ ...p, page: p.page - 1 }))}>Anterior</SecondaryButton>
                    <span>Página {data.current_page} de {data.last_page}</span>
                    <SecondaryButton type="button" disabled={filters.page >= data.last_page} onClick={() => setFilters((p) => ({ ...p, page: p.page + 1 }))}>Siguiente</SecondaryButton>
                </div>
            )}

            {detail && (
                <div role="dialog" aria-label="Detalle del pago" className="rounded-lg border border-line bg-canvas/40 p-3 text-sm">
                    <div className="flex items-start justify-between">
                        <strong>Pago {detail.reference}</strong>
                        <button type="button" onClick={() => setDetail(null)} className="text-xs font-semibold text-primary">Cerrar</button>
                    </div>
                    <dl className="mt-2 grid gap-1 sm:grid-cols-2">
                        <div>Transacción: <span className="font-mono text-xs">{detail.transactionId}</span></div>
                        <div>Workspace: {detail.workspace ?? '—'}</div>
                        <div>Confirmado: {when(detail.confirmedAt)}</div>
                        <div>Resultado interno: {detail.outcome ?? '—'}</div>
                        <div>Factura: {detail.invoice === 'pending' ? 'Pendiente de facturación (SIGO no conectada)' : '—'}</div>
                    </dl>
                    <p className="mt-2 text-xs font-semibold text-ink-muted">Eventos recibidos</p>
                    <ul className="text-xs">
                        {detail.events.map((event, index) => <li key={index}>{when(event.at)} · evento {event.eventStatus ?? '—'} · verificado {event.verifiedStatus ?? '—'} · {event.outcome ?? 'pendiente'}</li>)}
                    </ul>
                </div>
            )}
        </Card>
    );
}
