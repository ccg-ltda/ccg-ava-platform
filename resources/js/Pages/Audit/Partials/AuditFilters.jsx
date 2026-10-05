import { FilterX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Card from '@/Components/Card';
import InputLabel from '@/Components/InputLabel';
import SearchInput from '@/Components/SearchInput';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import WorkspaceCombobox, { ALL_WORKSPACES } from '@/Components/WorkspaceCombobox';

const ALL = '';

const withAll = (label, options) => [{ value: ALL, label }, ...options];

/**
 * Filters of Auditoría. Everything except the search applies at once; the search waits for a pause in typing.
 * `onChange(partial)` runs the query; the server returns the filters it used, which are the source of truth.
 */
export default function AuditFilters({ filters, options, scope, onChange, onClear, active }) {
    const [search, setSearch] = useState(filters.search);
    const first = useRef(true);

    useEffect(() => setSearch(filters.search), [filters.search]);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return undefined;
        }

        if (search === filters.search) return undefined;

        const timer = setTimeout(() => onChange({ search }), 350);

        return () => clearTimeout(timer);
    }, [search]);

    const value = (key) => filters[key] ?? ALL;

    return (
        <Card className="p-5 sm:p-6">
            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-6">
                <div className="sm:col-span-2 lg:col-span-6">
                    <SearchInput value={search} onChange={setSearch} placeholder="Buscar por usuario, registro, descripción o IP" label="Buscar en la auditoría" />
                </div>

                <div className="lg:col-span-1">
                    <InputLabel htmlFor="audit_from" value="Desde" />
                    <TextInput id="audit_from" type="date" className="mt-1" value={value('from')} max={filters.to ?? undefined} onChange={(e) => onChange({ from: e.target.value })} />
                </div>
                <div className="lg:col-span-1">
                    <InputLabel htmlFor="audit_to" value="Hasta" />
                    <TextInput id="audit_to" type="date" className="mt-1" value={value('to')} min={filters.from ?? undefined} onChange={(e) => onChange({ to: e.target.value })} />
                </div>
                <div className="lg:col-span-2">
                    <InputLabel htmlFor="audit_user" value="Usuario" />
                    <Select id="audit_user" className="mt-1" value={value('user') === ALL ? ALL : String(filters.user)} onChange={(user) => onChange({ user })} options={withAll('Todos los usuarios', options.users)} />
                </div>
                <div className="lg:col-span-2">
                    <InputLabel htmlFor="audit_resource" value="Módulo" />
                    <Select id="audit_resource" className="mt-1" value={value('resource')} onChange={(resource) => onChange({ resource })} options={withAll('Todos los módulos', options.resources)} />
                </div>

                {options.canChoose && (
                    <div className="sm:col-span-2 lg:col-span-3">
                        <InputLabel htmlFor="audit_workspace" value="Workspace" />
                        <WorkspaceCombobox
                            id="audit_workspace"
                            purpose="view"
                            includeAll
                            className="mt-1"
                            value={scope.mode === 'all' ? ALL_WORKSPACES : scope.workspace}
                            onChange={(option) => option && onChange({ workspace: String(option.id), user: ALL })}
                        />
                    </div>
                )}

                <div className={`sm:col-span-2 ${options.canChoose ? 'lg:col-span-3' : 'lg:col-span-6'}`}>
                    <p className="text-sm font-medium text-ink">Acción</p>
                    <div className="mt-1 flex flex-wrap gap-2" role="group" aria-label="Filtrar por acción">
                        {withAll('Todos', options.actions).map((action) => {
                            const selected = value('action') === action.value;

                            return (
                                <button
                                    key={action.value}
                                    type="button"
                                    aria-pressed={selected}
                                    onClick={() => onChange({ action: action.value })}
                                    className={`rounded-lg border px-3.5 py-2 text-xs font-semibold tracking-wider uppercase transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary ${
                                        selected ? 'border-primary bg-primary text-white' : 'border-field bg-card text-ink hover:bg-canvas'
                                    }`}
                                >
                                    {action.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>

            {active && (
                <div className="mt-5 flex justify-end border-t border-line pt-4">
                    <SecondaryButton type="button" onClick={onClear} className="gap-2">
                        <FilterX className="size-4" aria-hidden="true" />
                        Limpiar filtros
                    </SecondaryButton>
                </div>
            )}
        </Card>
    );
}
