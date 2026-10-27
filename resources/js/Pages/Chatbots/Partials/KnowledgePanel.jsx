import { router, useForm } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';

const STATUS = {
    pending: { tone: 'amber', label: 'Pendiente' },
    processing: { tone: 'amber', label: 'Procesando' },
    ready: { tone: 'green', label: 'Listo para consultar' },
    error: { tone: 'red', label: 'Con error' },
};

/** One document: its state is the real state of the extraction, never «aprendido» just because the file was uploaded. */
function Document({ chatbot, doc, canEdit }) {
    const confirm = useConfirm();
    const status = STATUS[doc.status] ?? STATUS.error;

    const remove = async () => {
        const confirmed = await confirm({ description: `Se eliminará «${doc.title}» con todos sus fragmentos y el archivo guardado. Ningún asistente podrá consultarlo.`, confirmLabel: 'Eliminar' });

        if (confirmed) router.delete(route('chatbots.knowledge.destroy', [chatbot.id, doc.id]), { preserveScroll: true });
    };

    return (
        <li className="space-y-2 py-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-ink">{doc.title}</p>
                    <p className="text-xs text-ink-muted">
                        {doc.file} · {doc.size} · subido {doc.at}
                        {doc.status === 'ready' ? ` · ${doc.chunks} ${doc.chunks === 1 ? 'fragmento' : 'fragmentos'}` : ''}
                    </p>
                </div>
                <Badge tone={status.tone}>{status.label}</Badge>
            </div>

            {doc.error && <p className="text-sm text-danger">{doc.error}</p>}

            {canEdit && (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <label className="flex items-center gap-2 text-sm text-ink">
                        <input
                            type="checkbox"
                            className="size-4 rounded border-line"
                            checked={doc.linked}
                            disabled={doc.status !== 'ready'}
                            onChange={(e) => router.post(route('chatbots.knowledge.link', [chatbot.id, doc.id]), { linked: e.target.checked }, { preserveScroll: true })}
                        />
                        Este asistente puede consultarlo
                    </label>
                    <div className="flex gap-2">
                        {doc.canRetry && (
                            <SecondaryButton type="button" onClick={() => router.post(route('chatbots.knowledge.retry', [chatbot.id, doc.id]), {}, { preserveScroll: true })}>
                                Reintentar
                            </SecondaryButton>
                        )}
                        <SecondaryButton type="button" onClick={remove} className="text-danger">
                            Eliminar
                        </SecondaryButton>
                    </div>
                </div>
            )}
        </li>
    );
}

/**
 * Base de conocimiento: documents of the Workspace that the assistant may consult. Zonno extracts their text and finds passages by words; n8n asks for them through
 * the search endpoint. Nothing is «learned»: the assistant only sees what the search returns for each question.
 */
export default function KnowledgePanel({ chatbot, knowledge, canEdit }) {
    const form = useForm({ file: null, title: '' });
    const accept = knowledge.extensions.map((extension) => `.${extension}`).join(',');

    const submit = (event) => {
        event.preventDefault();
        form.post(route('chatbots.knowledge.store', chatbot.id), { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset() });
    };

    return (
        <Card className="space-y-5 p-5 sm:p-6" aria-label="Base de conocimiento">
            <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <BookOpen className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0">
                    <h3 className="text-base font-bold text-ink">Base de conocimiento</h3>
                    <p className="text-sm text-ink-muted">
                        Documentos que el asistente puede consultar ({knowledge.extensions.join(', ')}; máx. {Math.round(knowledge.maxKb / 1024)} MB). Los PDF aún no se pueden leer: conviértelos a .docx o .txt.
                    </p>
                </div>
            </div>

            <Alert tone="info">El asistente no «aprende» el documento: cuando n8n pregunta, Zonno devuelve los fragmentos que comparten palabras con la pregunta. Solo los documentos en «Listo para consultar» se usan.</Alert>

            {canEdit && (
                <form onSubmit={submit} noValidate className="space-y-3 rounded-xl border border-line p-4">
                    <div>
                        <InputLabel htmlFor="knowledge_file" value="Archivo" />
                        <input id="knowledge_file" type="file" accept={accept} className="mt-1 block w-full text-sm text-ink" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                        <InputError message={form.errors.file} className="mt-1" />
                    </div>
                    <div className="flex justify-end">
                        <PrimaryButton type="submit" disabled={form.processing || !form.data.file}>
                            {form.processing ? 'Subiendo y leyendo...' : 'Subir documento'}
                        </PrimaryButton>
                    </div>
                </form>
            )}

            {knowledge.documents.length === 0 ? (
                <p className="text-sm text-ink-muted">Todavía no hay documentos en este negocio.</p>
            ) : (
                <ul className="divide-y divide-line">
                    {knowledge.documents.map((doc) => (
                        <Document key={doc.id} chatbot={chatbot} doc={doc} canEdit={canEdit} />
                    ))}
                </ul>
            )}
        </Card>
    );
}
