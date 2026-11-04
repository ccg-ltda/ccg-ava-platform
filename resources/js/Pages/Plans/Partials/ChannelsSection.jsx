import Alert from '@/Components/Alert';
import Checkbox from '@/Components/Checkbox';
import SectionCard from './SectionCard';

/**
 * The communication channels the plan includes: a capability independent of the modules. A Workspace can configure and use only the channels its plan includes (the server checks it on every
 * operation); a channel left out keeps its credentials and conversations and works again when a plan includes it. The administrative Workspace always has every channel.
 */
export default function ChannelsSection({ form, commercial, ready = true }) {
    const selected = form.data.channel_keys ?? [];
    const toggle = (key) => form.setData('channel_keys', selected.includes(key) ? selected.filter((item) => item !== key) : [...selected, key]);

    return (
        <SectionCard id="plan-card-channels" title="Canales disponibles" description="Los canales que el Workspace suscriptor podrá configurar. Son independientes de los módulos: un canal sin su módulo (Asistentes, Conversaciones) tampoco opera.">
            {!ready && <Alert tone="warning">Los canales todavía no se pueden guardar: falta aplicar la migración pendiente en esta base de datos.</Alert>}
            {form.errors.channel_keys && <Alert tone="danger">{form.errors.channel_keys}</Alert>}
            <ul className={`grid gap-3 sm:grid-cols-2 lg:grid-cols-3 ${ready ? '' : 'pointer-events-none opacity-60'}`} aria-label="Canales del plan">
                {commercial.channels.map((channel) => (
                    <li key={channel.key}>
                        <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-line bg-card px-4 py-3 text-sm text-ink hover:border-primary/50">
                            <Checkbox checked={selected.includes(channel.key)} onChange={() => toggle(channel.key)} />
                            <span className="font-semibold">{channel.label}</span>
                        </label>
                    </li>
                ))}
            </ul>
            <p className="text-xs text-ink-muted">{selected.length === 0 ? 'Ningún canal: el Workspace no podrá conectar canales.' : `${selected.length} de ${commercial.channels.length} canales incluidos.`}</p>
        </SectionCard>
    );
}
