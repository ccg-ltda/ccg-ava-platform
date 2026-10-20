import { CalendarDays, Clock, Coins, Globe } from 'lucide-react';
import { formatDate, formatMoney, formatTime } from '@/lib/format';

const CURRENCY_LOCALE = { COP: 'es-CO', DOP: 'es-DO', USD: 'en-US', EUR: 'es-ES' };

/** The separators a currency uses, read from Intl itself (the same engine that formats the amounts), so the explanation never disagrees with the numbers. */
function separators(currency) {
    const parts = new Intl.NumberFormat(CURRENCY_LOCALE[currency] ?? 'es-ES', { style: 'currency', currency }).formatToParts(1234567.89);

    return {
        group: parts.find((part) => part.type === 'group')?.value ?? '',
        decimal: parts.find((part) => part.type === 'decimal')?.value ?? '',
        symbol: parts.find((part) => part.type === 'currency')?.value ?? currency,
    };
}

const labelOf = (options, value) => options.find((option) => option.value === value)?.label ?? value;

/** A small titled box of the preview. */
function Tile({ icon: Icon, title, hint, children }) {
    return (
        <section className="flex h-full flex-col rounded-xl border border-line bg-card p-4 shadow-card sm:p-5">
            <h4 className="flex items-center gap-2 text-sm font-bold text-ink">
                <Icon className="size-4 text-accent-blue" aria-hidden="true" />
                {title}
            </h4>
            <div className="mt-3 flex-1">{children}</div>
            {hint && <p className="mt-3 border-t border-line pt-3 text-xs text-ink-muted">{hint}</p>}
        </section>
    );
}

const EXAMPLES = [
    { label: 'Precio de un servicio', amount: 49900 },
    { label: 'Total de un pedido', amount: 125000 },
    { label: 'Ventas del mes', amount: 1234567.89 },
];

/** Example hours of the day (08 to 16), drawn with the chosen hour format: they show the format only, they are not the business hours. */
const HOURS = [8, 10, 12, 14, 16];

/** Illustrative heights of the sample bars (percent of the box); they are not data of the business. */
const BARS = [38, 55, 46, 72, 90];

/**
 * A small graphic explanation of the regional settings: money (symbol and separators, with illustrative bars), date and time (a calendar tile and
 * the formats) and a row as the team would see it. It only formats example values with the given preferences: nothing is saved and no record is changed.
 */
export default function RegionalPreview({ currency, timezone, dateFormat, timeFormat, catalog }) {
    const now = new Date();
    const prefs = { timezone, dateFormat, timeFormat };
    const sep = separators(currency);
    const day = formatDate(now, { timezone, dateFormat: 'dmy' }).slice(0, 2);
    const month = new Intl.DateTimeFormat('es', { timeZone: timezone, month: 'short' }).format(now).replace('.', '');

    return (
        <div className="space-y-4">
            <div className="grid items-stretch gap-4 md:grid-cols-2">
                <Tile
                    icon={Coins}
                    title="Dinero"
                    hint={`${labelOf(catalog.currencies, currency)}: símbolo «${sep.symbol}», miles separados por «${sep.group || 'nada'}» y decimales con «${sep.decimal}».`}
                >
                    <ul className="space-y-1.5 text-sm">
                        {EXAMPLES.map(({ label, amount }) => (
                            <li key={label} className="flex items-baseline justify-between gap-3">
                                <span className="text-ink-muted">{label}</span>
                                <span className="font-semibold text-ink tabular-nums">{formatMoney(amount, currency)}</span>
                            </li>
                        ))}
                    </ul>
                    <div className="mt-4 flex items-end gap-2" role="img" aria-label="Barras de ejemplo con montos en la moneda elegida">
                        {BARS.map((height) => (
                            <div key={height} className="flex min-w-0 flex-1 flex-col justify-end gap-1">
                                <span className="truncate text-center text-[10px] text-ink-muted tabular-nums">{formatMoney(height * 1000, currency)}</span>
                                <div className="flex h-16 items-end">
                                    <div className="w-full rounded-t bg-primary/70" style={{ height: `${height}%` }} />
                                </div>
                            </div>
                        ))}
                    </div>
                    <p className="mt-1 text-[10px] text-ink-muted">Barras de ejemplo: no son datos de tu negocio.</p>
                </Tile>

                <Tile
                    icon={CalendarDays}
                    title="Fecha y hora"
                    hint={`La hora se calcula en ${labelOf(catalog.timezones, timezone)}. Cambiar un formato solo cambia cómo se muestra.`}
                >
                    <div className="flex items-center gap-4">
                        <div className="w-16 shrink-0 overflow-hidden rounded-lg border border-line text-center" aria-hidden="true">
                            <div className="bg-primary py-0.5 text-[10px] font-bold tracking-wider text-white uppercase">{month}</div>
                            <div className="py-1.5 text-2xl font-bold text-ink">{day}</div>
                        </div>
                        <dl className="min-w-0 space-y-1 text-sm">
                            <div className="flex items-baseline gap-2">
                                <dt className="w-12 shrink-0 text-ink-muted">Fecha</dt>
                                <dd className="font-semibold text-ink tabular-nums">{formatDate(now, prefs)}</dd>
                            </div>
                            <div className="flex items-baseline gap-2">
                                <dt className="w-12 shrink-0 text-ink-muted">Hora</dt>
                                <dd className="font-semibold text-ink tabular-nums">{formatTime(now, prefs)}</dd>
                            </div>
                        </dl>
                    </div>
                    <ul className="mt-4 grid grid-cols-5 gap-2" aria-label="Horas de ejemplo con el formato elegido">
                        {HOURS.map((hour) => (
                            <li key={hour} className="rounded-lg border border-line bg-canvas px-1 py-2 text-center text-[11px] font-semibold text-ink tabular-nums">
                                {formatTime(new Date(Date.UTC(2026, 0, 1, hour)), { timezone: 'UTC', timeFormat })}
                            </li>
                        ))}
                    </ul>
                    <p className="mt-1 text-[10px] text-ink-muted">Horas de ejemplo: no son tu horario.</p>
                </Tile>
            </div>

            <Tile icon={Globe} title="Así lo verá tu equipo">
                <div className="overflow-hidden rounded-lg border border-line text-xs">
                    <div className="grid grid-cols-[1fr_auto_auto] gap-x-3 bg-canvas px-3 py-1.5 font-semibold text-ink-muted">
                        <span>Pedido</span>
                        <span>Fecha</span>
                        <span className="text-right">Total</span>
                    </div>
                    <div className="grid grid-cols-[1fr_auto_auto] items-center gap-x-3 px-3 py-2 text-ink tabular-nums">
                        <span className="flex items-center gap-1.5">
                            <Clock className="size-3 text-accent-blue" aria-hidden="true" />#1042
                        </span>
                        <span>
                            {formatDate(now, prefs)} {formatTime(now, prefs)}
                        </span>
                        <span className="text-right font-semibold">{formatMoney(125000, currency)}</span>
                    </div>
                </div>
            </Tile>
        </div>
    );
}
