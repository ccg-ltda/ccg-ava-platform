import { router } from '@inertiajs/react';
import { Bot, CheckCheck, UserRoundCheck } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/Components/ConfirmDialog';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Select from '@/Components/Select';

/** What the banner says about who answers, and why. */
function describe(selected) {
    const who = selected.assignedUser?.isMe ? 'ti' : selected.assignedUser?.name;

    return {
        ai: { tone: 'info', text: 'La IA responde sola.' },
        pending: { tone: 'warning', text: `Espera a un agente${selected.handoffAt ? ` desde ${selected.handoffAt}` : ''}${selected.handoffReason ? `. Motivo: ${selected.handoffReason}` : ''}. La IA no responde.` },
        human: { tone: 'info', text: `La atiende ${who ?? 'un agente'}. La IA no responde.` },
        resolved: { tone: 'success', text: 'Resuelta. Se reabre con la IA si el contacto escribe.' },
    }[selected.handling];
}

/**
 * The state of the conversation and the actions the server says the user has on it (`selected.can`): take it, give it
 * back to the AI, resolve it and, for a manager, assign it. The buttons only reflect the permissions; the server checks
 * them again and refuses what the state does not allow.
 */
export default function ChatActions({ selected, agents }) {
    const confirm = useConfirm();
    const [busy, setBusy] = useState(false);
    const [agent, setAgent] = useState(null);
    const { can } = selected;
    const banner = describe(selected);

    const post = (name, data = {}) => router.post(route(name, { conversation: selected.id }), data, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    const release = async () => {
        if (await confirm({ title: 'Devolver a la IA', description: 'La IA volverá a responder a este contacto de inmediato.', confirmLabel: 'Devolver a la IA' })) {
            post('conversations.release');
        }
    };

    const resolve = async () => {
        if (await confirm({ title: 'Resolver la conversación', description: 'Se marcará como resuelta. La IA no responderá hasta que el contacto vuelva a escribir.', confirmLabel: 'Resolver' })) {
            post('conversations.resolve');
        }
    };

    const options = agents.filter((candidate) => candidate.id !== selected.assignedUser?.id).map((candidate) => ({ value: candidate.id, label: candidate.name }));

    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line bg-card px-3 py-2">
            <p className={`min-w-0 flex-1 basis-48 text-xs leading-snug ${banner.tone === 'warning' ? 'font-semibold text-accent-amber' : 'text-ink-muted'}`}>{banner.text}</p>

            {(can.take || can.release || can.resolve || can.assign) && (
                <div className="flex flex-wrap items-center gap-2">
                    {can.take && (
                        <PrimaryButton type="button" disabled={busy} onClick={() => post('conversations.take')} className="gap-1.5 py-1.5!">
                            <UserRoundCheck className="size-4" aria-hidden="true" />
                            Tomar conversación
                        </PrimaryButton>
                    )}
                    {can.release && (
                        <SecondaryButton disabled={busy} onClick={release} className="gap-1.5 py-1.5!">
                            <Bot className="size-4" aria-hidden="true" />
                            Devolver a la IA
                        </SecondaryButton>
                    )}
                    {can.resolve && (
                        <SecondaryButton disabled={busy} onClick={resolve} className="gap-1.5 py-1.5!">
                            <CheckCheck className="size-4" aria-hidden="true" />
                            Resolver
                        </SecondaryButton>
                    )}
                    {can.assign && options.length > 0 && (
                        <div className="flex w-full items-center gap-2 sm:w-auto">
                            <Select value={agent} onChange={setAgent} options={options} placeholder="Asignar a…" size="sm" className="sm:w-40" aria-label="Agente al que asignar la conversación" />
                            <SecondaryButton disabled={busy || agent === null} className="py-1.5!" onClick={() => post('conversations.assign', { user_id: agent })}>
                                Asignar
                            </SecondaryButton>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
