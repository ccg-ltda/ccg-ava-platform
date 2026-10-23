import { Head } from '@inertiajs/react';
import { CalendarCheck, CheckCircle2, Clock, MapPin, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import CopyBlock from '@/Components/CopyBlock';
import DateField from '@/Components/DateField';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { dayLabel, getJson, postJson } from '@/config/bookings';

const api = (key, path = '') => `/api/booking/${key}${path}`;
const today = () => new Date().toISOString().slice(0, 10);

/** The free starts of a service, grouped by day; the visitor can only pick one the server offered. */
function Slots({ slots, picked, onPick, showProfessional }) {
    // With no professional chosen, one button per time is enough: the server assigns whoever is free.
    const offered = useMemo(() => (showProfessional ? slots.filter((slot, index) => slots.findIndex((other) => other.startsAt === slot.startsAt) === index) : slots), [slots, showProfessional]);
    const byDay = useMemo(() => offered.reduce((groups, slot) => ({ ...groups, [slot.date]: [...(groups[slot.date] ?? []), slot] }), {}), [offered]);

    return (
        <div className="max-h-96 space-y-4 overflow-y-auto pr-1">
            {Object.entries(byDay).map(([date, items]) => (
                <div key={date}>
                    <p className="text-xs font-semibold tracking-wide text-ink-muted uppercase">{dayLabel(date)}</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                        {items.map((slot) => {
                            const isPicked = picked?.startsAt === slot.startsAt && (showProfessional || picked?.professionalId === slot.professionalId);

                            return (
                                <button
                                    key={`${slot.startsAt}-${slot.professionalId}`}
                                    type="button"
                                    aria-pressed={isPicked}
                                    onClick={() => onPick(slot)}
                                    className={`min-h-11 rounded-lg border px-3 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary ${isPicked ? 'border-primary bg-primary text-white' : 'border-field bg-card text-ink hover:border-primary/50'}`}
                                >
                                    {slot.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

/** Finds a free start for a service: professional and first day, then the offered times. */
function SlotFinder({ keyName, config, serviceId, locationId, professionalId: fixedProfessional, picked, onPick, token }) {
    const [professionals, setProfessionals] = useState([]);
    const [professionalId, setProfessionalId] = useState(fixedProfessional ?? '');
    const [from, setFrom] = useState(today());
    const [state, setState] = useState({ loading: true, slots: [], error: null });

    useEffect(() => {
        getJson(api(keyName, '/professionals'), { service_id: serviceId, location_id: locationId }).then((body) => setProfessionals(body.professionals)).catch(() => setProfessionals([]));
    }, [serviceId, locationId]);

    useEffect(() => {
        let live = true;
        setState({ loading: true, slots: [], error: null });
        getJson(api(keyName, '/availability'), { service_id: serviceId, professional_id: professionalId || undefined, location_id: locationId, from, days: Math.min(7, config.maxDays), token })
            .then((body) => live && setState({ loading: false, slots: body.slots, error: null }))
            .catch((error) => live && setState({ loading: false, slots: [], error: error.message }));

        return () => {
            live = false;
        };
    }, [serviceId, professionalId, from, locationId]);

    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel htmlFor="pub_pro" value="Profesional" />
                    <Select id="pub_pro" className="mt-1" value={professionalId} onChange={(value) => { setProfessionalId(value); onPick(null); }} options={[{ value: '', label: 'Cualquiera disponible' }, ...professionals.map((p) => ({ value: p.id, label: p.name }))]} />
                </div>
                <div>
                    <DateField id="pub_from" label="Desde el día" clearable={false} min={today()} value={from} onChange={(value) => { setFrom(value || today()); onPick(null); }} />
                </div>
            </div>
            {state.loading && <p className="text-sm text-ink-muted" role="status">Buscando horarios…</p>}
            {state.error && <Alert tone="warning">{state.error}</Alert>}
            {!state.loading && !state.error && state.slots.length === 0 && <p className="text-sm text-ink-muted">No hay horarios libres en estos días. Prueba con otra fecha.</p>}
            {state.slots.length > 0 && <Slots slots={state.slots} picked={picked} onPick={(slot) => onPick(!professionalId ? { ...slot, professionalId: null } : slot)} showProfessional={!professionalId} />}
        </div>
    );
}

/** The booking made by a visitor, or an existing one opened with its link: see it, cancel it or move it. */
function Manage({ keyName, config, token, onDone }) {
    const [appointment, setAppointment] = useState(null);
    const [error, setError] = useState(null);
    const [moving, setMoving] = useState(false);
    const [slot, setSlot] = useState(null);
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);

    const load = useCallback(() => getJson(api(keyName, `/appointments/${token}`)).then((body) => setAppointment(body.appointment)).catch((e) => setError(e.message)), [keyName, token]);
    useEffect(() => { load(); }, [load]);

    const run = async (path, data) => {
        setBusy(true);
        setError(null);

        try {
            const body = await postJson(api(keyName, `/appointments/${token}/${path}`), data ?? {});
            setAppointment(body.appointment);
            setMoving(false);
            setConfirming(false);
            setSlot(null);
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };

    if (error && !appointment) return <Alert tone="warning">{error}</Alert>;
    if (!appointment) return <p className="text-sm text-ink-muted" role="status">Cargando tu reserva…</p>;

    return (
        <div className="space-y-5">
            <div className="flex items-start gap-3">
                {appointment.status === 'cancelled' ? <XCircle className="size-6 shrink-0 text-ink-muted" aria-hidden="true" /> : <CheckCircle2 className="size-6 shrink-0 text-accent-green" aria-hidden="true" />}
                <div>
                    <p className="text-lg font-bold text-ink">{appointment.service}</p>
                    <p className="text-sm text-ink-muted">{appointment.dateLabel} · {appointment.timeLabel}{appointment.professional && ` · ${appointment.professional}`}</p>
                    {appointment.location && (
                        <p className="mt-0.5 inline-flex items-center gap-1 text-sm text-ink-muted">
                            <MapPin className="size-3.5" aria-hidden="true" />
                            {appointment.location}
                        </p>
                    )}
                    {appointment.price !== null && appointment.price !== undefined && appointment.currency && (
                        <p className="text-sm text-ink-muted">Precio al reservar: {new Intl.NumberFormat('es', { style: 'currency', currency: appointment.currency }).format(appointment.price)}</p>
                    )}
                    <p className="mt-1 text-sm font-semibold text-ink">{appointment.statusLabel}</p>
                    {appointment.status === 'requested' && <p className="text-sm text-ink-muted">Tu solicitud llegó al negocio. Te confirmarán la cita.</p>}
                </div>
            </div>
            {error && <Alert tone="warning">{error}</Alert>}

            {moving && appointment.serviceId && (
                <div className="space-y-3 rounded-xl border border-line p-4">
                    <SlotFinder keyName={keyName} config={config} serviceId={appointment.serviceId} professionalId={appointment.professionalId} picked={slot} onPick={setSlot} token={token} />
                    <PrimaryButton type="button" disabled={!slot || busy} onClick={() => run('reschedule', { starts_at: slot.startsAt, professional_id: slot.professionalId })}>
                        Confirmar nuevo horario
                    </PrimaryButton>
                </div>
            )}

            <div className="flex flex-wrap gap-3">
                {appointment.canConfirm && !moving && <PrimaryButton type="button" disabled={busy} onClick={() => run('confirm')}>Confirmo que asistiré</PrimaryButton>}
                {appointment.customerConfirmed && appointment.status !== 'cancelled' && <span className="inline-flex items-center gap-1.5 text-sm font-medium text-accent-green"><CheckCircle2 className="size-4" aria-hidden="true" />Confirmaste tu asistencia</span>}
                {appointment.canChange && !moving && <SecondaryButton type="button" onClick={() => setMoving(true)}>Cambiar horario</SecondaryButton>}
                {appointment.canCancel && !confirming && <SecondaryButton type="button" onClick={() => setConfirming(true)}>Cancelar reserva</SecondaryButton>}
                {confirming && (
                    <span className="flex flex-wrap items-center gap-2 rounded-lg border border-danger/30 bg-danger/5 px-3 py-2 text-sm">
                        ¿Cancelar esta reserva?
                        <PrimaryButton type="button" disabled={busy} onClick={() => run('cancel')}>Sí, cancelar</PrimaryButton>
                        <SecondaryButton type="button" onClick={() => setConfirming(false)}>No</SecondaryButton>
                    </span>
                )}
                <SecondaryButton type="button" onClick={onDone}>Hacer otra reserva</SecondaryButton>
            </div>
            {!appointment.canCancel && appointment.status !== 'cancelled' && <p className="text-xs text-ink-muted">Ya no es posible cambiar esta reserva por aquí (se admite hasta {config.cancelNoticeHours} h antes). Comunícate con el negocio.</p>}
        </div>
    );
}

/**
 * The open booking page of a Workspace: choose a service, a time and leave a name and a contact. Everything it shows comes from
 * the public endpoints (only what a visitor needs); it can only ask for a start the agenda offered and it says what really
 * happened: "Por confirmar" is not "Confirmada". Mobile first.
 */
export default function Public({ publicKey: key, business, primaryColor }) {
    const [config, setConfig] = useState(null);
    const [services, setServices] = useState([]);
    const [loadError, setLoadError] = useState(null);
    const [serviceId, setServiceId] = useState('');
    const [locationId, setLocationId] = useState('');
    const [slot, setSlot] = useState(null);
    const [customer, setCustomer] = useState({ name: '', phone: '', email: '' });
    const [notes, setNotes] = useState('');
    const [website, setWebsite] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [token, setToken] = useState(new URLSearchParams(window.location.search).get('cita'));

    useEffect(() => {
        getJson(api(key, '/config')).then(setConfig).catch((e) => setLoadError(e.message));
    }, [key]);

    // With several locations the visitor chooses one first; with one it is implied. The services shown are the ones that location offers.
    const locations = config?.locations ?? [];
    const effectiveLocation = locationId || (locations.length === 1 ? String(locations[0].id) : '');
    const waitingForLocation = config && config.locationRequired && !effectiveLocation;

    useEffect(() => {
        if (!config || waitingForLocation) return;
        getJson(api(key, '/services'), { location_id: effectiveLocation || undefined }).then((body) => setServices(body.services)).catch((e) => setLoadError(e.message));
    }, [key, config, effectiveLocation, waitingForLocation]);

    const submit = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        try {
            const body = await postJson(api(key, '/appointments'), { service_id: serviceId, professional_id: slot.professionalId, location_id: effectiveLocation ? Number(effectiveLocation) : undefined, starts_at: slot.startsAt, customer, notes, website });
            window.history.replaceState({}, '', `${window.location.pathname}?cita=${body.token}`);
            setToken(body.token);
        } catch (e) {
            setError(e.message);

            if (e.reason === 'slot_unavailable') setSlot(null);
        } finally {
            setBusy(false);
        }
    };

    const reset = () => {
        window.history.replaceState({}, '', window.location.pathname);
        setToken(null);
        setSlot(null);
        setServiceId('');
        setLocationId('');
    };

    const service = services.find((item) => String(item.id) === String(serviceId));
    const link = token ? `${window.location.origin}${window.location.pathname}?cita=${token}` : null;

    return (
        <div className="min-h-dvh bg-canvas px-4 py-8 font-sans text-ink sm:py-12" style={primaryColor ? { '--color-primary': primaryColor } : undefined}>
            <Head title={`Reservar · ${business}`}>
                {/* The link to manage a booking is in the address: it must never travel to another site. */}
                <meta name="referrer" content="no-referrer" />
            </Head>
            <main className="mx-auto w-full max-w-2xl space-y-6">
                <header className="text-center">
                    <span className="mx-auto grid size-12 place-items-center rounded-2xl bg-primary/15 text-primary">
                        <CalendarCheck className="size-6" aria-hidden="true" />
                    </span>
                    <h1 className="mt-3 text-2xl font-extrabold tracking-tight sm:text-3xl">{business}</h1>
                    <p className="mt-1 text-sm text-ink-muted">Reserva tu cita en línea</p>
                </header>

                {loadError && <Alert tone="warning">{loadError}</Alert>}
                {!config && !loadError && <p className="text-center text-sm text-ink-muted" role="status">Cargando…</p>}

                {config && !config.canBook && !token && (
                    <Alert tone="info">Por ahora no es posible reservar en línea. Comunícate directamente con el negocio.</Alert>
                )}

                {token && config && (
                    <Card className="space-y-4 p-5 sm:p-7">
                        <div>
                            <p className="text-sm font-medium text-ink">Guarda este enlace para ver, cambiar o cancelar tu reserva:</p>
                            <CopyBlock className="mt-2" value={link} label="Enlace de tu reserva" message="Enlace copiado." />
                        </div>
                        <Manage keyName={key} config={config} token={token} onDone={reset} />
                    </Card>
                )}

                {!token && config?.canBook && (
                    <Card className="p-5 sm:p-7">
                        <form onSubmit={submit} noValidate className="space-y-6">
                            {locations.length > 0 && (
                                <div>
                                    <h2 className="text-base font-bold">{locations.length > 1 ? '1. Elige la sede' : 'Sede'}</h2>
                                    <div className="mt-3 grid gap-2">
                                        {locations.map((item) => (
                                            <label key={item.id} className="flex cursor-pointer items-start gap-3 rounded-xl border border-field p-3 transition-colors hover:border-primary/50 has-checked:border-primary has-checked:bg-primary-soft/50">
                                                <input type="radio" name="location" className="mt-1 size-4 accent-primary" checked={String(effectiveLocation) === String(item.id)} onChange={() => { setLocationId(String(item.id)); setServiceId(''); setSlot(null); }} />
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center gap-1.5 text-sm font-semibold">
                                                        <MapPin className="size-3.5 shrink-0 text-ink-muted" aria-hidden="true" />
                                                        {item.name}
                                                    </span>
                                                    {(item.address || item.city) && <span className="block text-xs text-ink-muted">{[item.address, item.city].filter(Boolean).join(', ')}</span>}
                                                    {item.notes && <span className="block text-xs text-ink-muted">{item.notes}</span>}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {!waitingForLocation && (
                            <div>
                                <h2 className="text-base font-bold">{locations.length > 1 ? '2. Elige el servicio' : '1. Elige el servicio'}</h2>
                                <div className="mt-3 grid gap-2">
                                    {services.length === 0 && <p className="text-sm text-ink-muted">Este negocio todavía no tiene servicios disponibles.</p>}
                                    {services.map((item) => (
                                        <label key={item.id} className="flex cursor-pointer items-start gap-3 rounded-xl border border-field p-3 transition-colors hover:border-primary/50 has-checked:border-primary has-checked:bg-primary-soft/50">
                                            <input type="radio" name="service" className="mt-1 size-4 accent-primary" checked={String(serviceId) === String(item.id)} onChange={() => { setServiceId(item.id); setSlot(null); }} />
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-sm font-semibold">{item.name}</span>
                                                {item.description && <span className="block text-xs text-ink-muted">{item.description}</span>}
                                                <span className="mt-1 inline-flex items-center gap-1 text-xs text-ink-muted">
                                                    <Clock className="size-3" aria-hidden="true" />
                                                    {item.durationMinutes ? `${item.durationMinutes} min` : 'Duración según el servicio'}
                                                    {item.price !== null && item.price !== undefined && ` · ${new Intl.NumberFormat('es', { style: 'currency', currency: config.currency }).format(item.price)}`}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                            )}

                            {service && (
                                <div>
                                    <h2 className="text-base font-bold">{locations.length > 1 ? '3. Elige día y hora' : '2. Elige día y hora'}</h2>
                                    <div className="mt-3">
                                        <SlotFinder keyName={key} config={config} serviceId={serviceId} locationId={effectiveLocation || undefined} picked={slot} onPick={setSlot} />
                                    </div>
                                </div>
                            )}

                            {slot && (
                                <div className="space-y-4">
                                    <h2 className="text-base font-bold">{locations.length > 1 ? '4. Tus datos' : '3. Tus datos'}</h2>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="sm:col-span-2">
                                            <InputLabel htmlFor="pub_name" value="Nombre" />
                                            <TextInput id="pub_name" className="mt-1" autoComplete="name" value={customer.name} maxLength={120} onChange={(e) => setCustomer({ ...customer, name: e.target.value })} />
                                        </div>
                                        <div>
                                            <InputLabel htmlFor="pub_phone" value={config.requirePhone ? 'Teléfono' : 'Teléfono (opcional)'} />
                                            <TextInput id="pub_phone" type="tel" className="mt-1" autoComplete="tel" placeholder="+573001112233" aria-describedby="pub_phone_hint" value={customer.phone} maxLength={32} onChange={(e) => setCustomer({ ...customer, phone: e.target.value })} />
                                            <p id="pub_phone_hint" className="mt-1 text-xs text-ink-muted">Con el prefijo de tu país, por ejemplo +573001112233.</p>
                                        </div>
                                        <div>
                                            <InputLabel htmlFor="pub_email" value={config.requireEmail ? 'Correo' : 'Correo (opcional)'} />
                                            <TextInput id="pub_email" type="email" className="mt-1" autoComplete="email" value={customer.email} maxLength={160} onChange={(e) => setCustomer({ ...customer, email: e.target.value })} />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <InputLabel htmlFor="pub_notes" value="Nota (opcional)" />
                                            <Textarea id="pub_notes" rows={2} className="mt-1" aria-describedby="pub_notes_hint" value={notes} maxLength={500} onChange={(e) => setNotes(e.target.value)} />
                                            <p id="pub_notes_hint" className="mt-1 text-xs text-ink-muted">Solo datos para organizar la cita. No escribas diagnósticos, síntomas ni información médica.</p>
                                        </div>
                                    </div>
                                    {/* Honeypot: invisible to people, tempting to bots. */}
                                    <div aria-hidden="true" className="absolute -left-[9999px] h-0 w-0 overflow-hidden">
                                        <label>
                                            Sitio web
                                            <input type="text" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
                                        </label>
                                    </div>
                                    {config.requiresConfirmation && <p className="text-xs text-ink-muted">Este negocio confirma cada reserva: quedará «Por confirmar» hasta que la acepte.</p>}
                                    {error && <Alert tone="warning">{error}</Alert>}
                                    <PrimaryButton type="submit" disabled={busy} className="w-full justify-center sm:w-auto">
                                        {busy ? 'Reservando…' : 'Solicitar reserva'}
                                    </PrimaryButton>
                                </div>
                            )}
                            {!slot && error && <Alert tone="warning">{error}</Alert>}
                        </form>
                    </Card>
                )}
            </main>
        </div>
    );
}
