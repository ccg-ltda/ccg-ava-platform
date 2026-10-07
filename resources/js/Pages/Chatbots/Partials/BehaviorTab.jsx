import { router } from '@inertiajs/react';
import { KeyRound, Pencil } from 'lucide-react';
import Alert from '@/Components/Alert';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import CopyBlock from '@/Components/CopyBlock';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Textarea from '@/Components/Textarea';
import ProfileFields from './ProfileFields';

function SectionTitle({ icon: Icon, title, children }) {
    return (
        <div className="flex items-start gap-3">
            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                <Icon className="size-5" aria-hidden="true" />
            </span>
            <div className="min-w-0">
                <h3 className="text-base font-bold text-ink">{title}</h3>
                <p className="text-sm text-ink-muted">{children}</p>
            </div>
        </div>
    );
}

/** How an n8n workflow uses Ava as the single source of the chatbot's behavior. */
function Guide() {
    return (
        <ol className="list-decimal space-y-1.5 pl-5 text-sm text-ink-muted">
            <li>
                En n8n crea una credencial <strong className="text-ink">Header Auth</strong> con nombre <code className="font-mono text-ink">Authorization</code> y valor <code className="font-mono text-ink">Bearer &lt;token&gt;</code>.
            </li>
            <li>Al inicio del workflow, un nodo HTTP Request (GET) a la dirección de arriba con esa credencial.</li>
            <li>
                Usa <code className="font-mono text-ink">chatbot.instructions</code> como mensaje del sistema del agente de IA. No escribas otro prompt en n8n.
            </li>
            <li>
                WhatsApp: el WhatsApp Trigger y el envío usan las credenciales de WhatsApp de n8n. Compara el número que recibe el mensaje con <code className="font-mono text-ink">channels.whatsapp.phone_number_id</code>. Para ver la conversación en Ava, reporta cada mensaje con <code className="font-mono text-ink">POST /api/agent/messages</code> (ver la pestaña Conexión del canal).
            </li>
            <li>
                Web: un nodo Webhook (POST) que recibe <code className="font-mono text-ink">message</code> y <code className="font-mono text-ink">session_id</code> y responde con <code className="font-mono text-ink">{'{"reply": "..."}'}</code>.
            </li>
        </ol>
    );
}

/**
 * IA y comportamiento: where the chatbot is maintained. One form edits its identity and its instructions (Ava is the
 * only place the instructions live; n8n reads them from here), and below it the access token the automation uses.
 * The form belongs to the page; the server validates and stores it for the active Workspace.
 */
export default function BehaviorTab({ form, chatbot, canManage, instructionsMax, avatarMaxKb, onSubmit, agentToken }) {
    const confirm = useConfirm();
    const { data, setData, errors, processing, isDirty } = form;

    const generate = async () => {
        const replacing = agentToken.configured;
        const confirmed = await confirm({
            description: replacing
                ? 'El token actual dejará de funcionar: tendrás que actualizarlo en n8n.'
                : 'Se generará un token para que n8n lea la configuración de este chatbot. Solo se mostrará una vez.',
            confirmLabel: replacing ? 'Generar nuevo token' : 'Generar token',
        });

        if (confirmed) router.post(route('chatbots.agent-token.generate', chatbot.id), {}, { preserveScroll: true });
    };

    const revoke = async () => {
        const confirmed = await confirm({ description: 'n8n dejará de poder leer este chatbot hasta que generes otro token.', confirmLabel: 'Revocar' });

        if (confirmed) router.delete(route('chatbots.agent-token.revoke', chatbot.id), { preserveScroll: true });
    };

    return (
        <>
            <form onSubmit={onSubmit} noValidate>
                <Card className="space-y-6 p-5 sm:p-6">
                    <SectionTitle icon={Pencil} title="Editar el chatbot">
                        Su identidad y las instrucciones que definen cómo responde. Ava es la única fuente: n8n las lee de aquí.
                    </SectionTitle>

                    <ProfileFields form={form} chatbot={chatbot} canManage={canManage} avatarMaxKb={avatarMaxKb} />

                    <div>
                        <InputLabel htmlFor="chatbot_instructions" value="Instrucciones principales" />
                        <Textarea
                            id="chatbot_instructions"
                            className="mt-1"
                            rows={8}
                            value={data.instructions}
                            onChange={(e) => setData('instructions', e.target.value)}
                            invalid={Boolean(errors.instructions)}
                            maxLength={instructionsMax}
                            placeholder="Quién es el chatbot, cómo habla y qué puede o no puede responder."
                            disabled={!canManage}
                        />
                        <div className="mt-1 flex items-start justify-between gap-3">
                            <InputError message={errors.instructions} />
                            <p className="ml-auto shrink-0 text-xs text-ink-muted">
                                {data.instructions.length} / {instructionsMax}
                            </p>
                        </div>
                    </div>

                    {canManage && (
                        <div className="flex justify-end border-t border-line pt-4">
                            <PrimaryButton type="submit" disabled={processing || !isDirty || !data.name.trim()}>
                                {processing ? 'Guardando...' : 'Guardar cambios'}
                            </PrimaryButton>
                        </div>
                    )}
                </Card>
            </form>

            <Card className="space-y-5 p-5 sm:p-6">
                <SectionTitle icon={KeyRound} title="Acceso para n8n">
                    Ava no ejecuta ningún modelo: n8n lee de aquí las instrucciones y responde por los canales activos.
                </SectionTitle>

                <div className="space-y-2">
                    <p className="text-sm font-semibold text-ink">Dirección de la configuración (GET)</p>
                    <CopyBlock value={agentToken.endpoint} label="Dirección de la configuración del chatbot" />
                </div>

                <div className="space-y-3">
                    <p className="text-sm font-semibold text-ink">Token de acceso</p>

                    {agentToken.token && (
                        <>
                            <Alert tone="warning">Copia el token ahora: por seguridad no se volverá a mostrar.</Alert>
                            <CopyBlock value={agentToken.token} label="Token de acceso del chatbot" />
                        </>
                    )}

                    {!agentToken.token && (
                        <p className="text-sm text-ink-muted">
                            {agentToken.configured ? (
                                <>
                                    Token activo terminado en <code className="font-mono text-ink">••••{agentToken.hint}</code> · generado {agentToken.createdAt}.
                                </>
                            ) : (
                                'Todavía no hay un token: n8n no puede leer este chatbot.'
                            )}
                        </p>
                    )}

                    {canManage && (
                        <div className="flex flex-wrap gap-2">
                            <PrimaryButton type="button" onClick={generate}>
                                {agentToken.configured ? 'Generar nuevo token' : 'Generar token'}
                            </PrimaryButton>
                            {agentToken.configured && (
                                <SecondaryButton type="button" onClick={revoke} className="text-danger">
                                    Revocar
                                </SecondaryButton>
                            )}
                        </div>
                    )}
                </div>

                <div className="space-y-2 border-t border-line pt-4">
                    <p className="text-sm font-semibold text-ink">Cómo conectarlo en n8n</p>
                    <Guide />
                </div>
            </Card>
        </>
    );
}
