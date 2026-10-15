import { useState } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import { applyChange, hasMalformedMarkers } from '@/config/messageLimits';
import LimitedTextarea from './LimitedTextarea';

const PATTERN = /\{\{\s*([A-Za-z_]+)\s*\}\}/g;

const usedIn = (body) => [...new Set([...body.matchAll(PATTERN)].map((match) => match[1]))];

/**
 * Message body with variable buttons and a live preview. The preview fills the body with a sample customer and says, for each variable
 * the message uses, whether that customer has the data and how many customers of the Workspace do. The server validates the variables again.
 */
export default function MessageEditor({ id = 'message_body', label = 'Mensaje', value, onChange, error, variables, availability, sample, max, disabled = false }) {
    const [sampleId, setSampleId] = useState(sample[0]?.id ?? null);
    const customer = sample.find((item) => item.id === sampleId);
    const known = new Set(variables.map((variable) => variable.key));
    const used = usedIn(value);
    const unknown = used.filter((name) => !known.has(name));
    const malformed = hasMalformedMarkers(value);
    const [insertNotice, setInsertNotice] = useState(null);

    const insert = (name) => {
        const result = applyChange(value, `${value}${value && !value.endsWith(' ') ? ' ' : ''}{{${name}}}`, max);

        setInsertNotice(result.rejected);

        if (result.value !== value) onChange(result.value);
    };

    const preview = value.split(PATTERN).map((part, index) => {
        if (index % 2 === 0) return part;
        if (!known.has(part)) return <mark key={index} className="rounded bg-danger/10 px-1 text-danger">{`{{${part}}}`}</mark>;

        const filled = customer?.values[part];

        return filled ? <strong key={index}>{filled}</strong> : <mark key={index} className="rounded bg-accent-amber/10 px-1 text-accent-amber">{`{{${part}}}`}</mark>;
    });

    return (
        <div className="space-y-3">
            <div>
                <InputLabel htmlFor={id} value={label} />
                <LimitedTextarea id={id} value={value} onChange={onChange} max={max} disabled={disabled} invalid={Boolean(error)} />
                <InputError message={error} className="mt-1" />
                {insertNotice && (
                    <p role="status" className="mt-1 text-xs text-accent-amber">
                        {insertNotice}
                    </p>
                )}
            </div>

            {!disabled && (
                <div className="flex flex-wrap gap-2" role="group" aria-label="Insertar variable">
                    {variables.map((variable) => (
                        <button
                            key={variable.key}
                            type="button"
                            onClick={() => insert(variable.key)}
                            className="rounded-full border border-line bg-canvas px-3 py-1 text-xs font-semibold text-ink-muted hover:border-primary/40 hover:text-primary focus-visible:outline-2 focus-visible:outline-primary"
                        >
                            {`{{${variable.key}}}`}
                        </button>
                    ))}
                </div>
            )}

            <div className="rounded-lg border border-line bg-canvas/60 p-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Vista previa</p>
                    {sample.length > 0 && <Select size="sm" className="w-48" aria-label="Cliente de ejemplo" value={sampleId} onChange={setSampleId} options={sample.map((item) => ({ value: item.id, label: item.name }))} />}
                </div>
                <p className="mt-2 break-words whitespace-pre-line text-ink">{value ? preview : <span className="text-ink-muted">Escribe el mensaje para verlo aquí.</span>}</p>
                {sample.length === 0 && <p className="mt-2 text-xs text-ink-muted">Aún no hay clientes para probar el mensaje: las variables se mostrarán sin datos.</p>}
            </div>

            {(used.length > 0 || malformed) && (
                <ul className="space-y-1 text-xs" aria-label="Datos disponibles de las variables">
                    {malformed && <li className="text-danger">Revisa las llaves dobles: una variable se escribe como {'{{nombre}}'}; no se admiten variables numeradas como {'{{1}}'} ni llaves sueltas.</li>}
                    {unknown.map((name) => (
                        <li key={name} className="text-danger">{`{{${name}}}`}: esta variable no existe.</li>
                    ))}
                    {used.filter((name) => known.has(name)).map((name) => {
                        const info = availability[name];
                        const missingForSample = customer && !customer.values[name];

                        return (
                            <li key={name} className={missingForSample || info.available < info.total ? 'text-accent-amber' : 'text-ink-muted'}>
                                {`{{${name}}}`}: {info.available} de {info.total} clientes tienen este dato
                                {missingForSample ? ` (${customer.name} no lo tiene)` : ''}.
                                {info.available < info.total && ' A quienes no lo tengan, la campaña no les escribirá.'}
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
