import { Check, Minus } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import { TableWrap, Td, Th } from '@/Components/Table';
import { roleTone } from '@/config/roles';

/**
 * Permisos tab: read-only matrix of the permissions the system defines and the roles that grant them.
 * Permissions live in code, so they cannot be created here; they are assigned to roles in the Roles tab.
 */
export default function PermissionsPanel({ roles, permissions }) {
    return (
        <Card className="overflow-hidden">
            <p className="border-b border-line p-4 text-sm text-ink-muted sm:px-6">
                Estos permisos los define el sistema. Cada rol los recibe desde la pestaña Roles (solo superusuarios).
            </p>

            {permissions.length === 0 ? (
                <div className="p-6">
                    <EmptyState title="Sin permisos definidos" description="El sistema no tiene permisos registrados." />
                </div>
            ) : (
                <TableWrap>
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
                        {permissions.map((permission) => (
                            <tr key={permission} className="transition-colors duration-150 hover:bg-canvas/70">
                                <Td className="font-mono text-[13px] whitespace-nowrap text-ink">{permission}</Td>
                                {roles.map((role) => {
                                    const granted = role.permissions.includes(permission);

                                    return (
                                        <Td key={role.id} className="text-center">
                                            {granted ? (
                                                <Check className="mx-auto size-4 text-primary" aria-label={`${role.name} tiene ${permission}`} />
                                            ) : (
                                                <Minus className="mx-auto size-4 text-line" aria-label={`${role.name} no tiene ${permission}`} />
                                            )}
                                        </Td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </TableWrap>
            )}
        </Card>
    );
}
