import { Check, Circle, FileText, Landmark } from 'lucide-react';
import Badge from '@/Components/Badge';

/** The three stages Ava can honestly tell apart: only the first one exists today. */
const STAGES = [
    { id: 'config', label: 'Configuración', done: true },
    { id: 'prep', label: 'Preparación', done: false },
    { id: 'active', label: 'Integración activa', done: false },
];

function Stage({ stage }) {
    const Icon = stage.done ? Check : Circle;

    return (
        <li className="flex items-center gap-2 text-sm">
            <span className={`grid size-5 shrink-0 place-items-center rounded-full ${stage.done ? 'bg-accent-green/15 text-accent-green' : 'bg-canvas text-ink-muted'}`}>
                <Icon className="size-3" aria-hidden="true" />
            </span>
            <span className={stage.done ? 'font-medium text-ink' : 'text-ink-muted'}>{stage.label}</span>
            <span className="ms-auto text-xs text-ink-muted">{stage.done ? 'Hecha' : stage.id === 'prep' ? 'Sin iniciar' : 'No disponible'}</span>
        </li>
    );
}

/** One protagonist card: icon, title, state, what it is and its actions. */
function FiscalCard({ icon: Icon, title, badge, children, footer }) {
    return (
        <section className="flex h-full flex-col rounded-2xl border border-line bg-card p-5 shadow-card sm:p-6">
            <div className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-primary/10 text-primary">
                    <Icon className="size-6" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-bold text-ink">{title}</h3>
                    <div className="mt-1">{badge}</div>
                </div>
            </div>
            <div className="mt-4 flex-1 space-y-3 text-sm text-ink-muted">{children}</div>
            {footer && <div className="mt-5 border-t border-line pt-4">{footer}</div>}
        </section>
    );
}

/**
 * The two protagonist cards of Impuestos y facturación: the fiscal authority of the chosen country and its electronic invoicing. They describe the
 * country from config/workspace.php and say plainly what exists: Ava has no invoicing integration, so nothing here is reported as connected and the only
 * stage that is done is the configuration of the country. The same cards are used in the summary and under the form.
 */
export default function FiscalCards({ country }) {
    if (!country) return null;

    const isColombia = country.code === 'CO';

    return (
        <div className="grid gap-5 md:grid-cols-2">
            <FiscalCard
                icon={Landmark}
                title="Autoridad fiscal"
                badge={<Badge tone="blue">Informativa</Badge>}
                footer={<p className="text-xs text-ink-muted">Es información de referencia del país elegido; Ava no se comunica con esta entidad.</p>}
            >
                <p className="text-2xl leading-none" aria-hidden="true">
                    {country.flag}
                </p>
                <p className="text-base font-semibold text-ink">{country.authority}</p>
                <p>
                    Es la entidad ante la que tu negocio declara impuestos en {country.name}.
                </p>
                {country.note && <p className="rounded-lg bg-canvas p-3 text-xs">{country.note}</p>}
            </FiscalCard>

            <FiscalCard
                icon={FileText}
                title={isColombia ? 'Facturación electrónica de la DIAN' : 'Facturación electrónica'}
                badge={<Badge tone="amber">Integración no disponible</Badge>}
                footer={
                    <ol className="space-y-2" aria-label="Estado de la facturación electrónica">
                        {STAGES.map((stage) => (
                            <Stage key={stage.id} stage={stage} />
                        ))}
                    </ol>
                }
            >
                <p className="text-base font-semibold text-ink">{country.system}</p>
                <p>
                    {isColombia
                        ? 'Es el sistema con el que la DIAN valida las facturas electrónicas.'
                        : `Es el sistema de facturación electrónica que corresponde a ${country.name}.`}{' '}
                    Ava todavía no emite ni envía facturas: esta integración aún no está implementada, así que ninguna factura se está reportando.
                </p>
            </FiscalCard>
        </div>
    );
}
