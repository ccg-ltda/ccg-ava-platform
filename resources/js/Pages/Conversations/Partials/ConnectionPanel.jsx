import { Link } from '@inertiajs/react';
import { Settings2 } from 'lucide-react';
import Alert from '@/Components/Alert';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import CopyBlock from '@/Components/CopyBlock';
import { channelStates } from '@/config/chatbots';

const SAMPLE = `{
  "channel": "whatsapp",
  "contact_id": "573001112233",
  "contact_name": "María Pérez",
  "direction": "in",
  "type": "text",
  "body": "Hola, ¿tienen envíos?",
  "external_id": "wamid.XXXX",
  "timestamp": 1790000000
}`;

/**
 * The technical side of the channel, kept apart from the conversations: what Ava knows about the account, what was
 * really verified, and how the automation reports messages. Credentials are never shown, only whether they work.
 */
export default function ConnectionPanel({ channel, chatbot, reportEndpoint, canConfigure, workspaceId }) {
    const { account, connection } = channel;
    const state = channelStates[channel.state];

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <Card className="space-y-4 p-5 sm:p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-base font-bold text-ink">Cuenta de {channel.label}</h2>
                    <Badge tone={state.tone}>{state.label}</Badge>
                </div>

                {account ? (
                    <dl className="space-y-1.5 text-sm">
                        {account.rows.map(({ label, value }) => (
                            <div key={label} className="flex items-center justify-between gap-3">
                                <dt className="shrink-0 text-ink-muted">{label}</dt>
                                <dd className="min-w-0 truncate font-semibold text-ink" title={value}>
                                    {value}
                                </dd>
                            </div>
                        ))}
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-ink-muted">Integración</dt>
                            <dd className="font-semibold text-ink">{account.isActive ? 'Activa' : 'Inactiva'}</dd>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <dt className="text-ink-muted">Actualizada</dt>
                            <dd className="text-ink">{account.updatedAt}</dd>
                        </div>
                    </dl>
                ) : (
                    <p className="text-sm text-ink-muted">Este Workspace todavía no ha configurado la cuenta de {channel.label}.</p>
                )}

                {connection ? (
                    <p className={`rounded-lg border p-3 text-sm ${connection.ok ? 'border-accent-green/25 bg-accent-green/10' : 'border-danger/25 bg-danger/10'}`}>
                        <strong>{connection.ok ? 'Conexión verificada. ' : 'Error de conexión. '}</strong>
                        {connection.message}
                        <span className="mt-0.5 block text-xs text-ink-muted">{connection.at}</span>
                    </p>
                ) : (
                    account && <p className="text-sm text-ink-muted">Conexión sin verificar. La prueba se hace desde Integraciones.</p>
                )}

                {canConfigure && (
                    <Link href={route('integrations.index')} className="inline-flex items-center gap-2 rounded-lg border border-line bg-card px-4 py-2 text-sm font-semibold text-ink transition-colors hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary">
                        <Settings2 className="size-4" aria-hidden="true" />
                        Configurar en Integraciones
                    </Link>
                )}
            </Card>

            <Card className="space-y-4 p-5 sm:p-6">
                <h2 className="text-base font-bold text-ink">Cómo llegan los mensajes a Ava</h2>
                <p className="text-sm text-ink-muted">
                    Ava no habla con {channel.label}: lo hace n8n. Para que la conversación aparezca aquí, el workflow reporta cada mensaje (entrante y saliente) con el token del chatbot,
                    {' '}
                    <Link href={route('chatbots.show', { chatbot: chatbot.id, tab: 'ia', ...(workspaceId ? { workspace: workspaceId } : {}) })} className="font-semibold text-primary underline-offset-2 hover:underline">
                        que se genera en IA y comportamiento
                    </Link>
                    .
                </p>

                <div className="space-y-2">
                    <p className="text-sm font-semibold text-ink">Reportar un mensaje (POST)</p>
                    <CopyBlock value={reportEndpoint} label="Dirección para reportar mensajes" />
                </div>

                <div className="space-y-2">
                    <p className="text-sm font-semibold text-ink">Ejemplo de cuerpo (JSON)</p>
                    <pre className="max-h-64 overflow-auto rounded-lg border border-line bg-canvas p-3 font-mono text-xs text-ink">{SAMPLE}</pre>
                    <p className="text-xs text-ink-muted">
                        <code className="font-mono">direction</code>: «in» (del contacto) o «out» (del chatbot). <code className="font-mono">type</code>: text, audio, image, video, document, sticker, location u other; en los multimedia, <code className="font-mono">body</code> es el pie o la transcripción. El estado de entrega de un mensaje saliente se actualiza con <code className="font-mono">POST /api/agent/messages/status</code>.
                    </p>
                </div>

                <Alert tone="info">Ava guarda el texto, el tipo y la hora; no descarga audio, imágenes ni archivos.</Alert>
                <Alert tone="warning">
                    Tener la cuenta de {channel.label} configurada no conecta n8n, y conectar n8n no conecta {channel.label}. Ambas piezas deben estar listas: la conexión de n8n se hace en Integraciones → n8n.
                </Alert>
            </Card>
        </div>
    );
}
