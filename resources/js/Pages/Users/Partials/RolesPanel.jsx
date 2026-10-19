import { Eye, Lock, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import IconButton from '@/Components/IconButton';
import Pagination from '@/Components/Pagination';
import PrimaryButton from '@/Components/PrimaryButton';
import { TableWrap, Td, Th } from '@/Components/Table';
import { roleTone } from '@/config/roles';
import usePagedList from '@/Hooks/usePagedList';
import { summarizeRole } from '@/lib/permissions';
import RoleEditor from './RoleEditor';

const VISIBLE_AREAS = 4;

/** What a role can do, readable: one chip per resource with how many of its permissions it holds ("Clientes 3/6"). */
function RoleScope({ role, catalog }) {
    const areas = summarizeRole(catalog, role.permissions);

    if (areas.length === 0) return <span className="text-xs text-ink-muted">Sin permisos</span>;

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {areas.slice(0, VISIBLE_AREAS).map((area) => (
                <Badge key={area.id} tone={area.granted === area.total ? 'blue' : 'neutral'} className="normal-case tracking-normal">
                    <span title={`${area.granted} de ${area.total} permisos de ${area.label}`}>
                        {area.label} {area.granted}/{area.total}
                    </span>
                </Badge>
            ))}
            {areas.length > VISIBLE_AREAS && <span className="text-xs text-ink-muted">+{areas.length - VISIBLE_AREAS} áreas</span>}
            <span className="text-xs text-ink-muted">· {role.permissions.length} permisos</span>
        </div>
    );
}

/**
 * Roles tab: the global role catalog. Everyone who manages users sees it and can open the full detail of a role; only superusers
 * (`canManage`) can create roles or change their permissions (roles are never deleted), because the catalog is shared by every Workspace.
 * The server enforces all of it.
 */
export default function RolesPanel({ roles, permissionCatalog, perPageOptions, canManage }) {
    const [editor, setEditor] = useState(null); // { mode: 'create' | 'edit' | 'view', role? }

    const list = usePagedList({ tab: 'roles', section: 'roles', only: ['roles'], meta: roles.meta, perPageOptions });

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4 sm:px-6">
                <p className="max-w-2xl text-sm text-ink-muted">
                    Los roles son compartidos por todos los Workspaces.{' '}
                    {canManage ? 'Como superusuario puedes crearlos y ajustar sus permisos.' : 'Solo un superusuario puede modificarlos; todos pueden consultar su detalle.'}
                </p>
                {canManage && (
                    <PrimaryButton type="button" onClick={() => setEditor({ mode: 'create' })} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nuevo rol
                    </PrimaryButton>
                )}
            </div>

            <TableWrap busy={list.loading}>
                <thead>
                    <tr>
                        <Th>Rol</Th>
                        <Th>Alcance</Th>
                        <Th>Usuarios aquí</Th>
                        <Th className="text-center">Acciones</Th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-line">
                    {roles.data.map((role) => (
                        <tr key={role.id} className="transition-colors duration-150 hover:bg-canvas/70">
                            <Td className="whitespace-nowrap">
                                <span className="flex items-center gap-2">
                                    <Badge tone={roleTone(role.name)}>{role.name}</Badge>
                                    {role.protected && <Lock className="size-3.5 text-ink-muted" aria-label="Rol del sistema" />}
                                </span>
                            </Td>
                            <Td>
                                <RoleScope role={role} catalog={permissionCatalog} />
                            </Td>
                            <Td className="text-ink-muted">{role.usersInWorkspace}</Td>
                            <Td>
                                <div className="flex items-center justify-center gap-1">
                                    <IconButton icon={Eye} label="Ver permisos" context={role.name} onClick={() => setEditor({ mode: 'view', role })} />
                                    {canManage &&
                                        (role.protected ? (
                                            <span className="text-xs text-ink-muted">Rol del sistema</span>
                                        ) : (
                                            <IconButton icon={Pencil} label="Editar permisos" context={role.name} onClick={() => setEditor({ mode: 'edit', role })} />
                                        ))}
                                </div>
                            </Td>
                        </tr>
                    ))}
                </tbody>
            </TableWrap>

            <Pagination noun="roles" {...list.paginationProps} />

            {/* One editor at a time, re-created for each role and mode so its form never keeps another role's selection. */}
            <RoleEditor
                key={editor ? `${editor.mode}-${editor.role?.id ?? 'new'}` : 'closed'}
                show={Boolean(editor)}
                mode={editor?.mode ?? 'view'}
                role={editor?.role}
                catalog={permissionCatalog}
                onClose={() => setEditor(null)}
            />
        </Card>
    );
}
