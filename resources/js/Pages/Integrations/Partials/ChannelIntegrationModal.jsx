import { useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';

/** One input of the form, drawn from the descriptor the integration type sends (text, textarea or write-only secret). */
function Field({ field, data, setData, errors, stored, editing }) {
    const id = `channel_${field.name}`;
    const secretSet = field.kind === 'secret' && Boolean(stored?.[`${field.name}_set`]);
    const label = field.required ? field.label : field.label;

    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            {field.kind === 'textarea' ? (
                <Textarea id={id} className="mt-1" rows={3} value={data[field.name]} onChange={(e) => setData(field.name, e.target.value)} placeholder={field.placeholder} invalid={Boolean(errors[field.name])} spellCheck={false} />
            ) : (
                <TextInput
                    id={id}
                    type={field.kind === 'secret' ? 'password' : 'text'}
                    className="mt-1"
                    value={data[field.name]}
                    onChange={(e) => setData(field.name, e.target.value)}
                    placeholder={secretSet ? '•••••••••••• (guardado; vacío = conservar)' : field.placeholder}
                    invalid={Boolean(errors[field.name])}
                    maxLength={field.kind === 'secret' ? 4096 : 2048}
                    autoComplete={field.kind === 'secret' ? 'new-password' : 'off'}
                />
            )}
            <InputError message={errors[field.name]} className="mt-1" />
            {secretSet && editing && <p className="mt-1.5 text-xs text-ink-muted">Escribe un valor nuevo solo para reemplazarlo. Nunca se muestra el guardado.</p>}
            {field.hint && <p className="mt-1.5 text-xs text-ink-muted">{field.hint}</p>}
        </div>
    );
}

/**
 * Configures the account the ACTIVE Workspace uses for a channel. The form is whatever the channel's integration type
 * declares (`channel.fields`); secrets are write-only: a stored one is never sent here (only "is set"), and leaving
 * it blank while editing keeps it.
 */
export default function ChannelIntegrationModal({ channel, onClose }) {
    const editing = Boolean(channel.integration);
    const stored = channel.integration?.form;
    const form = useForm(Object.fromEntries(channel.fields.map(({ name }) => [name, stored?.[name] ?? ''])));
    const { data, setData, errors } = form;

    const submit = (event) => {
        event.preventDefault();
        form.put(route('integrations.channels.update', channel.key), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8 sm:py-5">
                    <h2 className="text-lg font-bold text-ink">{editing ? `Editar ${channel.label}` : `Configurar ${channel.label}`}</h2>
                    <p className="mt-1 text-sm text-ink-muted">Configuración propia de este Workspace. No se comparte con ningún otro.</p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    {channel.fields.map((field) => (
                        <Field key={field.name} field={field} data={data} setData={setData} errors={errors} stored={stored} editing={editing} />
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
