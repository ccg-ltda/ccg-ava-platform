import { router } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/Components/ConfirmDialog';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

/** Posts a demo action and keeps the page where it is. */
const post = (name, params, data, setBusy) => router.post(route(name, params), data, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

/**
 * The demo environment of the inbox (only in local/development): generate, reset or clean the fictitious scenarios of the
 * administrative Workspace. Everything it creates is marked as demo and nothing of it reaches a real channel.
 */
export function DemoPanel({ demo }) {
    const confirm = useConfirm();
    const [busy, setBusy] = useState(false);

    const run = async (action, confirmation) => {
        if (!confirmation || (await confirm(confirmation))) {
            post('conversations.demo', { action }, {}, setBusy);
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="inline-flex items-center gap-1.5 text-xs text-ink-muted" title="Las conversaciones demo son ficticias: nada se envía a ningún proveedor.">
                    <FlaskConical className="size-4 text-accent-violet" aria-hidden="true" />
                    <strong className="text-ink">DEMO</strong> {demo.demo}/{demo.total}
                </span>
                {demo.canManage && (
                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton disabled={busy} onClick={() => run('generate')} className="py-1.5!">
                            Generar
                        </SecondaryButton>
                        <SecondaryButton className="py-1.5!" disabled={busy} onClick={() => run('reset', { title: 'Reiniciar los escenarios demo', description: 'Se borrarán las conversaciones demo (y lo que hayas simulado en ellas) y se volverán a crear. Las conversaciones reales no se tocan.', confirmLabel: 'Reiniciar' })}>
                            Reiniciar
                        </SecondaryButton>
                        <SecondaryButton className="py-1.5!" disabled={busy} onClick={() => run('clean', { title: 'Limpiar los datos demo', description: 'Se borrarán solo las conversaciones marcadas como demo. Las conversaciones reales no se tocan.', confirmLabel: 'Limpiar' })}>
                            Limpiar
                        </SecondaryButton>
                    </div>
                )}
            </div>
        </div>
    );
}

/** What a manager can simulate on a DEMO conversation: the contact writes, the "AI" answers, the assistant asks for a person. */
export function SimulationBar({ conversationId }) {
    const [busy, setBusy] = useState(false);
    const [text, setText] = useState('');

    const incoming = (event) => {
        event.preventDefault();

        if (text.trim() !== '') {
            router.post(route('conversations.simulate.incoming', { conversation: conversationId }), { body: text }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false), onSuccess: (page) => !page.props.flash?.error && setText('') });
        }
    };

    return (
        <details className="border-b border-line bg-accent-violet/5 px-3 py-2">
            <summary className="cursor-pointer text-xs font-semibold text-accent-violet outline-none focus-visible:outline-2 focus-visible:outline-primary">
                Simulación DEMO <span className="font-normal text-ink-muted">· los mensajes que añadas son ficticios y no se envían a ningún proveedor</span>
            </summary>
            <form onSubmit={incoming} className="mt-2 flex flex-wrap items-center gap-2">
                <TextInput value={text} onChange={(event) => setText(event.target.value)} maxLength={1000} placeholder="Mensaje del contacto (simulado)" aria-label="Mensaje entrante simulado" className="min-w-0 flex-1 basis-56" />
                <SecondaryButton type="submit" disabled={busy || text.trim() === ''}>
                    Simular entrante
                </SecondaryButton>
                <SecondaryButton disabled={busy} onClick={() => post('conversations.simulate.ai-reply', { conversation: conversationId }, {}, setBusy)}>
                    Simular respuesta de IA
                </SecondaryButton>
                <SecondaryButton disabled={busy} onClick={() => post('conversations.simulate.handoff', { conversation: conversationId }, {}, setBusy)}>
                    Simular pedido de agente
                </SecondaryButton>
            </form>
        </details>
    );
}

/** Buttons that set the delivery state of a simulated outgoing message (what a channel's status webhook would report). */
export function StatusSimulator({ conversationId, messageId }) {
    return (
        <p className="mt-1 flex flex-wrap justify-end gap-x-3 text-[11px]">
            <span className="text-white/75">Simular estado:</span>
            {[
                ['sent', 'Enviado'],
                ['delivered', 'Entregado'],
                ['read', 'Leído'],
                ['failed', 'Falló'],
            ].map(([status, label]) => (
                <button key={status} type="button" onClick={() => router.post(route('conversations.simulate.status', { conversation: conversationId, message: messageId }), { status }, { preserveScroll: true })} className="font-semibold text-white underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-white">
                    {label}
                </button>
            ))}
        </p>
    );
}
