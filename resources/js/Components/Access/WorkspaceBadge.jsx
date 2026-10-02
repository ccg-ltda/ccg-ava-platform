import { router } from '@inertiajs/react';

/** Shows the Workspace chosen in the pre-login step and lets the user pick another one. */
export default function WorkspaceBadge({ code }) {
    return (
        <div className="workspace-badge">
            <span>
                Workspace <strong>{code}</strong>
            </span>
            <button type="button" onClick={() => router.delete(route('pre-login.reset'))}>
                Cambiar
            </button>
        </div>
    );
}
