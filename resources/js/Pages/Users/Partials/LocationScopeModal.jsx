import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import Alert from '@/Components/Alert';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

/**
 * Which locations (sedes) a member works with: all of them (the default) or exactly the ones ticked. The server decides what the member may reach and checks
 * every request again; an empty selection means NO location (never "all"), and the dialog says so before saving.
 */
export default function LocationScopeModal({ user, onClose }) {
    const [state, setState] = useState(null);
    const [scope, setScope] = useState('all');
    const [ids, setIds] = useState([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!user) return undefined;

        let cancelled = false;
        setState(null);
        setError(null);
        fetch(route('users.locations.show', user.id), { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(response)))
            .then((body) => {
                if (cancelled) return;
                setState(body);
                setScope(body.scope);
                setIds(body.locationIds);
            })
            .catch(() => !cancelled && setError('No se pudo cargar el alcance de sedes.'));

        return () => {
            cancelled = true;
        };
    }, [user?.id]);

    const toggle = (id) => setIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));

    const save = () => {
        setSaving(true);
        router.put(route('users.locations.update', user.id), { scope, location_ids: scope === 'selected' ? ids : [] }, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: (errors) => setError(Object.values(errors)[0] ?? 'No se pudo guardar.'),
            onFinish: () => setSaving(false),
        });
    };

    const locked = state && !state.mayChange;

    return (
        <Modal show={Boolean(user)} onClose={onClose} maxWidth="md">
            <div className="p-6">
                <h2 className="text-lg font-bold text-ink">Sedes de {user?.name}</h2>
                <p className="mt-1 text-sm text-ink-muted">Elige con qué sedes puede trabajar. Los permisos de su rol y los módulos del plan siguen decidiendo qué puede hacer en ellas.</p>

                {error && <Alert tone="error" className="mt-4">{error}</Alert>}
                {state && !state.ready && <Alert tone="warning" className="mt-4">El alcance por sede todavía no está disponible en esta base de datos (falta aplicar la migración pendiente): todos trabajan con todas las sedes.</Alert>}
                {locked && <Alert tone="warning" className="mt-4">Quien trabaja con sedes seleccionadas no puede cambiar el alcance de nadie.</Alert>}

                {state && (
                    <fieldset className="mt-4 space-y-3" disabled={!state.ready || locked}>
                        <label className="flex cursor-pointer items-start gap-2 text-sm text-ink">
                            <input type="radio" name="location_scope" className="mt-1 size-4 accent-primary" checked={scope === 'all'} onChange={() => setScope('all')} />
                            <span><strong>Todas las sedes</strong><span className="block text-xs text-ink-muted">Incluye las que se creen más adelante.</span></span>
                        </label>
                        <label className="flex cursor-pointer items-start gap-2 text-sm text-ink">
                            <input type="radio" name="location_scope" className="mt-1 size-4 accent-primary" checked={scope === 'selected'} onChange={() => setScope('selected')} />
                            <span><strong>Solo las sedes elegidas</strong><span className="block text-xs text-ink-muted">No ve ni modifica datos de otras sedes.</span></span>
                        </label>

                        {scope === 'selected' && (
                            <div className="ml-6 grid gap-2 sm:grid-cols-2">
                                {state.locations.length === 0 && <p className="text-sm text-ink-muted">Este Workspace todavía no tiene sedes.</p>}
                                {state.locations.map((location) => (
                                    <label key={location.id} className="flex cursor-pointer items-center gap-2 rounded-lg border border-field px-3 py-2 text-sm text-ink has-checked:border-primary/50 has-checked:bg-primary-soft/40">
                                        <input type="checkbox" className="size-4 accent-primary" checked={ids.includes(location.id)} onChange={() => toggle(location.id)} />
                                        <span>{location.name}{!location.isActive && <span className="ml-1 text-xs text-ink-muted">(inactiva)</span>}</span>
                                    </label>
                                ))}
                            </div>
                        )}

                        {scope === 'selected' && ids.length === 0 && state.ready && (
                            <Alert tone="warning" className="ml-6">Sin ninguna sede marcada, este usuario no podrá trabajar con datos de ninguna sede.</Alert>
                        )}
                    </fieldset>
                )}

                <div className="mt-6 flex justify-end gap-2">
                    <SecondaryButton type="button" onClick={onClose}>Cancelar</SecondaryButton>
                    <PrimaryButton type="button" onClick={save} disabled={!state || !state.ready || locked || saving}>{saving ? 'Guardando…' : 'Guardar'}</PrimaryButton>
                </div>
            </div>
        </Modal>
    );
}
