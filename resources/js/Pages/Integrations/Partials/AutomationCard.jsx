import { Link } from '@inertiajs/react';
import { Workflow } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import CopyBlock from '@/Components/CopyBlock';

/**
 * What the Workspace can verify about n8n. "Conectado" means n8n DID read a chatbot's configuration from Ava (the
 * only evidence Ava has); it says nothing about WhatsApp or the AI model, which run inside n8n.
 */
const STATES = {
    not_configured: { label: 'No configurado', tone: 'amber' },
    configured: { label: 'Configurado', tone: 'blue' },
    connected: { label: 'Conectado', tone: 'green' },
};

/** n8n as a first-class integration: its purpose, its state and, per chatbot, the token and the last read. */
export default function AutomationCard({ automation, workspaceId }) {
    const state = STATES[automation.state];

    return (
        <Card className="space-y-5 p-5 sm:p-6">
            <div className="flex flex-wrap items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <Workflow className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-[14rem] flex-1">
                    <h3 className="text-base font-bold text-ink">n8n</h3>
                    <p className="text-sm text-ink-muted">
                        La capa de automatización que conecta cada chatbot con sus canales y ejecuta el flujo de IA. Ava le entrega la identidad y las instrucciones del chatbot; n8n no guarda otra copia.
                    </p>
                </div>
                <Badge tone={state.tone}>{state.label}</Badge>
            </div>

            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-ink-muted">Última lectura de n8n</dt>
                    <dd className="font-semibold text-ink">{automation.lastSeen ?? 'Todavía ninguna'}</dd>
                </div>
                <div>
                    <dt className="text-ink-muted">Chatbots con token</dt>
                    <dd className="font-semibold text-ink">
                        {automation.chatbots.filter((chatbot) => chatbot.hasToken).length} de {automation.chatbots.length}
                    </dd>
                </div>
            </dl>

            <div className="space-y-2">
                <p className="text-sm font-semibold text-ink">Dirección que lee n8n (GET)</p>
                <CopyBlock value={automation.endpoint} label="Dirección de la configuración del chatbot" />
            </div>

            {automation.chatbots.length === 0 ? (
                <p className="text-sm text-ink-muted">Este Workspace no tiene chatbots todavía.</p>
            ) : (
                <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Chatbots y su acceso para n8n">
                    {automation.chatbots.map((chatbot) => (
                        <li key={chatbot.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 text-sm">
                            <span className="min-w-0 font-semibold text-ink">
                                {chatbot.name}
                                {!chatbot.isActive && <span className="ml-2 font-normal text-ink-muted">(inactivo)</span>}
                            </span>
                            <span className="text-ink-muted">
                                {chatbot.hasToken ? <>Token ••••{chatbot.hint}</> : 'Sin token'} · {chatbot.lastSeen ? `leído ${chatbot.lastSeen}` : 'sin lecturas'}
                            </span>
                            <Link
                                href={route('chatbots.show', { chatbot: chatbot.id, tab: 'ia', ...(workspaceId ? { workspace: workspaceId } : {}) })}
                                className="font-semibold text-primary underline-offset-2 hover:underline"
                            >
                                Gestionar
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-xs text-ink-muted">«Conectado» significa que n8n leyó la configuración de algún chatbot. No comprueba WhatsApp ni el modelo de IA, que se ejecutan en n8n.</p>
        </Card>
    );
}
