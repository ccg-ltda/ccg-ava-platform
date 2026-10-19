import { Check, Minus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import Pagination from '@/Components/Pagination';
import SearchInput from '@/Components/SearchInput';
import Select from '@/Components/Select';
import { TableWrap, Td, Th } from '@/Components/Table';
import { roleTone } from '@/config/roles';
import usePagedList from '@/Hooks/usePagedList';

/**
 * Permisos tab: read-only matrix of the permissions the system defines, by resource, and the roles that hold each. Permissions live in code, so they
 * cannot be created here; they are assigned to roles in the Roles tab (superusers). The resource filter and the search run on the server, so
 * the total and the pages follow them; nothing is truncated: each permission says what it allows and who has it.
 */
export default function PermissionsPanel({ roles, permissions, permissionCatalog, perPageOptions }) {
    const describe = (name) => permissionCatalog.items.find((item) => item.name === name);
    const groupLabel = (name) => permissionCatalog.groups[describe(name)?.group]?.label;
    const [query, setQuery] = useState(permissions.filters?.q ?? '');
    const [group, setGroup] = useState(permissions.filters?.group ?? '');
    const first = useRef(true);

    const list = usePagedList({
        tab: 'permisos',
        section: 'permissions',
        only: ['permissions'],
        meta: permissions.meta,
        perPageOptions,
        extra: { permission_group: group || undefined, permission_q: query || undefined },
    });

    // The text is searched after a short pause; the resource filter applies at once.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return undefined;
        }
        const timer = setTimeout(() => list.visit({ permission_group: group || undefined, permission_q: query || undefined }, { restart: true }), 300);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, group]);

    const groupOptions = [{ value: '', label: 'Todos los recursos' }, ...Object.entries(permissionCatalog.groups).map(([id, item]) => ({ value: id, label: item.label }))];
    const filtered = Boolean(group || query);

    return (
        <Card className="overflow-hidden">
            <div className="space-y-3 border-b border-line p-4 sm:px-6">
                <p className="text-sm text-ink-muted">
                    El sistema define <strong className="text-ink">{permissions.total}</strong> permisos, uno por operación de cada recurso, y todos son independientes. Cada rol los
                    recibe desde la pestaña Roles (solo superusuarios).
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    <SearchInput value={query} onChange={setQuery} placeholder="Buscar un permiso" label="Buscar permisos" className="min-w-0 flex-1 sm:max-w-sm" />
                    <Select className="w-full sm:w-64" aria-label="Filtrar por recurso" value={group} onChange={setGroup} options={groupOptions} />
                    <p role="status" className="text-xs text-ink-muted">
                        {filtered ? `${permissions.meta.total} de ${permissions.total} permisos` : `${permissions.total} permisos`}
                    </p>
                </div>
            </div>

            {permissions.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState title="Ningún permiso coincide" description="Cambia el recurso o el texto de la búsqueda." />
                </div>
            ) : (
                <TableWrap busy={list.loading}>
                    <thead>
                        <tr>
                            <Th>Permiso</Th>
                            {roles.map((role) => (
                                <Th key={role.id} className="text-center">
                                    <Badge tone={roleTone(role.name)}>{role.name}</Badge>
                                </Th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                        {permissions.data.map((permission) => (
                            <tr key={permission.name} className="transition-colors duration-150 hover:bg-canvas/70">
                                <Td className="text-ink">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="text-sm font-medium">{describe(permission.name)?.label ?? permission.name}</span>
                                        {groupLabel(permission.name) && (
                                            <Badge className="normal-case tracking-normal">{groupLabel(permission.name)}</Badge>
                                        )}
                                    </span>
                                    <span className="block font-mono text-[11px] text-ink-muted">{permission.name}</span>
                                    {describe(permission.name)?.description && <span className="mt-0.5 block max-w-md text-xs whitespace-normal text-ink-muted">{describe(permission.name).description}</span>}
                                </Td>
                                {roles.map((role) => {
                                    const granted = permission.roles.includes(role.name);

                                    return (
                                        <Td key={role.id} className="text-center">
                                            {granted ? (
                                                <Check className="mx-auto size-4 text-primary" aria-label={`${role.name} tiene ${permission.name}`} />
                                            ) : (
                                                <Minus className="mx-auto size-4 text-line" aria-label={`${role.name} no tiene ${permission.name}`} />
                                            )}
                                        </Td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}

            <Pagination noun="permisos" {...list.paginationProps} />
        </Card>
    );
}
