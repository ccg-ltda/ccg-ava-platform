import { Mail, MessageSquareText, Plus } from 'lucide-react';
import { useRef } from 'react';
import Badge from '@/Components/Badge';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import { CUSTOMER_EXAMPLE_VALUES, hasMalformedMarkers, insertVariable, renderText, templateParameters } from '@/lib/messageVariables';

/** Buttons with the customer data the message can use: choosing one writes it at the cursor, so nobody has to know or type its technical name. */
function VariableButtons({ variables, onPick, label }) {
    return (
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={label}>
            {variables.map((variable) => (
                <button
                    key={variable.key}
                    type="button"
                    onClick={() => onPick(variable.key)}
                    className="inline-flex items-center gap-1 rounded-full border border-field bg-card px-2.5 py-1 text-xs font-semibold text-ink transition-colors hover:border-primary/60 hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
                >
                    <Plus className="size-3 text-accent-blue" aria-hidden="true" />
                    {variable.label}
                </button>
            ))}
        </div>
    );
}

/**
 * What the customer would read, with example data. It is a simulation built from the form and never a real message or real data: the example values are the same for every
 * Workspace and are not stored. An e-mail shows its subject and body; WhatsApp shows the template Meta delivers (its text is defined and approved in Meta, Ava only sends its
 * name, language and the values in order) next to the reference text of the message.
 */
function Preview({ channel, data, variables, values, real }) {
    const whatsapp = channel === 'whatsapp';
    const text = renderText(data.body, values);
    const malformed = hasMalformedMarkers(data.body);
    const labelOf = (key) => variables.find((variable) => variable.key === key)?.label ?? key;
    const parameters = templateParameters(data.template_params, values);

    return (
        <aside aria-label="Vista previa del mensaje" className="space-y-3 rounded-xl border border-dashed border-primary/40 bg-primary-soft/30 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <MessageSquareText className="size-4 text-accent-blue" aria-hidden="true" />
                <p className="text-sm font-bold text-ink">Vista previa · {whatsapp ? 'WhatsApp' : 'Correo electrónico'}</p>
                <Badge tone={real ? 'blue' : 'amber'}>{real ? 'Con datos del cliente' : 'Simulación'}</Badge>
            </div>
            <p className="text-xs text-ink-muted">{real ? 'Así lo leería este cliente. Es solo una vista previa: no se envía ni se guarda.' : 'Así lo leería un cliente con datos de ejemplo. No es un mensaje real ni se guarda.'}</p>
            {malformed && <p role="alert" className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">Hay llaves sin cerrar o un dato que no existe: ese texto se enviará vacío o el sistema rechazará el mensaje. Usa los botones para insertar los datos.</p>}

            {whatsapp ? (
                <>
                    <div>
                        <p className="mb-1 text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Texto de referencia</p>
                        <div className="max-w-sm rounded-2xl rounded-tl-sm bg-accent-green/10 px-3.5 py-2.5 text-sm break-words whitespace-pre-line text-ink shadow-card">{text || 'Escribe el mensaje para ver cómo queda.'}</div>
                    </div>
                    <div className="space-y-2 rounded-xl border border-line bg-card p-3 text-sm">
                        <p className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Plantilla que recibe el cliente</p>
                        {data.template_name.trim() === '' ? (
                            <p className="text-xs text-ink-muted">Indica el nombre de la plantilla aprobada: sin ella no se puede enviar por WhatsApp.</p>
                        ) : (
                            <>
                                <p className="font-semibold text-ink">{data.template_name} <span className="text-xs font-normal text-ink-muted">· idioma {data.template_language || 'sin indicar'}</span></p>
                                {parameters.length === 0 ? (
                                    <p className="text-xs text-ink-muted">Sin variables.</p>
                                ) : (
                                    <ul className="space-y-1 text-xs text-ink-muted">
                                        {parameters.map((parameter) => (
                                            <li key={parameter.position} className="flex gap-2">
                                                <code className="shrink-0 rounded bg-canvas px-1.5 font-mono text-ink">{`{{${parameter.position}}}`}</code>
                                                <span className="min-w-0 break-words">{labelOf(parameter.variable)}: <strong className="text-ink">{parameter.value}</strong></span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </>
                        )}
                    </div>
                </>
            ) : (
                <div className="max-w-xl overflow-hidden rounded-xl border border-line bg-card text-sm shadow-card">
                    <p className="flex items-center gap-2 border-b border-line bg-canvas/60 px-3.5 py-2 text-xs text-ink-muted">
                        <Mail className="size-3.5" aria-hidden="true" />
                        <span className="min-w-0 truncate">Asunto: <strong className="text-ink">{renderText(data.subject, values) || 'sin asunto'}</strong></span>
                    </p>
                    <div className="px-3.5 py-3 break-words whitespace-pre-line text-ink">{text || <span className="text-ink-muted">Escribe el mensaje para ver cómo queda.</span>}</div>
                </div>
            )}
        </aside>
    );
}

/**
 * The channel and the message of a reminder, shared by the single reminder and by an automatic rule so both are filled and validated the same way. `data` / `setData` /
 * `errors` come from the parent's form. ONLY the channels that are really available in this Workspace can be chosen (the others are listed below with their reason, never as an
 * option); the server checks again when saving and when sending. The customer data goes in with buttons at the cursor and a live preview shows the result with example data.
 * Two columns on a wide screen: every cell is a label above one control, so the rows line up.
 */
export default function MessageFields({ data, setData, errors, channels, variables, limits, idPrefix, previewValues = null }) {
    const area = useRef(null);
    const whatsapp = data.channel === 'whatsapp';
    const available = channels.filter((channel) => channel.available);
    const unavailable = channels.filter((channel) => !channel.available);
    const toggleParam = (key) => setData('template_params', data.template_params.includes(key) ? data.template_params.filter((item) => item !== key) : [...data.template_params, key]);

    const insert = (variable) => {
        const field = area.current;
        const { text, caret } = insertVariable(data.body, variable, field?.selectionStart, field?.selectionEnd);

        setData('body', text);
        // The new text renders on the next frame: put the caret right after the inserted data and give the focus back.
        requestAnimationFrame(() => {
            field?.focus();
            field?.setSelectionRange(caret, caret);
        });
    };

    return (
        <>
            <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                <div className="min-w-0">
                    <InputLabel htmlFor={`${idPrefix}_channel`} value="Canal" />
                    {available.length === 0 ? (
                        <p id={`${idPrefix}_channel`} role="alert" className="mt-1 rounded-lg border border-line bg-canvas/60 px-3 py-2 text-sm text-ink-muted">Ningún canal está disponible ahora mismo.</p>
                    ) : (
                        <Select
                            id={`${idPrefix}_channel`}
                            className="mt-1"
                            value={data.channel}
                            onChange={(channel) => setData('channel', channel)}
                            invalid={Boolean(errors.channel)}
                            options={available.map((channel) => ({ value: channel.key, label: channel.label }))}
                        />
                    )}
                    <InputError message={errors.channel} className="mt-1" />
                </div>
                {!whatsapp && (
                    <div className="min-w-0">
                        <InputLabel htmlFor={`${idPrefix}_subject`} value="Asunto del correo" />
                        <TextInput id={`${idPrefix}_subject`} className="mt-1" value={data.subject} onChange={(event) => setData('subject', event.target.value)} invalid={Boolean(errors.subject)} maxLength={limits.subject} autoComplete="off" />
                        <InputError message={errors.subject} className="mt-1" />
                    </div>
                )}
            </div>
            {unavailable.length > 0 && (
                <ul className="space-y-0.5 text-xs text-ink-muted" aria-label="Canales no disponibles">
                    {unavailable.map((channel) => (
                        <li key={channel.key}>
                            {channel.label} no está disponible: {channel.reason}
                        </li>
                    ))}
                </ul>
            )}

            <div>
                <InputLabel htmlFor={`${idPrefix}_body`} value={whatsapp ? 'Mensaje (referencia interna)' : 'Mensaje'} />
                <div className="mt-1 space-y-2">
                    <VariableButtons variables={variables} onPick={insert} label="Datos del cliente que se pueden insertar en el mensaje" />
                    <Textarea ref={area} id={`${idPrefix}_body`} rows={5} value={data.body} onChange={(event) => setData('body', event.target.value)} invalid={Boolean(errors.body)} maxLength={limits.body} />
                </div>
                <p className="mt-1 text-xs text-ink-muted">Pulsa un dato para insertarlo donde está el cursor; se reemplaza por el del cliente al enviar · {data.body.length}/{limits.body}</p>
                <InputError message={errors.body} className="mt-1" />
            </div>

            {whatsapp && (
                <fieldset className="space-y-4 rounded-xl border border-line bg-canvas/40 p-4">
                    <legend className="px-1 text-sm font-semibold text-ink">Plantilla aprobada de WhatsApp</legend>
                    <p className="text-xs text-ink-muted">Un seguimiento siempre cae fuera de las 24 h de conversación: WhatsApp solo lo entrega con una plantilla aprobada por Meta. Escribe su nombre e idioma tal como están en Meta y marca, en orden, qué dato de la persona llena cada variable.</p>
                    <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                        <div className="min-w-0">
                            <InputLabel htmlFor={`${idPrefix}_tpl`} value="Nombre de la plantilla" />
                            <TextInput id={`${idPrefix}_tpl`} className="mt-1" value={data.template_name} onChange={(event) => setData('template_name', event.target.value)} invalid={Boolean(errors.template_name)} autoComplete="off" />
                            <InputError message={errors.template_name} className="mt-1" />
                        </div>
                        <div className="min-w-0">
                            <InputLabel htmlFor={`${idPrefix}_lang`} value="Idioma" />
                            <TextInput id={`${idPrefix}_lang`} className="mt-1" value={data.template_language} onChange={(event) => setData('template_language', event.target.value)} maxLength={12} autoComplete="off" />
                        </div>
                    </div>
                    <div>
                        <p className="text-sm font-medium text-ink">Variables, en orden: {data.template_params.length ? data.template_params.map((key) => variables.find((variable) => variable.key === key)?.label ?? key).join(' → ') : 'ninguna'}</p>
                        <div className="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                            {variables.map((variable) => (
                                <label key={variable.key} className="flex items-center gap-2 text-sm text-ink">
                                    <Checkbox checked={data.template_params.includes(variable.key)} onChange={() => toggleParam(variable.key)} />
                                    {variable.label}
                                </label>
                            ))}
                        </div>
                        <InputError message={errors.template_params} className="mt-1" />
                    </div>
                </fieldset>
            )}

            <Preview channel={data.channel} data={data} variables={variables} values={previewValues ?? CUSTOMER_EXAMPLE_VALUES} real={Boolean(previewValues)} />
        </>
    );
}
