import { useForm } from '@inertiajs/react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';

/** Creates a chatbot with its name and description; identity, channels and behavior are set on its own page. */
export default function NewChatbotModal({ onClose }) {
    const { data, setData, post, processing, errors } = useForm({ name: '', description: '' });

    const submit = (event) => {
        event.preventDefault();
        post(route('chatbots.store'), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="lg">
            <form onSubmit={submit} noValidate>
                <header className="border-b border-line px-5 py-4 sm:px-6">
                    <h2 className="text-lg font-bold text-ink">Nuevo chatbot</h2>
                    <p className="mt-1 text-sm text-ink-muted">Después podrás subir su avatar, escribir sus instrucciones y elegir sus canales.</p>
                </header>

                <div className="space-y-5 px-5 py-6 sm:px-6">
                    <div>
                        <InputLabel htmlFor="bot_name" value="Nombre" />
                        <TextInput id="bot_name" className="mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} maxLength={100} autoFocus />
                        <InputError message={errors.name} className="mt-1" />
                    </div>
                    <div>
                        <InputLabel htmlFor="bot_description" value="Descripción (opcional)" />
                        <Textarea id="bot_description" className="mt-1" rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} invalid={Boolean(errors.description)} maxLength={500} />
                        <InputError message={errors.description} className="mt-1" />
                    </div>
                </div>

                <footer className="flex flex-col-reverse gap-3 border-t border-line px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={processing}>
                        {processing ? 'Creando...' : 'Crear chatbot'}
                    </PrimaryButton>
                </footer>
            </form>
        </Modal>
    );
}
