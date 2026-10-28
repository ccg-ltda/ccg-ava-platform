import { router } from '@inertiajs/react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import InputLabel from '@/Components/InputLabel';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

const SOURCE = { customer: 'Cliente', agent: 'Agente', ai: 'IA' };
const SUGGESTED = ['name', 'phone', 'email', 'reason'];

/**
 * «Datos de la conversación»: what has been collected (who gave it and whether it is confirmed), the steps done and the operations already performed. This is the same state the
 * AI receives when the conversation is handed back, so a data point recorded here is not asked again. Only the agent who has the conversation can record or correct a value; an
 * inference of the AI is never shown as confirmed. The server validates, authorizes and applies every change.
 */
export default function ContextPanel({ selected }) {
    const context = selected.context;
    const [key, setKey] = useState('');
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState({});

    if (!context) return null;

    const empty = context.facts.length === 0 && context.steps.length === 0 && context.operations.length === 0;
    if (empty && !selected.can.context) return null;

    const save = (event) => {
        event.preventDefault();
        setBusy(true);
        router.post(route('conversations.context.store', { conversation: selected.id }), { facts: { [key.trim()]: value } }, {
            preserveScroll: true,
            onSuccess: () => { setKey(''); setValue(''); setErrors({}); },
            onError: (e) => setErrors(e),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <details className="border-b border-line bg-card px-3 py-2 text-xs" open={selected.handling === 'human'}>
            <summary className="cursor-pointer font-semibold text-ink focus-visible:outline-2 focus-visible:outline-primary">
                Datos de la conversación{context.facts.length > 0 ? ` (${context.facts.length})` : ''}
                {context.resuming && <span className="ml-2 font-normal text-ink-muted">· la IA retoma desde aquí</span>}
            </summary>

            <div className="mt-2 space-y-3">
                {context.facts.length === 0 ? (
                    <p className="text-ink-muted">Todavía no hay datos recogidos.</p>
                ) : (
                    <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Datos recogidos">
                        {context.facts.map((fact) => (
                            <li key={fact.key} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                                <span className="w-24 shrink-0 font-mono text-ink-muted">{fact.key}</span>
                                <span className="min-w-0 flex-1 break-words font-medium text-ink">{fact.value}</span>
                                <Badge tone={fact.status === 'confirmed' ? 'green' : 'amber'}>{fact.status === 'confirmed' ? 'Confirmado' : 'Inferido por la IA'}</Badge>
                                <span className="text-ink-muted">{SOURCE[fact.source] ?? fact.source}</span>
                                {fact.previous > 0 && <span className="text-ink-muted">· corregido {fact.previous} {fact.previous === 1 ? 'vez' : 'veces'}</span>}
                            </li>
                        ))}
                    </ul>
                )}

                {context.conflicts.length > 0 && (
                    <p className="rounded-lg bg-accent-amber/10 px-3 py-2 text-accent-amber" role="status">
                        La IA propuso un valor distinto para: {[...new Set(context.conflicts.map((c) => c.key))].join(', ')}. Se conservó el dato confirmado.
                    </p>
                )}

                {(context.steps.length > 0 || context.nextStep) && (
                    <p className="text-ink-muted">Pasos completados: {context.steps.join(', ') || 'ninguno'}{context.nextStep ? ` · siguiente: ${context.nextStep}` : ''}</p>
                )}
                {context.operations.length > 0 && (
                    <p className="text-ink-muted">Operaciones ya realizadas (no se repiten): {context.operations.map((o) => `${o.key} ${o.id}`).join(', ')}</p>
                )}

                {selected.can.context && (
                    <form onSubmit={save} className="flex flex-wrap items-end gap-2" aria-label="Registrar o corregir un dato">
                        <div>
                            <InputLabel htmlFor="ctx_key" value="Dato" />
                            <TextInput id="ctx_key" list="ctx_keys" className="mt-1 w-32" value={key} onChange={(e) => setKey(e.target.value)} placeholder="phone" maxLength={40} invalid={Boolean(errors['facts'])} />
                            <datalist id="ctx_keys">{SUGGESTED.map((name) => <option key={name} value={name} />)}</datalist>
                        </div>
                        <div className="min-w-40 flex-1">
                            <InputLabel htmlFor="ctx_value" value="Valor" />
                            <TextInput id="ctx_value" className="mt-1" value={value} onChange={(e) => setValue(e.target.value)} maxLength={500} />
                        </div>
                        <SecondaryButton type="submit" disabled={busy || key.trim() === '' || value.trim() === ''} className="py-1.5!">Guardar dato</SecondaryButton>
                        {errors.facts && <p className="w-full text-danger" role="alert">{errors.facts}</p>}
                        <p className="w-full text-ink-muted">Se guarda como confirmado y aportado por ti. Nombre en minúsculas, sin espacios (por ejemplo <code>phone</code>).</p>
                    </form>
                )}
            </div>
        </details>
    );
}
