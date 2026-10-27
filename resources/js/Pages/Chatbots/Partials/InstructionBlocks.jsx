import { router, useForm } from '@inertiajs/react';
import { Check, CloudOff, MessageSquareWarning, RefreshCw } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import { diffLines, hasChanges } from '@/lib/diff';

const STATE = {
    never: { tone: 'amber', icon: CloudOff, title: 'Guardado en Zonno · sin confirmar por n8n' },
    pending: { tone: 'amber', icon: RefreshCw, title: 'Guardado en Zonno · pendiente de sincronizar' },
    synced: { tone: 'green', icon: Check, title: 'Sincronizado con n8n' },
};

const MODES = [
    { key: 'single', title: 'Single', summary: 'Un solo prompt completo', detail: 'Escribes todas las instrucciones en un único texto. Es lo más simple y es el modo de los asistentes que ya existían.' },
    { key: 'modular', title: 'Modular', summary: 'Tres bloques independientes', detail: 'Separas identidad, memoria y conocimiento, y comportamiento. Cada bloque se guarda por separado y se puede revisar y mejorar sin tocar los otros.' },
];

/** Where the instructions stand: the saved version and what n8n has confirmed. «Sincronizado» appears only when n8n confirmed that version. */
function Status({ status }) {
    const state = STATE[status.state];
    const Icon = state.icon;

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" role="status">
            <Badge tone={state.tone}>
                <span className="inline-flex items-center gap-1.5">
                    <Icon className="size-3.5" aria-hidden="true" />
                    {state.title}
                </span>
            </Badge>
            <span className="text-ink-muted">Versión {status.version}</span>
            {status.syncedAt && <span className="text-xs text-ink-muted">n8n confirmó la v{status.syncedVersion} el {status.syncedAt}</span>}
            <span className="w-full text-xs text-ink-muted">{status.label}</span>
        </div>
    );
}

/** The explicit choice of mode. Switching is a versioned change (it changes what n8n receives) and never deletes the content of the other mode. */
function ModeSelector({ chatbot, mode, canEdit }) {
    const confirm = useConfirm();

    const choose = async (key) => {
        if (key === mode || !canEdit) return;

        const target = MODES.find((item) => item.key === key);
        const confirmed = await confirm({
            description: `Se usará el modo ${target.title}: n8n recibirá solo ese contenido a partir de la próxima lectura. El contenido del otro modo se conserva sin cambios y no se envía.`,
            confirmLabel: `Cambiar a ${target.title}`,
        });

        if (confirmed) router.post(route('chatbots.instructions.mode', chatbot.id), { mode: key }, { preserveScroll: true });
    };

    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-semibold text-ink">Modo de configuración</legend>
            <div className="grid gap-3 sm:grid-cols-2">
                {MODES.map((item) => (
                    <label key={item.key} className={`flex gap-3 rounded-xl border p-4 ${mode === item.key ? 'border-primary bg-primary/10' : 'border-line'} ${canEdit ? 'cursor-pointer' : 'cursor-default opacity-80'}`}>
                        <input type="radio" name={`instruction_mode_${chatbot.id}`} className="mt-1 size-4" checked={mode === item.key} disabled={!canEdit} onChange={() => choose(item.key)} />
                        <span>
                            <span className="block text-sm font-bold text-ink">
                                {item.title} · {item.summary}
                            </span>
                            <span className="block text-xs text-ink-muted">{item.detail}</span>
                        </span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

/** One block with its own form: saving it never sends or changes the others. The hash of what was opened protects against overwriting somebody else's change. */
function Block({ chatbot, block, max, canEdit }) {
    const form = useForm({ text: block.text, expected_hash: block.hash });
    const error = form.errors[block.key] ?? form.errors.text;

    const submit = (event) => {
        event.preventDefault();
        form.post(route('chatbots.instructions.update', [chatbot.id, block.key]), { preserveScroll: true, onSuccess: (page) => {
            const saved = page.props.chatbot.instructionBlocks.find((item) => item.key === block.key);
            form.setDefaults({ text: saved?.text ?? '', expected_hash: saved?.hash ?? '' });
            form.setData({ text: saved?.text ?? '', expected_hash: saved?.hash ?? '' });
        } });
    };

    return (
        <form onSubmit={submit} noValidate className="space-y-2 rounded-xl border border-line p-4" aria-label={block.label}>
            <InputLabel htmlFor={`instr_${block.key}`} value={block.label} />
            <p className="text-xs text-ink-muted">{block.help}</p>
            <Textarea id={`instr_${block.key}`} rows={block.mode === 'single' ? 14 : 6} value={form.data.text} maxLength={max} onChange={(e) => form.setData('text', e.target.value)} invalid={Boolean(error)} disabled={!canEdit} />
            <div className="flex items-start justify-between gap-3">
                <InputError message={error} />
                <p className="ml-auto shrink-0 text-xs text-ink-muted">
                    {form.data.text.length} / {max}
                </p>
            </div>
            {canEdit && (
                <div className="flex justify-end">
                    <PrimaryButton type="submit" disabled={form.processing || !form.isDirty}>
                        {form.processing ? 'Guardando...' : block.mode === 'single' ? 'Guardar prompt' : 'Guardar este bloque'}
                    </PrimaryButton>
                </div>
            )}
        </form>
    );
}

/** A proposal made from a conversation, side by side with the current text; applied only when a person approves it. */
function Proposal({ chatbot, item }) {
    const confirm = useConfirm();
    const lines = diffLines(item.current, item.proposed);

    const approve = async () => {
        const confirmed = await confirm({ description: `Se guardará una versión nueva de «${item.blockLabel}» con el contenido propuesto. Lo demás no cambia.`, confirmLabel: 'Aprobar y guardar' });

        if (confirmed) router.post(route('chatbots.feedback.approve', [chatbot.id, item.id]), {}, { preserveScroll: true });
    };

    return (
        <li className="space-y-3 rounded-xl border border-line p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <p className="text-sm font-semibold text-ink">
                    Cambiar «{item.blockLabel}» <span className="font-normal text-ink-muted">· propuesto por {item.author ?? 'una persona'}</span>
                </p>
                {item.stale && <Badge tone="amber">Desactualizada</Badge>}
            </div>
            <dl className="space-y-1 text-sm">
                <div>
                    <dt className="text-xs text-ink-muted">Respuesta que no gustó</dt>
                    <dd className="rounded-lg bg-canvas px-3 py-2 text-ink">{item.reply || '—'}</dd>
                </div>
                <div>
                    <dt className="text-xs text-ink-muted">Motivo</dt>
                    <dd className="text-ink">{item.reason}</dd>
                </div>
                {item.desiredBehavior && (
                    <div>
                        <dt className="text-xs text-ink-muted">Comportamiento deseado</dt>
                        <dd className="text-ink">{item.desiredBehavior}</dd>
                    </div>
                )}
            </dl>
            <div>
                <p className="mb-1 text-xs font-semibold text-ink-muted">Comparación (− se quita · + se añade)</p>
                <pre className="max-h-64 overflow-auto rounded-lg border border-line bg-canvas p-3 font-mono text-[13px] whitespace-pre-wrap" aria-label="Comparación del apartado">
                    {hasChanges(lines) ? (
                        lines.map((line, index) => (
                            <span key={index} className={`block ${line.type === 'added' ? 'bg-accent-green/15 text-ink' : line.type === 'removed' ? 'bg-danger/10 text-danger line-through' : 'text-ink-muted'}`}>
                                {line.type === 'added' ? '+ ' : line.type === 'removed' ? '− ' : '  '}
                                {line.text}
                            </span>
                        ))
                    ) : (
                        'Sin diferencias'
                    )}
                </pre>
            </div>
            {item.stale && <Alert tone="warning">El apartado cambió después de esta propuesta: ya no se puede aprobar. Recházala y propón el cambio de nuevo sobre el texto actual.</Alert>}
            <div className="flex flex-wrap justify-end gap-2">
                <SecondaryButton type="button" onClick={() => router.post(route('chatbots.feedback.reject', [chatbot.id, item.id]), {}, { preserveScroll: true })}>
                    Rechazar
                </SecondaryButton>
                <PrimaryButton type="button" onClick={approve} disabled={item.stale}>
                    Aprobar y guardar
                </PrimaryButton>
            </div>
        </li>
    );
}

/**
 * The instructions of the assistant in the mode the person chose (Single: one prompt / Modular: three independent blocks), the state of the synchronization with n8n, the
 * proposals waiting for review and the history of versions. Each block saves on its own; the server decides what is valid.
 */
export default function InstructionBlocks({ chatbot, feedback, canEdit, max }) {
    const mode = chatbot.instructionStatus.mode;
    const shown = chatbot.instructionBlocks.filter((block) => block.mode === mode);
    const hidden = chatbot.instructionBlocks.filter((block) => block.mode !== mode && block.chars > 0);

    return (
        <Card className="space-y-5 p-5 sm:p-6" aria-label="Instrucciones del asistente">
            <div>
                <h3 className="text-base font-bold text-ink">Instrucciones del asistente</h3>
                <p className="text-sm text-ink-muted">Elige cómo configurarlo. Zonno es la única fuente: n8n lee de aquí el contenido del modo activo y no escribe su propio prompt.</p>
            </div>

            <ModeSelector chatbot={chatbot} mode={mode} canEdit={canEdit} />

            <Status status={chatbot.instructionStatus} />

            {mode === 'modular' && <Alert tone="info">En modo Modular, n8n recibe los tres bloques (los que tengan texto) en este orden y bajo su título. El prompt del modo Single no se mezcla ni se envía.</Alert>}

            <div className="space-y-4">
                {shown.map((block) => (
                    <Block key={`${block.key}-${block.hash}`} chatbot={chatbot} block={block} max={max} canEdit={canEdit} />
                ))}
            </div>

            {hidden.length > 0 && (
                <p className="rounded-lg bg-canvas px-3 py-2 text-xs text-ink-muted">
                    Guardado y sin enviar (modo {mode === 'single' ? 'Modular' : 'Single'}): {hidden.map((block) => `${block.label} (${block.chars} caracteres)`).join(' · ')}. Cambia de modo para verlo y editarlo.
                </p>
            )}

            {feedback.length > 0 && (
                <section aria-label="Propuestas pendientes" className="space-y-3 border-t border-line pt-5">
                    <h4 className="flex items-center gap-2 text-sm font-bold text-ink">
                        <MessageSquareWarning className="size-4 text-accent-amber" aria-hidden="true" />
                        Propuestas desde conversaciones ({feedback.length})
                    </h4>
                    <p className="text-xs text-ink-muted">Nada cambia hasta que una persona autorizada apruebe. Las reglas de seguridad fijas de Zonno no forman parte de ningún apartado y no se pueden editar desde aquí.</p>
                    <ul className="space-y-3">
                        {feedback.map((item) => (
                            <Proposal key={item.id} chatbot={chatbot} item={item} />
                        ))}
                    </ul>
                </section>
            )}

            {chatbot.instructionVersions.length > 0 && (
                <details className="rounded-lg border border-line px-4 py-3 text-sm">
                    <summary className="cursor-pointer font-semibold text-ink">Historial de versiones</summary>
                    <ul className="mt-3 divide-y divide-line">
                        {chatbot.instructionVersions.map((version) => (
                            <li key={version.version} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span className="font-medium text-ink">v{version.version}</span>
                                <span className="text-ink-muted">
                                    {version.blocks.length > 0 ? version.blocks.join(', ') : 'Versión inicial'}
                                    {version.source === 'feedback' ? ' · desde una retroalimentación' : ''}
                                </span>
                                <span className="text-xs text-ink-muted">
                                    {version.author ?? 'Sistema'} · {version.at}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </Card>
    );
}
