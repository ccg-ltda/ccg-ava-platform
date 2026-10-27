import { useForm } from '@inertiajs/react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';
import Textarea from '@/Components/Textarea';
import { diffLines, hasChanges } from '@/lib/diff';

/**
 * «Esta respuesta no me gustó»: the person says why, picks the ONE block of the instructions that should change and writes (or edits) its new content. The dialog
 * only creates a PROPOSAL — it shows the comparison with the current text — and nothing changes until somebody approves it in IA y comportamiento. No model writes
 * the proposal: «Añadir como regla» just appends the desired behavior as a new line that the person can edit.
 */
export default function FeedbackDialog({ conversation, message, feedback, onClose }) {
    const first = feedback.blocks.find((block) => block.key === 'limits') ?? feedback.blocks[0];
    const form = useForm({ reason: '', desired_behavior: '', target_block: first.key, proposed_text: first.text });
    const current = feedback.blocks.find((block) => block.key === form.data.target_block)?.text ?? '';
    const lines = diffLines(current, form.data.proposed_text);

    const choose = (key) => {
        form.setData((data) => ({ ...data, target_block: key, proposed_text: feedback.blocks.find((block) => block.key === key).text }));
    };

    const appendRule = () => {
        const rule = form.data.desired_behavior.trim();

        if (rule === '') return;

        form.setData('proposed_text', `${form.data.proposed_text.replace(/\s+$/, '')}${form.data.proposed_text.trim() === '' ? '' : '\n'}- ${rule}`);
    };

    const submit = (event) => {
        event.preventDefault();
        form.post(route('conversations.feedback.store', [conversation.id, message.id]), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal show onClose={onClose} maxWidth="2xl" scrollable>
            <form onSubmit={submit} noValidate className="flex min-h-0 flex-1 flex-col">
                <header className="border-b border-line px-5 py-4 sm:px-8">
                    <h2 className="text-lg font-bold text-ink">Esta respuesta no me gustó</h2>
                    <p className="mt-1 rounded-lg bg-canvas px-3 py-2 text-sm text-ink-muted">{message.body}</p>
                </header>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-8">
                    <Alert tone="info">Esto crea una propuesta para revisar: no cambia las instrucciones del asistente hasta que una persona autorizada la apruebe.</Alert>

                    <div>
                        <InputLabel htmlFor="fb_reason" value="¿Qué estuvo mal?" />
                        <Textarea id="fb_reason" className="mt-1" rows={2} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} invalid={Boolean(form.errors.reason)} maxLength={1000} />
                        <InputError message={form.errors.reason} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="fb_desired" value="¿Cómo debería responder? (opcional)" />
                        <Textarea id="fb_desired" className="mt-1" rows={2} value={form.data.desired_behavior} onChange={(e) => form.setData('desired_behavior', e.target.value)} maxLength={1000} />
                    </div>

                    <div>
                        <InputLabel htmlFor="fb_block" value="Apartado que debería cambiar" />
                        <Select id="fb_block" className="mt-1" value={form.data.target_block} onChange={choose} options={feedback.blocks.map((block) => ({ value: block.key, label: block.label }))} />
                        <InputError message={form.errors.target_block} className="mt-1" />
                    </div>

                    <div>
                        <div className="flex items-center justify-between gap-3">
                            <InputLabel htmlFor="fb_proposed" value="Contenido nuevo de ese apartado" />
                            <SecondaryButton type="button" onClick={appendRule} disabled={form.data.desired_behavior.trim() === ''}>
                                Añadir como regla
                            </SecondaryButton>
                        </div>
                        <Textarea id="fb_proposed" className="mt-1" rows={7} value={form.data.proposed_text} onChange={(e) => form.setData('proposed_text', e.target.value)} invalid={Boolean(form.errors.proposed_text)} />
                        <InputError message={form.errors.proposed_text} className="mt-1" />
                    </div>

                    <div>
                        <p className="mb-1 text-xs font-semibold text-ink-muted">Comparación con el texto actual (− se quita · + se añade)</p>
                        <pre className="max-h-48 overflow-auto rounded-lg border border-line bg-canvas p-3 font-mono text-[13px] whitespace-pre-wrap" aria-label="Comparación con el texto actual">
                            {hasChanges(lines)
                                ? lines.map((line, index) => (
                                      <span key={index} className={`block ${line.type === 'added' ? 'bg-accent-green/15 text-ink' : line.type === 'removed' ? 'bg-danger/10 text-danger line-through' : 'text-ink-muted'}`}>
                                          {line.type === 'added' ? '+ ' : line.type === 'removed' ? '− ' : '  '}
                                          {line.text}
                                      </span>
                                  ))
                                : 'Sin cambios todavía'}
                        </pre>
                    </div>
                </div>

                <div className="flex justify-end gap-3 border-t border-line bg-card px-5 py-4 sm:px-8">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cancelar
                    </SecondaryButton>
                    <PrimaryButton type="submit" disabled={form.processing || !hasChanges(lines)}>
                        {form.processing ? 'Enviando...' : 'Enviar propuesta'}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>
    );
}
