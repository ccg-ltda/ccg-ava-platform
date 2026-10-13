import Alert from '@/Components/Alert';

/**
 * Said on every page of Servicios y Reservas whose data belongs to the Workspace's agenda: with an external agenda connected the
 * catalog is read from it (and edited there), and what that agenda cannot do through Ava is stated, never faked.
 */
export default function ExternalAgendaNotice({ capabilities, error, what }) {
    if (!capabilities.external) return null;

    return (
        <div className="space-y-3">
            <Alert tone="info">
                Este Workspace usa <strong>{capabilities.label}</strong> como agenda. {what} se leen de allí y se administran allí; Ava no mezcla esa agenda con la suya.
            </Alert>
            {!capabilities.connected && <Alert tone="warning">La agenda elegida no está conectada: revisa Integraciones (la integración debe estar configurada y activa y el plan debe incluir Proveedores de agenda). Mientras tanto no se ofrece ni se acepta ninguna reserva.</Alert>}
            {error && <Alert tone="warning">{error}</Alert>}
        </div>
    );
}
