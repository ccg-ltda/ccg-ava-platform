import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';

/**
 * The channel and the message of a reminder, shared by the single reminder and by an automatic rule so both are filled and validated the same way. `data` / `setData` /
 * `errors` come from the parent's form; the channels arrive from the server with what is really available (an unavailable one shows its reason and cannot be chosen).
 * Two columns on a wide screen: every cell is a label above one control, so the rows line up.
 */
export default function MessageFields({ data, setData, errors, channels, variables, limits, idPrefix }) {
    const whatsapp = data.channel === 'whatsapp';
    const toggleParam = (key) => setData('template_params', data.template_params.includes(key) ? data.template_params.filter((item) => item !== key) : [...data.template_params, key]);
    const unavailable = channels.filter((channel) => !channel.available);

    return (
        <>
            <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                <div className="min-w-0">
                    <InputLabel htmlFor={`${idPrefix}_channel`} value="Canal" />
                    <Select
                        id={`${idPrefix}_channel`}
                        className="mt-1"
                        value={data.channel}
                        onChange={(channel) => setData('channel', channel)}
                        invalid={Boolean(errors.channel)}
                        options={channels.map((channel) => ({ value: channel.key, label: channel.available ? channel.label : `${channel.label} (no disponible)`, disabled: !channel.available }))}
                    />
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
                <ul className="space-y-0.5 text-xs text-ink-muted">
                    {unavailable.map((channel) => (
                        <li key={channel.key}>
                            {channel.label}: {channel.reason}
                        </li>
                    ))}
                </ul>
            )}

            <div>
                <InputLabel htmlFor={`${idPrefix}_body`} value={whatsapp ? 'Mensaje (referencia interna)' : 'Mensaje'} />
                <Textarea id={`${idPrefix}_body`} className="mt-1" rows={5} value={data.body} onChange={(event) => setData('body', event.target.value)} invalid={Boolean(errors.body)} maxLength={limits.body} />
                <p className="mt-1 text-xs text-ink-muted">
                    Variables: {variables.map((variable) => `{{${variable.key}}}`).join(' ')} · {data.body.length}/{limits.body}
                </p>
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
                        <p className="text-sm font-medium text-ink">Variables, en orden: {data.template_params.length ? data.template_params.map((key) => `{{${key}}}`).join(' → ') : 'ninguna'}</p>
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
        </>
    );
}
