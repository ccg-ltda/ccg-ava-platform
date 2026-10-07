import { router } from '@inertiajs/react';
import Alert from './Alert';
import InputLabel from './InputLabel';
import WorkspaceCombobox from './WorkspaceCombobox';

/**
 * Lets an administrator of the platform choose WHICH Workspace a page shows (reads only; the server decides and
 * revalidates). Renders nothing for anyone else. `scope` is the prop the server sends ({ workspace, canChoose }).
 */
export function WorkspaceViewSelector({ scope, routeName, id }) {
    if (!scope.canChoose) return null;

    const change = (option) => option && router.get(route(routeName), { workspace: option.id }, { preserveScroll: true });

    return (
        <div className="w-full sm:w-72">
            <InputLabel htmlFor={id} value="Workspace" className="sr-only" />
            <WorkspaceCombobox id={id} purpose="view" value={scope.workspace} onChange={change} placeholder="Buscar Workspace" />
        </div>
    );
}

/** Says, above a read-only page, whose data it shows. */
export function ReadOnlyWorkspaceNotice({ scope, what }) {
    if (!scope.readOnly) return null;

    return (
        <Alert tone="info">
            Estás consultando {what} de <strong>{scope.workspace.name}</strong> ({scope.workspace.code}). Es una vista de solo lectura: los cambios se hacen desde el propio Workspace.
        </Alert>
    );
}
