import { Check, Minus } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import Pagination from '@/Components/Pagination';
import { TableWrap, Td, Th } from '@/Components/Table';
import { roleTone } from '@/config/roles';
import usePagedList from '@/Hooks/usePagedList';

/**
 * Permisos tab: read-only matrix of the permissions the system defines and the roles that grant them.
 * Permissions live in code, so they cannot be created here; they are assigned to roles in the Roles tab.
 */
export default function PermissionsPanel({ roles, permissions, permissionCatalog, perPageOptions }) {
    const describe = (name) => permissionCatalog.items.find((item) => item.name === name);

    const list = usePagedList({ tab: 'permisos', section: 'permissions', only: ['permissions'], meta: permissions.meta, perPageOptions });

    return (
        <Card className="overflow-hidden">
            <p className="border-b border-line p-4 text-sm text-ink-muted sm:px-6">
                Estos permisos los define el sistema. Cada rol los recibe desde la pestaña Roles (solo superusuarios).
            </p>

            {permissions.data.length === 0 ? (
                <div className="p-6">
                    <EmptyState title="Sin permisos definidos" description="El sistema no tiene permisos registrados." />
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
                                    <span className="block text-sm font-medium">{describe(permission.name)?.label ?? permission.name}</span>
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
