import { formatMoney } from '@/lib/format';

/** The prices a plan sells, one line per period; says plainly when it sells none. Mirrors what the public sign-up shows. */
export default function PriceSummary({ offer, className = '' }) {
    if (offer?.state === 'quote') {
        return <p className={`mt-3 text-sm font-semibold text-ink ${className}`}>Por cotización</p>;
    }

    const rows = [
        offer?.priceMonthly > 0 && { label: 'Mensual', amount: offer.priceMonthly },
        offer?.priceYearly > 0 && { label: 'Anual', amount: offer.priceYearly },
    ].filter(Boolean);

    if (rows.length === 0 || !offer.currency) {
        return <p className={`mt-3 text-sm text-ink-muted ${className}`}>Sin precio configurado</p>;
    }

    return (
        <ul className={`mt-3 space-y-1 ${className}`}>
            {rows.map((row) => (
                <li key={row.label} className="flex items-baseline justify-between gap-3 text-sm">
                    <span className="text-ink-muted">{row.label}</span>
                    <span className="font-semibold text-ink">
                        {formatMoney(row.amount, offer.currency)} <span className="text-xs font-normal text-ink-muted">{offer.currency}</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}
