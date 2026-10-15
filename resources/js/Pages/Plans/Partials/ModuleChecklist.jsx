import { Link2 } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import ImplementationBadge from './ImplementationBadge';

/**
 * Selected modules that lack a module they technically need (`requires`, from config/modules.php). The server rejects the
 * same selection; this only explains it before saving. Returns `[{ module, missing: [{ id, key, name }] }]`.
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
 * The catalog regrouped as [{ group, categories: [{ ...category, modules }] }]: first the conceptual group of Ava (platform, shared
 * capabilities, commercial solutions) and inside it the organizational category. A group or category without modules is dropped.
 */
export function groupedCatalog(catalog) {
    const byGroup = new Map();

    catalog.forEach((category) =>
        category.modules.forEach((module) => {
            const entry = byGroup.get(module.group.key) ?? { group: module.group, categories: new Map() };
            const inCategory = entry.categories.get(category.id) ?? { ...category, modules: [] };
            inCategory.modules.push(module);
            entry.categories.set(category.id, inCategory);
            byGroup.set(module.group.key, entry);
        }),
    );

    return [...byGroup.values()].sort((a, b) => a.group.sort - b.group.sort).map((entry) => ({ group: entry.group, categories: [...entry.categories.values()] }));
}

/**
 * Modules to pick for a plan, grouped by Ava's conceptual groups and then by category. Controlled: `selected` is a list of module ids, `onChange(ids)`.
 * Pending modules can be included (they are reserved for the future) but are labelled as not working yet. A module that
 * was switched off can stay where it already is but cannot be added.
 */
export default function ModuleChecklist({ catalog, selected, onChange, error, disabled = false, idPrefix = 'module' }) {
    const groups = groupedCatalog(catalog);

    const toggle = (id) => onChange(selected.includes(id) ? selected.filter((value) => value !== id) : [...selected, id]);

    if (groups.length === 0) {
        return <p className="text-sm text-ink-muted">Todavía no hay módulos registrados en el catálogo.</p>;
    }

    const unmet = unmetRequirements(catalog, selected);

    return (
        <div className="space-y-5">
            {unmet.length > 0 && (
                <Alert tone="warning">
                    <p className="font-semibold">Este plan no se puede guardar así: faltan módulos de los que otros dependen.</p>
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
            {groups.map(({ group, categories }) => (
                <section key={group.key} className="space-y-4" aria-label={group.name}>
                    <h3 className="border-b border-line pb-1 text-sm font-bold text-ink">{group.name}</h3>
                    {categories.map((category) => (
                <fieldset key={category.id} className="min-w-0">
                    <legend className="text-xs font-semibold tracking-wider text-ink-muted uppercase">{category.name}</legend>
                    <ul className="mt-2 grid gap-2 lg:grid-cols-2">
                        {category.modules.map((module) => {
                            const checked = selected.includes(module.id);
                            const blocked = disabled || (!module.isActive && !checked);
                            const id = `${idPrefix}_${module.id}`;

                            return (
                                <li key={module.id}>
                                    <label
                                        htmlFor={id}
                                        className={`flex h-full gap-3 rounded-lg border p-3 transition-colors duration-150 ${checked ? 'border-primary/40 bg-primary-soft/50' : 'border-line bg-card'} ${blocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-primary/40'}`}
                                    >
                                        <Checkbox id={id} className="mt-0.5 shrink-0" checked={checked} disabled={blocked} onChange={() => toggle(module.id)} />
                                        <span className="min-w-0">
                                            <span className="block text-sm font-semibold text-ink">{module.name}</span>
                                            {module.description && <span className="mt-0.5 block text-xs text-ink-muted">{module.description}</span>}
                                            <span className="mt-2 flex flex-wrap gap-1.5">
                                                <ImplementationBadge implemented={module.implemented} />
                                                {!module.isActive && <Badge tone="neutral">Desactivado</Badge>}
                                                {module.requires.length > 0 && (
                                                    <Badge tone="blue">
                                                        <Link2 className="me-1 size-3" aria-hidden="true" />
                                                        Requiere {module.requires.map((required) => required.name).join(', ')}
                                                    </Badge>
                                                )}
                                            </span>
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                </fieldset>
                    ))}
                </section>
            ))}
            <InputError message={error} />
        </div>
    );
}
