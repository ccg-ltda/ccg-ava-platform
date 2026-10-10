import { router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useId, useState } from 'react';
import { useConfirm } from './ConfirmDialog';
import IconButton from './IconButton';
import InputError from './InputError';
import InputLabel from './InputLabel';
import Modal from './Modal';
import PrimaryButton from './PrimaryButton';
import SecondaryButton from './SecondaryButton';
import Select from './Select';
import { useToast } from './Toast';
import TextInput from './TextInput';

/** Criteria compared as text so `5` and `'5'` (a number from the URL, a string from the server) are the same filter. */
const fingerprint = (criteria) => JSON.stringify(Object.keys(criteria).sort().map((key) => [key, String(criteria[key])]));
const same = (a, b) => fingerprint(a) === fingerprint(b);

/**
 * Saved filters of one module (`scope`, see config/saved_filters.php): pick one to apply it, save the filters in use under a
 * name, update, rename or delete it. Shared by every search page; each page only says what its criteria are.
 *
 *  - `saved`: [{ id, name, criteria }] from the server (the user's own, in the active Workspace).
 *  - `criteria`: the filters in use right now, empty ones left out (what would be saved).
 *  - `resolve(criteria)`: checks a saved criteria against what the page can offer today and returns `{ criteria }` to apply
 *    or `{ error }` (a user or channel that no longer exists): nothing incomplete or incompatible is ever searched.
 *  - `onApply(criteria)`: fills the page's controls and runs the search.
 *
 * Changing the filters after choosing one never touches the saved one: the user gets an explicit "Actualizar" button.
 */
export default function SavedFilters({ scope, saved, criteria, resolve, onApply }) {
    const id = useId();
    const confirm = useConfirm();
    const toast = useToast();
    const [selectedId, setSelectedId] = useState(null);
    const [dialog, setDialog] = useState(null);
    const [name, setName] = useState('');
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const empty = Object.keys(criteria).length === 0;
    // A filter is "the one in use" if the user picked it, or if the filters in use are exactly one of the saved ones (after a reload).
    const active = (!empty && saved.find((filter) => filter.id === selectedId)) || saved.find((filter) => same(filter.criteria, criteria)) || null;
    const changed = Boolean(active) && !empty && !same(active.criteria, criteria);

    const choose = (value) => {
        const filter = saved.find((item) => String(item.id) === value);

        if (!filter) return setSelectedId(null);

        const result = resolve(filter.criteria);

        if (result.error) return toast.error(result.error);

        setSelectedId(filter.id);
        onApply(result.criteria);
    };

    const send = (method, url, data, onSuccess) =>
        router[method](url, data, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess,
            onError: setErrors,
        });

    const open = (kind) => {
        setName(kind === 'rename' ? active.name : '');
        setErrors({});
        setDialog(kind);
    };

    const submit = (event) => {
        event.preventDefault();

        if (dialog === 'rename') send('put', route('saved-filters.update', { scope, filter: active.id }), { name }, () => setDialog(null));
        else send('post', route('saved-filters.store', { scope }), { name, criteria }, () => setDialog(null));
    };

    const update = () => send('put', route('saved-filters.update', { scope, filter: active.id }), { criteria });

    const remove = async () => {
        const accepted = await confirm({ title: 'Eliminar filtro guardado', description: `Se eliminará «${active.name}». Los filtros que ves ahora no cambian, pero no podrás volver a elegir este guardado.`, confirmLabel: 'Eliminar' });

        if (accepted) send('delete', route('saved-filters.destroy', { scope, filter: active.id }));
    };

    const problem = errors.name ?? errors.criteria ?? Object.entries(errors).find(([key]) => key.startsWith('criteria.'))?.[1];

    return (
        <section aria-label="Filtros guardados" className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            <div className="min-w-0 sm:w-72">
                <InputLabel htmlFor={id} value="Filtros guardados" />
                {saved.length > 0 ? (
                    <Select
                        id={id}
                        className="mt-1"
                        value={active ? String(active.id) : ''}
                        onChange={choose}
                        placeholder="Elige un filtro guardado"
                        options={[{ value: '', label: 'Sin filtro guardado' }, ...saved.map((filter) => ({ value: String(filter.id), label: filter.name }))]}
                    />
                ) : (
                    <p className="mt-1 flex min-h-10 items-center text-sm text-ink-muted">Aún no tienes filtros guardados. Configura los filtros y pulsa «Guardar filtros».</p>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {changed && (
                    <PrimaryButton type="button" onClick={update} disabled={busy}>
                        Actualizar «{active.name}»
                    </PrimaryButton>
                )}
                <SecondaryButton type="button" onClick={() => open('create')} disabled={empty || busy} title={empty ? 'Configura al menos un filtro para guardarlo' : undefined}>
                    Guardar filtros
                </SecondaryButton>
                {active && (
                    <>
                        <IconButton icon={Pencil} label="Renombrar" context={active.name} onClick={() => open('rename')} disabled={busy} />
                        <IconButton icon={Trash2} label="Eliminar" context={active.name} tone="danger" onClick={remove} disabled={busy} />
                    </>
                )}
            </div>

            {changed && (
                <p role="status" className="text-xs font-medium text-accent-amber sm:basis-full">
                    Cambiaste los filtros de «{active.name}». El guardado no se modificó: usa «Actualizar» para conservar los cambios o «Guardar filtros» para crear otro.
                </p>
            )}

            <Modal show={dialog !== null} onClose={() => setDialog(null)} maxWidth="md">
                <form onSubmit={submit} className="p-6">
                    <h2 className="text-lg font-bold text-ink">{dialog === 'rename' ? 'Renombrar filtro' : 'Guardar filtros'}</h2>
                    <p className="mt-1 text-sm text-ink-muted">
                        {dialog === 'rename' ? 'Cambia solo el nombre; los criterios guardados se mantienen.' : 'Se guardan solo los criterios de búsqueda que tienes ahora, nunca los resultados. Solo tú los verás.'}
                    </p>

                    <div className="mt-4">
                        <InputLabel htmlFor={`${id}-name`} value="Nombre del filtro" />
                        <TextInput id={`${id}-name`} className="mt-1" value={name} onChange={(event) => setName(event.target.value)} maxLength={60} isFocused invalid={Boolean(problem)} placeholder="Por ejemplo: Cambios de la semana" autoComplete="off" />
                        <InputError message={problem} className="mt-1" />
                    </div>

                    <div className="mt-6 flex justify-end gap-3">
                        <SecondaryButton type="button" onClick={() => setDialog(null)}>
                            Cancelar
                        </SecondaryButton>
                        <PrimaryButton type="submit" disabled={busy || name.trim() === ''}>
                            {dialog === 'rename' ? 'Guardar nombre' : 'Guardar filtros'}
                        </PrimaryButton>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
