import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import SectionCard from './SectionCard';

/**
 * The STANDARD limits the plan sells, in their own card. Only limits the backend really enforces are listed (today: users per Workspace). Empty means the plan
 * sets none — it is NOT read as unlimited by a number, it simply enforces nothing. A particular limit of one Workspace is managed from that Workspace's
 * license page and wins over this standard; editing the standard changes every Workspace that inherits it.
 */
export default function LimitsSection({ form, commercial, ready = true }) {
    const limits = form.data.limits ?? {};

    return (
        <SectionCard id="plan-card-limits" title="Límites estándar" description="Lo que este plan permite por Workspace. Cada Workspace hereda estos valores salvo que CCG le autorice uno particular desde su licencia.">
            {!ready && <Alert tone="warning">Los límites todavía no se pueden guardar: falta aplicar la migración pendiente en esta base de datos.</Alert>}
            {form.errors.limits && <Alert tone="danger">{form.errors.limits}</Alert>}

            <div className={`grid gap-4 md:grid-cols-2 ${ready ? '' : 'pointer-events-none opacity-60'}`}>
                {commercial.metrics.map((metric) => (
                    <div key={metric.key}>
                        <InputLabel htmlFor={`plan_limit_${metric.key}`} value={`${metric.label} por Workspace`} />
                        <TextInput
                            id={`plan_limit_${metric.key}`}
                            className="mt-1"
                            type="number"
                            inputMode="numeric"
                            min={1}
                            step={1}
                            value={limits[metric.key] ?? ''}
                            placeholder="Sin límite definido"
                            onChange={(e) => form.setData('limits', { ...limits, [metric.key]: e.target.value })}
                            invalid={Boolean(form.errors[`limits.${metric.key}`])}
                        />
                        <p className="mt-1 text-xs text-ink-muted">{metric.help}</p>
                        <InputError message={form.errors[`limits.${metric.key}`]} className="mt-1" />
                    </div>
                ))}
            </div>
            <p className="text-xs text-ink-muted">Estos valores se congelan en cada suscripción al contratar: editarlos aquí solo afecta a nuevas contrataciones (y a suscripciones históricas sin condiciones registradas). Las excepciones y el consumo de cada Workspace se ven en su página de suscripción.</p>
        </SectionCard>
    );
}
