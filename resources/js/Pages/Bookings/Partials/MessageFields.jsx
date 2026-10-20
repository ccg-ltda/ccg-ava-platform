import { ChevronDown, ChevronUp, MessageSquareText, Plus, X } from 'lucide-react';
import { useRef } from 'react';
import Badge from '@/Components/Badge';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { VARIABLE_LABELS, insertVariable, move, renderText, templateParameters } from '@/lib/messageVariables';

/** Buttons with the variables the system accepts: choosing one calls `onPick(variable)`. Nothing has to be typed by hand. */
function VariableButtons({ variables, onPick, disabled, label }) {
    return (
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={label}>
            {variables.map((variable) => (
                <button
                    key={variable}
                    type="button"
                    disabled={disabled}
                    onClick={() => onPick(variable)}
                    title={VARIABLE_LABELS[variable] ?? variable}
                    className="inline-flex items-center gap-1 rounded-full border border-field bg-card px-2.5 py-1 text-xs font-semibold text-ink transition-colors hover:border-primary/60 hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Plus className="size-3 text-accent-blue" aria-hidden="true" />
                    {VARIABLE_LABELS[variable] ?? variable}
                </button>
            ))}
        </div>
    );
}

/** The ordered variables of a Meta template: each is the parameter {{1}}, {{2}}... that Meta receives, so the order is the one the template expects. */
function ParameterList({ params, onChange, disabled }) {
    if (params.length === 0) return <p className="text-xs text-ink-muted">Sin variables: Ava enviará la plantilla sin parámetros.</p>;

    return (
        <ol className="space-y-1.5" aria-label="Variables de la plantilla en orden">
            {params.map((variable, index) => (
                <li key={`${variable}-${index}`} className="flex items-center gap-2 rounded-lg border border-line bg-card px-2 py-1.5 text-sm">
                    <span className="grid size-6 shrink-0 place-items-center rounded-md bg-primary/10 text-xs font-bold text-accent-blue tabular-nums">{index + 1}</span>
                    <span className="min-w-0 flex-1 truncate text-ink">{VARIABLE_LABELS[variable] ?? variable}</span>
                    {!disabled && (
                        <span className="flex shrink-0 items-center">
                            <button type="button" aria-label={`Subir ${VARIABLE_LABELS[variable] ?? variable}`} disabled={index === 0} onClick={() => onChange(move(params, index, -1))} className="rounded p-1 text-ink-muted hover:bg-canvas disabled:opacity-30 focus-visible:outline-2 focus-visible:outline-primary">
                                <ChevronUp className="size-4" aria-hidden="true" />
                            </button>
                            <button type="button" aria-label={`Bajar ${VARIABLE_LABELS[variable] ?? variable}`} disabled={index === params.length - 1} onClick={() => onChange(move(params, index, 1))} className="rounded p-1 text-ink-muted hover:bg-canvas disabled:opacity-30 focus-visible:outline-2 focus-visible:outline-primary">
                                <ChevronDown className="size-4" aria-hidden="true" />
                            </button>
                            <button type="button" aria-label={`Quitar ${VARIABLE_LABELS[variable] ?? variable}`} onClick={() => onChange(params.filter((_, position) => position !== index))} className="rounded p-1 text-danger hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-danger">
                                <X className="size-4" aria-hidden="true" />
                            </button>
                        </span>
                    )}
                </li>
            ))}
        </ol>
    );
}

/** A simulated WhatsApp bubble: what the customer would read. It is a simulation built from the form, never a real message. */
function Bubble({ title, children }) {
    return (
        <div>
            <p className="mb-1 text-[11px] font-semibold tracking-wider text-ink-muted uppercase">{title}</p>
            <div className="max-w-sm rounded-2xl rounded-tl-sm bg-accent-green/10 px-3.5 py-2.5 text-sm break-words whitespace-pre-line text-ink shadow-card">{children}</div>
        </div>
    );
}

/** Live preview of one message: the free text (inside the 24 h window) and the Meta template, both with example data. */
function Preview({ text, fallback, template, params }) {
    const parameters = templateParameters(params);
    const hasTemplate = template.name.trim() !== '';

    return (
        <aside aria-label="Vista previa del mensaje" className="space-y-3 self-start rounded-xl border border-dashed border-primary/40 bg-primary-soft/30 p-4">
            <div className="flex items-center gap-2">
                <MessageSquareText className="size-4 text-accent-blue" aria-hidden="true" />
                <p className="text-sm font-bold text-ink">Vista previa</p>
                <Badge tone="amber">Simulación</Badge>
            </div>
            <p className="text-xs text-ink-muted">Así lo leería el cliente con datos de ejemplo. No se envía nada.</p>

            <Bubble title="Texto libre (dentro de las 24 h)">{renderText(text || fallback) || 'Escribe el mensaje para ver cómo queda.'}</Bubble>

            <div>
                <p className="mb-1 text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Plantilla de Meta (fuera de las 24 h)</p>
                {hasTemplate ? (
                    <div className="space-y-2 rounded-xl border border-line bg-card p-3 text-sm">
                        <p className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="font-semibold text-ink">{template.name}</span>
                            <span className="text-xs text-ink-muted">Idioma: {template.language || 'sin indicar'}</span>
                        </p>
                        {parameters.length > 0 ? (
                            <ul className="space-y-1 text-xs text-ink-muted">
                                {parameters.map((parameter) => (
                                    <li key={parameter.position} className="flex gap-2">
                                        <code className="shrink-0 rounded bg-canvas px-1.5 font-mono text-ink">{`{{${parameter.position}}}`}</code>
                                        <span className="min-w-0 break-words">
                                            {VARIABLE_LABELS[parameter.variable] ?? parameter.variable}: <strong className="text-ink">{parameter.value}</strong>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-xs text-ink-muted">Sin variables.</p>
                        )}
                        <p className="text-xs text-ink-muted">El texto de la plantilla lo define y aprueba Meta: Ava solo envía el nombre, el idioma y estos valores, en este orden.</p>
                    </div>
                ) : (
                    <p className="rounded-xl border border-line bg-card p-3 text-xs text-ink-muted">Sin plantilla: fuera de las 24 h el aviso no se podrá enviar.</p>
                )}
            </div>
        </aside>
    );
}

/**
 * One message of the bookings (reception, confirmation, reminder): the free text with buttons to insert the variables at the cursor, the Meta template
 * (name, language and its ordered variables) and a live preview. `message` is `{ text, template: { name, language, params: [variable...] } }`; `onChange(next)`.
 * The variables are the ones the server accepts (`variables`); the order of `params` is the order of the parameters sent to Meta.
 */
export default function MessageFields({ kind, message, onChange, variables, fallback, limits, errors, disabled }) {
    const area = useRef(null);
    const setText = (text) => onChange({ ...message, text });
    const setTemplate = (patch) => onChange({ ...message, template: { ...message.template, ...patch } });

    const insert = (variable) => {
        const field = area.current;
        const { text, caret } = insertVariable(message.text, variable, field?.selectionStart, field?.selectionEnd);

        setText(text);
        // The new text renders on the next frame: put the caret right after the marker and give the focus back.
        requestAnimationFrame(() => {
            field?.focus();
            field?.setSelectionRange(caret, caret);
        });
    };

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div className="space-y-5">
                <div>
                    <InputLabel htmlFor={`msg_${kind}`} value="Texto (dentro de las 24 h)" />
                    <Textarea ref={area} id={`msg_${kind}`} rows={3} className="mt-1" maxLength={limits.text_max} placeholder={fallback} value={message.text} onChange={(event) => setText(event.target.value)} disabled={disabled} />
                    <div className="mt-2 space-y-1.5">
                        <p className="text-xs text-ink-muted">Pulsa una variable para insertarla donde está el cursor:</p>
                        <VariableButtons variables={variables} onPick={insert} disabled={disabled} label="Insertar variable en el texto" />
                    </div>
                    <InputError message={errors[`messages.${kind}.text`]} className="mt-1" />
                </div>

                <div className="space-y-3 rounded-lg border border-line p-3 sm:p-4">
                    <div>
                        <p className="text-sm font-semibold text-ink">Plantilla de Meta</p>
                        <p className="text-xs text-ink-muted">Se usa cuando la persona no ha escrito al negocio en las últimas {limits.window_hours} horas.</p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="sm:col-span-2">
                            <InputLabel htmlFor={`tpl_${kind}`} value="Nombre de la plantilla" />
                            <TextInput id={`tpl_${kind}`} className="mt-1" placeholder="recordatorio_cita" value={message.template.name} onChange={(event) => setTemplate({ name: event.target.value })} disabled={disabled} invalid={Boolean(errors[`messages.${kind}.template.name`])} />
                            <InputError message={errors[`messages.${kind}.template.name`]} className="mt-1" />
                        </div>
                        <div>
                            <InputLabel htmlFor={`lang_${kind}`} value="Idioma" />
                            <TextInput id={`lang_${kind}`} className="mt-1" placeholder="es" value={message.template.language} onChange={(event) => setTemplate({ language: event.target.value })} disabled={disabled} invalid={Boolean(errors[`messages.${kind}.template.language`])} />
                            <InputError message={errors[`messages.${kind}.template.language`]} className="mt-1" />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <p className="text-sm font-medium text-ink">Variables de la plantilla, en orden</p>
                        <p className="text-xs text-ink-muted">
                            Meta numera los huecos de su plantilla ({'{{1}}'}, {'{{2}}'}…): la primera variable llena el {'{{1}}'}, la segunda el {'{{2}}'} y así. Pon aquí las variables en el mismo orden en que la plantilla aprobada las espera.
                        </p>
                        <ParameterList params={message.template.params} onChange={(params) => setTemplate({ params })} disabled={disabled} />
                        {!disabled && message.template.params.length < 10 && (
                            <div className="space-y-1.5">
                                <p className="text-xs text-ink-muted">Añadir al final:</p>
                                <VariableButtons variables={variables} onPick={(variable) => setTemplate({ params: [...message.template.params, variable] })} label="Añadir variable a la plantilla" />
                            </div>
                        )}
                        <InputError message={errors[`messages.${kind}.template.params`] ?? errors[`messages.${kind}.template.params.0`]} className="mt-1" />
                    </div>
                </div>
            </div>

            <Preview text={message.text} fallback={fallback} template={message.template} params={message.template.params} />
        </div>
    );
}

