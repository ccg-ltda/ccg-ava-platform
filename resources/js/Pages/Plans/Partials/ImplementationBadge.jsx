import Badge from '@/Components/Badge';

/** Whether a module really works today (declared in config/modules.php) or is only registered for the future. */
export default function ImplementationBadge({ implemented, quiet = false }) {
    if (quiet && implemented) return null; // a working module needs no label on every card; only what does NOT work yet is worth saying

    return implemented ? <Badge tone="green">Implementado</Badge> : <Badge tone="amber">Pendiente de implementación</Badge>;
}
