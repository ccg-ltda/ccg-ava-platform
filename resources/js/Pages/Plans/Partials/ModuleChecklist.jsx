import { Link2 } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import ImplementationBadge from './ImplementationBadge';

/**
 * Selected modules that lack a module they technically need (`requires`, from config/modules.php). It is only a warning (decision E4): the plan can be saved and
 * ModuleAccess keeps those modules inoperative. Returns `[{ module, missing: [{ id, key, name }] }]`.
 */
export function unmetRequirements(catalog, selected) {
    const modules = catalog.flatMap((category) => category.modules);
    const chosen = modules.filter((module) => selected.includes(module.id));
    const chosenKeys = chosen.map((module) => module.key);

    return chosen
        .map((module) => ({
            module,
            missing: module.requires.filter((required) => !chosenKeys.includes(required.key)).map((required) => ({ ...required, id: modules.find((item) => item.key === required.key)?.id })),
        }))
        .filter((unmet) => unmet.missing.length > 0);
}

/**
 * The catalog regrouped by the structure of the application menu: `[{ section: { key, name, description, sort }, modules: [...] }]`, in the order of the
 * menu (General, Operación, Administración). The server sends each module's section (config/modules.php `menu`, the one the sidebar follows), so
 * nothing here classifies modules. Each module keeps its own category as `category`; a section without modules is dropped.
 */
export function modulesBySection(catalog) {
    const sections = new Map();

    catalog.forEach((category) =>
        category.modules.forEach((module) => {
            const entry = sections.get(module.section.key) ?? { section: module.section, modules: [] };
            entry.modules.push({ ...module, category: { id: category.id, name: category.name } });
            sections.set(module.section.key, entry);
        }),
    );

    return [...sections.values()].sort((a, b) => a.section.sort - b.section.sort).map((entry) => ({ ...entry, modules: entry.modules.sort((a, b) => a.name.localeCompare(b.name, 'es')) }));
}

/** One module as a compact card: name, short description, what it is and what it needs, and a clear selection box. */
function ModuleCard({ module, checked, blocked, id, onToggle }) {
    return (
        <label
            htmlFor={id}
            className={`flex h-full gap-3 rounded-lg border p-3 transition-colors duration-150 ${checked ? 'border-primary/40 bg-primary-soft/50' : 'border-line bg-card'} ${blocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-primary/40'} has-focus-visible:outline-2 has-focus-visible:outline-primary`}
        >
            <Checkbox id={id} className="mt-0.5 shrink-0" checked={checked} disabled={blocked} onChange={onToggle} />
            <span className="min-w-0">
                <span className="block text-sm font-semibold text-ink">{module.name}</span>
                {module.description && <span className="mt-0.5 line-clamp-3 block text-xs text-ink-muted">{module.description}</span>}
                <span className="mt-2 flex flex-wrap gap-1.5">
                    <ImplementationBadge implemented={module.implemented} />
                    {!module.isActive && <Badge tone="neutral">Desactivado</Badge>}
                    <Badge tone="neutral" className="normal-case tracking-normal">
                        {module.category.name}
                    </Badge>
                    {module.alsoFor.length > 0 && (
                        <Badge tone="violet" className="normal-case tracking-normal">
                            También en {module.alsoFor.map((solution) => solution.name).join(', ')}
                        </Badge>
                    )}
                    {module.requires.length > 0 && (
                        <Badge tone="blue" className="normal-case tracking-normal">
                            <Link2 className="me-1 size-3" aria-hidden="true" />
                            Requiere {module.requires.map((required) => required.name).join(', ')}
                        </Badge>
                    )}
                </span>
                {module.hint && <span className="mt-2 block text-[11px] text-ink-muted italic">{module.hint}</span>}
            </span>
        </label>
    );
}

/**
 * Modules to pick for a plan, organized like the application menu (General, Operación, Administración) in a compact grid of up to three columns.
 * Controlled: `selected` is a list of module ids, `onChange(ids)`. Pending modules can be included (they are reserved for the future) but are labelled as
 * not working yet. A module that was switched off can stay where it already is but cannot be added. The selection of the plan is independent of the
 * permissions of the roles: a module in the plan only exists for the Workspace; what each person does in it is decided by their role.
 */
export default function ModuleChecklist({ catalog, selected, onChange, error, disabled = false, idPrefix = 'module' }) {
    const sections = modulesBySection(catalog);

    const toggle = (id) => onChange(selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);
    const setMany = (ids, on) => onChange(on ? [...new Set([...selected, ...ids])] : selected.filter((id) => !ids.includes(id)));

    if (sections.length === 0) {
        return <p className="text-sm text-ink-muted">Todavía no hay módulos registrados en el catálogo.</p>;
    }

    const unmet = unmetRequirements(catalog, selected);

    return (
        <div className="space-y-6">
            {unmet.length > 0 && (
                <Alert tone="warning">
                    <p className="font-semibold">Faltan módulos de los que otros dependen. El plan se puede guardar así, pero esos módulos no funcionarán (ni se podrá asignar el plan) hasta completarlo.</p>
                    <ul className="mt-1 space-y-1.5">
                        {unmet.map(({ module, missing }) => (
                            <li key={module.id} className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span>
                                    «{module.name}» necesita {missing.map((required) => `«${required.name}»`).join(', ')} para funcionar.
                                </span>
                                {!disabled && (
                                    <button type="button" onClick={() => onChange([...selected, ...missing.map((required) => required.id).filter((id) => id && !selected.includes(id))])} className="font-semibold underline underline-offset-2">
                                        Añadir {missing.map((required) => required.name).join(', ')}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                </Alert>
            )}

            {sections.map(({ section, modules }) => {
                const selectable = modules.filter((module) => module.isActive || selected.includes(module.id)).map((module) => module.id);
                const count = modules.filter((module) => selected.includes(module.id)).length;

                return (
                    <section key={section.key} aria-label={section.name} className="space-y-3">
                        <div className="flex flex-wrap items-end justify-between gap-2 border-b border-line pb-1.5">
                            <div className="min-w-0">
                                <h3 className="text-sm font-bold text-ink">
                                    {section.name} <span className="ml-1 text-xs font-medium text-ink-muted">{count}/{modules.length}</span>
                                </h3>
                                {section.description && <p className="text-xs text-ink-muted">{section.description}</p>}
                            </div>
                            {!disabled && (
                                <span className="flex gap-2">
                                    <button type="button" onClick={() => setMany(selectable, true)} disabled={count === selectable.length} className="rounded-md px-2 py-1 text-xs font-semibold text-primary hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40">
                                        Marcar los de {section.name}
                                    </button>
                                    <button type="button" onClick={() => setMany(modules.map((module) => module.id), false)} disabled={count === 0} className="rounded-md px-2 py-1 text-xs font-semibold text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-40">
                                        Quitar
                                    </button>
                                </span>
                            )}
                        </div>
                        <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {modules.map((module) => {
                                const checked = selected.includes(module.id);
                                const blocked = disabled || (!module.isActive && !checked);

                                return (
                                    <li key={module.id}>
                                        <ModuleCard module={module} checked={checked} blocked={blocked} id={`${idPrefix}_${module.id}`} onToggle={() => toggle(module.id)} />
                                    </li>
                                );
                            })}
                        </ul>
                    </section>
                );
            })}
            <InputError message={error} />
        </div>
    );
}
