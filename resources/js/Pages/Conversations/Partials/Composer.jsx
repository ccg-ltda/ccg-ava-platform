import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
import Textarea from '@/Components/Textarea';

const MAX = 4096;

/** Where the agent writes to the contact. Shown only when the server says the user can reply; otherwise it says why not. */
export default function Composer({ selected }) {
    const form = useForm({ body: '' });

    if (!selected.can.reply) {
        return selected.handling === 'human' && !selected.delivery.ok ? (
            <div className="border-t border-line p-3">
                <Alert tone="warning">{selected.delivery.message}</Alert>
            </div>
        ) : (
            <p className="border-t border-line px-4 py-2.5 text-center text-xs text-ink-muted">
                {selected.handling === 'human' ? 'Esta conversación la atiende otro agente.' : 'Toma la conversación para responder al contacto.'}
            </p>
        );
    }

    const submit = (event) => {
        event.preventDefault();

        if (form.processing || form.data.body.trim() === '') {
            return;
        }

        form.post(route('conversations.messages.store', { conversation: selected.id }), {
            preserveScroll: true,
            // A refused message comes back as an error toast: keep the text so nothing the agent wrote is lost.
            onSuccess: (page) => !page.props.flash?.error && form.reset('body'),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-1 border-t border-line p-2 pr-16">
            <div className="flex items-end gap-2">
                <Textarea
                    value={form.data.body}
                    onChange={(event) => form.setData('body', event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            submit(event);
                        }
                    }}
                    rows={1}
                    maxLength={MAX}
                    placeholder="Escribe tu respuesta"
                    aria-label="Mensaje para el contacto"
                    invalid={Boolean(form.errors.body)}
                    className="max-h-32 min-h-10 flex-1 py-2"
                />
                <PrimaryButton type="submit" disabled={form.processing || form.data.body.trim() === ''} className="h-10 gap-2" aria-label="Enviar mensaje">
                    <Send className="size-4" aria-hidden="true" />
                    <span className="hidden sm:inline">{form.processing ? 'Enviando…' : 'Enviar'}</span>
                </PrimaryButton>
            </div>
            <InputError message={form.errors.body} />
        </form>
    );
}
