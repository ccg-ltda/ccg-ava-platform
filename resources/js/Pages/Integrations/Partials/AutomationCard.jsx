import { Link, router, usePage } from '@inertiajs/react';
import { PlugZap, Settings2, Unplug, Workflow, X } from 'lucide-react';
import { useState } from 'react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import CopyBlock from '@/Components/CopyBlock';
import Modal from '@/Components/Modal';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import N8nConfigModal from './N8nConfigModal';

/** What Ava can really say about its own connection to the n8n API: only a real test makes it "conectado". */
const CONNECTION = {
    not_configured: { label: 'No configurado', tone: 'amber' },
    configured: { label: 'Sin probar', tone: 'blue' },
    verified: { label: 'n8n conectado', tone: 'green' },
    error: { label: 'Error de conexión', tone: 'red' },
};

/** What Ava can really say: only an authenticated call with the token counts, and it proves the token was used, not which endpoint (the instructions have their own confirmation: «Sincronizado» only after n8n READ them). */
const WORKFLOW = {
    not_configured: { label: 'Sin token', tone: 'amber' },
    configured: { label: 'Esperando a n8n', tone: 'blue' },
    connected: { label: 'El token ya fue usado desde n8n', tone: 'green' },
};

function Step({ number, title, children }) {
    return (
        <section aria-labelledby={`n8n-step-${number}`} className="space-y-4 rounded-xl border border-line bg-card p-4 sm:p-5">
            <h4 id={`n8n-step-${number}`} className="flex items-center gap-2.5 text-base font-bold text-ink">
                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-xs text-white" aria-hidden="true">
                    {number}
                </span>
                {title}
            </h4>
            {children}
        </section>
    );
}

/** Ava -> n8n: the instance address and API Key, their real test, and the actions on the connection. */
function Connection({ connection, readOnly, onConfigure }) {
    const confirm = useConfirm();
    const [testing, setTesting] = useState(false);

    const test = () =>
        router.post(route('integrations.test', connection.id), {}, { preserveScroll: true, onStart: () => setTesting(true), onFinish: () => setTesting(false) });

    const disconnect = async () => {
        const confirmed = await confirm({
            description: 'Ava dejará de guardar la API Key de n8n. Tus workflows en n8n no se tocan y el token de cada chatbot sigue funcionando.',
            confirmLabel: 'Desconectar',
        });

        if (confirmed) router.delete(route('integrations.n8n.destroy'), { preserveScroll: true });
    };

    if (connection.state === 'not_configured') {
        return (
            <div className="space-y-3">
                <p className="text-sm text-ink-muted">Conecta tu instancia de n8n con Ava para permitir que tus automatizaciones trabajen con tus chatbots.</p>
                {readOnly ? <p className="text-sm text-ink-muted">Este Workspace todavía no ha conectado su n8n.</p> : (
                    <PrimaryButton type="button" onClick={onConfigure} className="gap-2">
                        <Settings2 className="size-4" aria-hidden="true" />
                        Configurar n8n
                    </PrimaryButton>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <dl className="divide-y divide-line rounded-lg border border-line text-sm [&>div]:flex [&>div]:flex-col [&>div]:gap-0.5 [&>div]:px-3 [&>div]:py-2 sm:[&>div]:flex-row sm:[&>div]:items-center sm:[&>div]:justify-between sm:[&>div]:gap-3">
                <div>
                    <dt className="shrink-0 text-ink-muted">URL de tu n8n</dt>
                    <dd className="min-w-0 break-all font-semibold text-ink sm:truncate sm:text-right" title={connection.baseUrl}>
                        {connection.baseUrl}
                    </dd>
                </div>
                <div>
                    <dt className="text-ink-muted">API Key</dt>
                    <dd className="font-semibold text-ink">{connection.apiKeySet ? '•••••••• guardada' : 'Sin API Key'}</dd>
                </div>
                <div>
                    <dt className="text-ink-muted">Actualizada</dt>
                    <dd className="text-ink">{connection.updatedAt}</dd>
                </div>
            </dl>

            {connection.lastTest ? (
                <p className={`rounded-lg border p-3 text-sm ${connection.lastTest.ok ? 'border-accent-green/25 bg-accent-green/10' : 'border-danger/25 bg-danger/10'}`}>
                    <strong>{connection.lastTest.ok ? '✓ ' : ''}</strong>
                    {connection.lastTest.message}
                    {connection.lastTest.ok && connection.host && <span className="block text-xs text-ink-muted">Instancia: {connection.host}</span>}
                    <span className="mt-0.5 block text-xs text-ink-muted">{connection.lastTest.at}</span>
                </p>
            ) : (
                <p className="text-sm text-ink-muted">Guardada, pero todavía no probada: pulsa «Probar conexión» para comprobar que Ava puede entrar a tu n8n.</p>
            )}

            {!readOnly && (
                <div className="flex flex-wrap gap-2">
                    <PrimaryButton type="button" onClick={test} disabled={testing} className="gap-2">
                        <PlugZap className="size-4" aria-hidden="true" />
                        {testing ? 'Probando...' : 'Probar conexión'}
                    </PrimaryButton>
                    <SecondaryButton type="button" onClick={onConfigure} className="gap-2">
                        <Settings2 className="size-4" aria-hidden="true" />
                        Cambiar configuración
                    </SecondaryButton>
                    <SecondaryButton type="button" onClick={disconnect} className="gap-2 text-danger">
                        <Unplug className="size-4" aria-hidden="true" />
                        Desconectar
                    </SecondaryButton>
                </div>
            )}
        </div>
    );
}

/** n8n -> Ava: where the workflow sends things and the token of each chatbot (shown once, when generated). */
function Workflow_({ automation, newToken, readOnly, workspaceId }) {
    const confirm = useConfirm();
    const { auth } = usePage().props;
    const canGenerate = !readOnly && auth.user.permissions.includes('manage-agent-tokens');
    const state = WORKFLOW[automation.state];

    const generate = async (chatbot) => {
        const replacing = chatbot.hasToken;
        const confirmed = await confirm({
            description: replacing ? `El token actual de ${chatbot.name} dejará de funcionar: tendrás que actualizarlo en n8n.` : `Se generará el token de ${chatbot.name}. Solo se mostrará una vez, en cuanto lo generes.`,
            confirmLabel: replacing ? 'Generar nuevo token' : 'Generar token',
        });

        if (confirmed) router.post(route('chatbots.agent-token.generate', chatbot.id), {}, { preserveScroll: true });
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-ink-muted">
                La conexión anterior permite que Ava se comunique con tu n8n. Para que un workflow de n8n pueda leer la configuración de un chatbot y enviar sus mensajes a Ava, debe usar las credenciales que Ava da a cada chatbot.
            </p>

            <div className="space-y-2">
                <p className="text-sm font-semibold text-ink">Dirección de Ava para leer la configuración del chatbot (GET)</p>
                <CopyBlock value={automation.endpoint} label="Dirección para leer la configuración del chatbot" />
            </div>
            <div className="space-y-2">
                <p className="text-sm font-semibold text-ink">Dirección de Ava para enviar mensajes (POST)</p>
                <CopyBlock value={automation.messagesEndpoint} label="Dirección para enviar mensajes a Ava" />
            </div>

            <details className="rounded-lg border border-line px-4 py-3 text-sm">
                <summary className="cursor-pointer font-semibold text-ink">Ejemplos cURL para probar o configurar tu workflow</summary>
                <div className="mt-3 space-y-4">
                    <Alert tone="info">
                        cURL es solo una forma de hacer la petición HTTP: no reemplaza la API ni el token. Define primero tu token en la terminal (<code>export {automation.curl.tokenVariable}=&lt;token del chatbot&gt;</code>) y copia los comandos tal cual. En n8n usa el nodo «HTTP Request» con el mismo método, dirección, cabeceras y cuerpo, y guarda el token como credencial de n8n, no dentro del flujo. Ava nunca incluye el token en estos comandos.
                    </Alert>
                    {automation.curl.examples.map((example) => (
                        <div key={example.key} className="space-y-1.5">
                            <p className="font-semibold text-ink">
                                {example.title} <span className="font-mono text-xs font-normal text-ink-muted">{example.method}</span>
                            </p>
                            <p className="text-xs text-ink-muted">{example.description}</p>
                            <CopyBlock value={example.command} label={`Comando cURL: ${example.title}`} />
                            <p className="text-xs text-ink-muted">Respuesta esperada: {example.expect}</p>
                        </div>
                    ))}
                </div>
            </details>

            <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-semibold text-ink">Token del chatbot</p>
                <Badge tone={state.tone}>{state.label}</Badge>
                {automation.lastSeen && <span className="text-xs text-ink-muted">Última actividad con el token: {automation.lastSeen}</span>}
            </div>
            <Alert tone="info">Este token identifica a cada chatbot cuando n8n envía información a Ava. No es la API Key de n8n. Por seguridad, Ava solo lo muestra en el momento de generarlo.</Alert>

            {automation.chatbots.length === 0 ? (
                <p className="text-sm text-ink-muted">Este Workspace no tiene chatbots todavía.</p>
            ) : (
                <ul className="divide-y divide-line rounded-lg border border-line" aria-label="Token de cada chatbot">
                    {automation.chatbots.map((chatbot) => (
                        <li key={chatbot.id} className="space-y-3 px-4 py-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                                <span className="min-w-0 font-semibold text-ink">
                                    {chatbot.name}
                                    {!chatbot.isActive && <span className="ml-2 font-normal text-ink-muted">(inactivo)</span>}
                                </span>
                                <span className="text-ink-muted">
                                    {chatbot.hasToken ? <>Token ••••{chatbot.hint}</> : 'Sin token'} · {chatbot.lastSeen ? `usado ${chatbot.lastSeen}` : 'sin uso'}
                                </span>
                                <span className="flex flex-wrap gap-2">
                                    {canGenerate && (
                                        <SecondaryButton type="button" onClick={() => generate(chatbot)}>
                                            {chatbot.hasToken ? 'Generar nuevo token' : 'Generar token'}
                                        </SecondaryButton>
                                    )}
                                    <Link href={route('chatbots.show', { chatbot: chatbot.id, tab: 'ia', ...(workspaceId ? { workspace: workspaceId } : {}) })} className="inline-flex items-center px-2 font-semibold text-primary underline-offset-2 hover:underline">
                                        Gestionar
                                    </Link>
                                </span>
                            </div>

                            {newToken?.chatbotId === chatbot.id && (
                                <div className="space-y-2">
                                    <Alert tone="warning">Copia el token ahora: por seguridad no se volverá a mostrar.</Alert>
                                    <CopyBlock value={newToken.token} label={`Token del chatbot ${chatbot.name}`} />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <div className="space-y-1.5 text-sm text-ink-muted">
                <p className="font-semibold text-ink">Dónde se usa en n8n</p>
                <ol className="list-decimal space-y-1 pl-5">
                    <li>
                        En n8n crea una credencial <strong className="text-ink">Header Auth</strong> con nombre <code className="font-mono text-ink">Authorization</code> y valor <code className="font-mono text-ink">Bearer &lt;token del chatbot&gt;</code>.
                    </li>
                    <li>Úsala en un nodo HTTP Request que lea la dirección de configuración y en los que envíen mensajes a Ava.</li>
                </ol>
            </div>
        </div>
    );
}

/** The detail of n8n: the three steps (connect, configure the workflow, where the conversations appear), in a wide dialog with its own scroll. */
function N8nPanel({ automation, newToken, readOnly, workspaceId, onClose, onConfigure }) {
    const { connection } = automation;
    const state = CONNECTION[connection.state];

    return (
        <Modal show onClose={onClose} maxWidth="6xl" scrollable>
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="flex shrink-0 items-start justify-between gap-3 border-b border-line p-4 sm:px-6">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                            <Workflow className="size-5" aria-hidden="true" />
                        </span>
                        <div className="min-w-0">
                            <h2 className="text-lg font-bold text-ink">Configuración de n8n</h2>
                            <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                <Badge tone={state.tone}>{state.label}</Badge>
                                {readOnly && <span className="text-xs text-ink-muted">Solo lectura</span>}
                            </div>
                        </div>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="rounded-md p-1.5 text-ink-muted hover:bg-canvas focus-visible:outline-2 focus-visible:outline-primary">
                        <X className="size-5" aria-hidden="true" />
                    </button>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-canvas/50 p-4 sm:p-6" tabIndex={0} aria-label="Configuración de n8n">
                    <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                        <div className="space-y-5">
                            <Step number="1" title="Conecta tu n8n con Ava">
                                <Connection connection={connection} readOnly={readOnly} onConfigure={onConfigure} />
                            </Step>

                            <Step number="3" title="Dónde verás las conversaciones">
                                <p className="text-sm text-ink-muted">
                                    Conectar n8n no conecta WhatsApp, y configurar WhatsApp no conecta n8n: cada pieza se configura por separado. Cuando ambas estén listas y tu workflow envíe los mensajes a Ava, aparecerán en <strong className="text-ink">Asistentes → tu chatbot → Canales → WhatsApp → Conversaciones</strong>. Ava no crea conversaciones por su cuenta: solo muestra los mensajes que n8n le envía de verdad.
                                </p>
                            </Step>
                        </div>

                        <Step number="2" title="Configura tu workflow de n8n">
                            <Workflow_ automation={automation} newToken={newToken} readOnly={readOnly} workspaceId={workspaceId} />
                        </Step>
                    </div>
                </div>

                <footer className="flex shrink-0 justify-end border-t border-line p-4 sm:px-6">
                    <SecondaryButton type="button" onClick={onClose}>
                        Cerrar
                    </SecondaryButton>
                </footer>
            </div>
        </Modal>
    );
}

/**
 * n8n as an integration, as a compact card like the channels'. Two separate things live behind its panel, never mixed: (1) Ava -> n8n, the instance
 * address and API Key Ava uses to reach the n8n API, verified only by a real test; (2) n8n -> Ava, the token of each chatbot and the addresses the
 * workflow uses. The badge says what Ava can really show: nothing saved, saved but untested, tested, or the last test failed. Neither says anything
 * about WhatsApp, which is configured on its own.
 */
export default function AutomationCard({ automation, newToken, readOnly, workspaceId }) {
    const [configuring, setConfiguring] = useState(false);
    // A token just generated is shown once: the panel opens on its own so it is not lost behind the card.
    const [open, setOpen] = useState(Boolean(newToken));
    const { connection } = automation;
    const state = CONNECTION[connection.state];
    const configured = connection.state !== 'not_configured';

    return (
        <Card className="flex h-full flex-col p-5">
            <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <Workflow className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-bold text-ink">n8n</h3>
                    <p className="text-sm text-ink-muted">Automatización que conecta tus chatbots con WhatsApp y la IA.</p>
                </div>
                <Badge tone={state.tone}>{state.label}</Badge>
            </div>

            <p className="mt-3 text-xs text-ink-muted">
                {configured ? connection.lastTest ? connection.lastTest.message : 'Guardada, pero todavía no probada.' : 'Aún no hay una instancia de n8n conectada.'}
            </p>

            <div className="mt-auto flex justify-end pt-4">
                <SecondaryButton type="button" onClick={() => setOpen(true)} className="gap-2">
                    <Settings2 className="size-4" aria-hidden="true" />
                    {readOnly ? 'Ver configuración' : configured ? 'Gestionar configuración' : 'Configurar'}
                </SecondaryButton>
            </div>

            {open && <N8nPanel automation={automation} newToken={newToken} readOnly={readOnly} workspaceId={workspaceId} onClose={() => setOpen(false)} onConfigure={() => setConfiguring(true)} />}
            {configuring && <N8nConfigModal connection={connection} onClose={() => setConfiguring(false)} />}
        </Card>
    );
}
