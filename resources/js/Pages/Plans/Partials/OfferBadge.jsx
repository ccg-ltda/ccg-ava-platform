import Badge from '@/Components/Badge';

const STATES = {
    online: { tone: 'green', label: 'Contratación en línea' },
    quote: { tone: 'blue', label: 'Por cotización' },
    incomplete: { tone: 'amber', label: 'Oferta incompleta' },
    private: { tone: 'neutral', label: 'No público' },
};

/**
 * Where a plan stands in the public sign-up, as the server computes it (Plan::offerState: the same rule the sign-up applies). «Oferta incompleta» means it is
 * marked public but is NOT shown to the public, and the reasons are in the tooltip.
 */
export default function OfferBadge({ offer, className = '' }) {
    const state = STATES[offer?.state] ?? STATES.private;
    const why = (offer?.issues ?? []).join(' ');

    return (
        <Badge tone={state.tone} className={className} title={why || undefined}>
            {state.label}
        </Badge>
    );
}
