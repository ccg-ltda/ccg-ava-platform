import { Globe, Lock, MessageSquareQuote } from 'lucide-react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PriceInput from '@/Components/PriceInput';
import Select from '@/Components/Select';
import { formatMoney } from '@/lib/format';
import SectionCard from './SectionCard';

const MODES = [
    { id: 'private', icon: Lock, title: 'No público', text: 'No aparece en el registro. Se puede asignar a mano a un Workspace.' },
    { id: 'online', icon: Globe, title: 'Contratación en línea', text: 'El cliente elige mensual o anual y paga en el registro. Necesita solución, moneda y al menos un precio.' },
    { id: 'quote', icon: MessageSquareQuote, title: 'Por cotización', text: 'El cliente solicita una propuesta. No hay cobro ni activación automática.' },
];

/** How the two switches of the server map to one choice for the person. */
const modeOf = (data) => (!data.is_public ? 'private' : data.requires_quote ? 'quote' : 'online');

/**
 * The commercial offer of a plan in its own card: how it is sold, the currency and the price per period. The solution is chosen in «Datos del plan».
 * Nothing is completed by itself: a period without a price is simply not sold, and the server refuses an incomplete online offer. When the database is
 * not ready for these fields (`ready` false) the card is read only and says so.
 */
export default function OfferSection({ form, commercial, ready = true }) {
    const mode = modeOf(form.data);
    const online = mode === 'online';
    const monthly = Number(form.data.price_monthly) || 0;
    const yearly = Number(form.data.price_yearly) || 0;
    const currency = form.data.currency;
    const perMonth = yearly > 0 ? yearly / 12 : 0;
    const saving = monthly > 0 && perMonth > 0 ? Math.round((1 - perMonth / monthly) * 100) : null;

    const choose = (id) => {
        form.setData((data) => ({ ...data, is_public: id !== 'private', requires_quote: id === 'quote' }));
    };

    return (
        <SectionCard id="plan-card-offer" title="Oferta comercial" description="Lo que el registro público muestra de este plan. La misma oferta puede venderse mensual y anual sin duplicar el plan.">
            {!ready && <Alert tone="warning">Estos campos no se pueden guardar todavía: faltan migraciones por aplicar en esta base de datos.</Alert>}
            {form.errors.offer && <Alert tone="danger">{form.errors.offer}</Alert>}

            <fieldset disabled={!ready} className={ready ? '' : 'opacity-60'}>
                <legend className="mb-2 text-sm font-semibold text-ink">¿Cómo se vende?</legend>
                <div className="grid gap-3 md:grid-cols-3" role="radiogroup" aria-label="Modalidad de contratación">
                    {MODES.map(({ id, icon: Icon, title, text }) => (
                        <label key={id} className={`flex cursor-pointer gap-3 rounded-xl border p-3 transition-colors focus-within:outline-2 focus-within:outline-primary ${mode === id ? 'border-primary bg-primary-soft' : 'border-line bg-card hover:border-primary/50'}`}>
                            <input type="radio" name="plan_mode" className="sr-only" checked={mode === id} onChange={() => choose(id)} />
                            <Icon className={`mt-0.5 size-5 shrink-0 ${mode === id ? 'text-primary' : 'text-ink-muted'}`} aria-hidden="true" />
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold text-ink">{title}</span>
                                <span className="mt-0.5 block text-xs text-ink-muted">{text}</span>
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>

            <div className={`grid gap-4 md:grid-cols-3 ${ready ? '' : 'pointer-events-none opacity-60'}`}>
                <div>
                    <InputLabel htmlFor="plan_currency" value="Moneda de los precios" />
                    <Select id="plan_currency" className="mt-1" value={currency ?? ''} onChange={(value) => form.setData('currency', value === '' ? null : value)} options={[{ value: '', label: 'Sin moneda' }, ...commercial.currencies]} />
                    <InputError message={form.errors.currency} className="mt-1" />
                </div>
                <div className={online ? '' : 'opacity-60'}>
                    <InputLabel htmlFor="plan_price_monthly" value="Precio mensual" />
                    <PriceInput id="plan_price_monthly" className="mt-1" value={form.data.price_monthly ?? ''} onChange={(value) => form.setData('price_monthly', value)} invalid={Boolean(form.errors.price_monthly)} disabled={mode === 'quote'} />
                    <InputError message={form.errors.price_monthly} className="mt-1" />
                </div>
                <div className={online ? '' : 'opacity-60'}>
                    <InputLabel htmlFor="plan_price_yearly" value="Precio anual (total del año)" />
                    <PriceInput id="plan_price_yearly" className="mt-1" value={form.data.price_yearly ?? ''} onChange={(value) => form.setData('price_yearly', value)} invalid={Boolean(form.errors.price_yearly)} disabled={mode === 'quote'} />
                    <InputError message={form.errors.price_yearly} className="mt-1" />
                </div>
            </div>
            <p className="text-xs text-ink-muted" role="status">
                {mode === 'quote'
                    ? 'Un plan por cotización no tiene precio en línea.'
                    : monthly <= 0 && yearly <= 0
                      ? 'Deja vacío el periodo que no quieras vender. Con ambos vacíos el plan no se puede contratar en línea.'
                      : [
                            monthly > 0 && currency ? `Mensual: ${formatMoney(monthly, currency)} ${currency}` : null,
                            yearly > 0 && currency ? `Anual: ${formatMoney(yearly, currency)} ${currency}${perMonth > 0 ? ` (equivale a ${formatMoney(perMonth, currency)} al mes${saving !== null && saving > 0 ? `, ${saving}% menos que el mensual` : ''})` : ''}` : null,
                            (monthly > 0 || yearly > 0) && !currency ? 'Elige la moneda para que el precio sea válido.' : null,
                        ].filter(Boolean).join(' · ')}
            </p>
        </SectionCard>
    );
}
