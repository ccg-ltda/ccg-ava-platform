import { useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import Select from '@/Components/Select';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import CopyBlock from '@/Components/CopyBlock';
import Badge from '@/Components/Badge';

/** One input of the form, drawn from the descriptor the integration type sends (text, textarea or write-only secret). */
function Field({ field, data, setData, errors, stored, editing }) {
    const id = `channel_${field.name}`;

    // A value the server computed for this connection (an address to paste in the provider's panel): shown to copy, never sent back.
    if (field.kind === 'copy') {
        return (
            <div>
                <InputLabel value={field.label} />
                {stored?.[field.name] ? <CopyBlock className="mt-1" value={stored[field.name]} label={field.label} /> : <p className="mt-1 text-sm text-ink-muted">Se genera al guardar la conexión.</p>}
                {field.hint && <p className="mt-1.5 text-xs text-ink-muted">{field.hint}</p>}
            </div>
        );
    }

    const secretSet = field.kind === 'secret' && Boolean(stored?.[`${field.name}_set`]);
    const label = field.required ? field.label : field.label;

    return (
        <div>
            <div className="flex flex-wrap items-center gap-2">
                <InputLabel htmlFor={id} value={label} />
                {field.pending && <Badge tone="amber" title={field.pending}>Por confirmar</Badge>}
            </div>
            {field.kind === 'select' ? (
                <Select id={id} className="mt-1" value={data[field.name]} onChange={(value) => setData(field.name, value)} options={field.options} placeholder="Elige una opción" invalid={Boolean(errors[field.name])} />
            ) : field.kind === 'textarea' ? (
                <Textarea id={id} className="mt-1" rows={3} value={data[field.name]} onChange={(e) => setData(field.name, e.target.value)} placeholder={field.placeholder} invalid={Boolean(errors[field.name])} spellCheck={false} />
            ) : (
                <TextInput
                    id={id}
                    type={field.kind === 'secret' ? 'password' : 'text'}
                    className="mt-1"
                    value={data[field.name]}
                    onChange={(e) => setData(field.name, e.target.value)}
                    placeholder={secretSet ? `${stored?.[`${field.name}_mask`] ?? '••••••••••••'} (guardado; vacío = conservar)` : field.placeholder}
                    invalid={Boolean(errors[field.name])}
                    maxLength={field.kind === 'secret' ? 4096 : 2048}
                    autoComplete={field.kind === 'secret' ? 'new-password' : 'off'}
                />
            )}
            <InputError message={errors[field.name]} className="mt-1" />
            {secretSet && editing && <p className="mt-1.5 text-xs text-ink-muted">Escribe un valor nuevo solo para reemplazarlo. Nunca se muestra el guardado.</p>}
            {field.hint && <p className="mt-1.5 text-xs text-ink-muted">{field.hint}</p>}
            {field.pending && <p className="mt-1 text-xs text-accent-amber">{field.pending}</p>}
        </div>
    );
}

/**
 * Configures the account the ACTIVE Workspace uses for a channel. The form is whatever the channel's integration type
 * declares (`channel.fields`); secrets are write-only: a stored one is never sent here (only "is set"), and leaving
 * it blank while editing keeps it. The same modal configures an external agenda (`routeName` of its own).
 */
export default function ChannelIntegrationModal({ channel, onClose, routeName = 'integrations.channels.update' }) {
    const editing = Boolean(channel.integration);
    const stored = channel.integration?.form;
    const form = useForm(Object.fromEntries(channel.fields.filter(({ kind }) => kind !== 'copy').map(({ name, default: initial }) => [name, stored?.[name] ?? initial ?? ''])));
    const { data, setData, errors } = form;

    const submit = (event) => {
        event.preventDefault();
        form.put(route(routeName, channel.key), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">{editing ? `Editar ${channel.label}` : `Configurar ${channel.label}`}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Configuración propia de este Workspace. No se comparte con ningún otro.</p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    {channel.fields.map((field, index) => (
                        <div key={field.name} className="space-y-5">
                            {field.section && field.section !== channel.fields[index - 1]?.section && <h3 className="border-b border-line pb-1 text-sm font-bold text-ink">{field.section}</h3>}
                            <Field field={field} data={data} setData={setData} errors={errors} stored={stored} editing={editing} />
                        </div>
                    ))}

                    {channel.notice && <Alert tone="info">{channel.notice}</Alert>}
                </div>

                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing}>
                        {form.processing ? 'Guardando...' : editing ? 'Guardar cambios' : 'Guardar configuración'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
