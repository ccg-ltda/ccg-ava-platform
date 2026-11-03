import { Link, router } from '@inertiajs/react';
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
import InstructionBlocks from './InstructionBlocks';
import KnowledgePanel from './KnowledgePanel';
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

/**
 * IA y comportamiento: where the chatbot is maintained. One form edits its identity and its instructions (Ava is the
 * only place the instructions live; n8n reads them from here), and below it the token that identifies this chatbot to n8n (the n8n connection itself lives in Integraciones).
 * The form belongs to the page; the server validates and stores it for the active Workspace.
 */
export default function BehaviorTab({ form, chatbot, feedback = [], knowledge = null, canManage, canTokens, instructionsMax, avatarMaxKb, onSubmit, agentToken }) {
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

    const rotate = async () => {
        const confirmed = await confirm({
            description: 'Se creará un token de reemplazo y el actual seguirá funcionando. Actualiza el nuevo en n8n: el anterior se retirará solo cuando n8n use el nuevo.',
            confirmLabel: 'Rotar token',
        });

        if (confirmed) router.post(route('chatbots.agent-token.rotate', chatbot.id), {}, { preserveScroll: true });
    };

    const revokeOne = async (id) => {
        const confirmed = await confirm({ description: 'Quien use este token dejará de poder leer este chatbot ahora mismo.', confirmLabel: 'Revocar este token' });

        if (confirmed) router.delete(route('chatbots.agent-token.revoke-one', [chatbot.id, id]), { preserveScroll: true });
    };

    const lifecycle = agentToken.lifecycle;
    const current = lifecycle?.current;

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

                    {/* Until the migration of the three blocks exists the single field stays; afterwards the blocks below replace it. */}
                    {!chatbot.instructionBlocks && (
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
                    )}

                    {canManage && (
                        <div className="flex justify-end border-t border-line pt-4">
                            <PrimaryButton type="submit" disabled={processing || !isDirty || !data.name.trim()}>
                                {processing ? 'Guardando...' : 'Guardar cambios'}
                            </PrimaryButton>
                        </div>
                    )}
                </Card>
            </form>

            {chatbot.instructionBlocks && <InstructionBlocks chatbot={chatbot} feedback={feedback} canEdit={canManage} max={instructionsMax} />}

            {knowledge && <KnowledgePanel chatbot={chatbot} knowledge={knowledge} canEdit={canManage} />}

            <Card className="space-y-5 p-5 sm:p-6">
                <SectionTitle icon={KeyRound} title="Acceso para n8n">
                    Este token identifica a este chatbot cuando n8n lee su configuración o reporta mensajes. No es la API Key de tu instancia de n8n.
                </SectionTitle>

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
                                    Token activo terminado en <code className="font-mono text-ink">••••{agentToken.hint}</code> · generado {agentToken.createdAt}
                                    {current && (
                                        <>
                                            {' '}· vence {current.expiresAt} (en {current.daysLeft} {current.daysLeft === 1 ? 'día' : 'días'}) · {current.lastUsedAt ? `último uso ${current.lastUsedAt}` : 'aún sin uso'}.
                                        </>
                                    )}
                                    {!current && '.'}
                                </>
                            ) : (
                                'Todavía no hay un token: n8n no puede leer este chatbot.'
                            )}
                        </p>
                    )}

                    {current?.state === 'expiring' && !lifecycle.pendingRotation && (
                        <Alert tone="warning">
                            Este token vence pronto. Usa «Rotar token»: el actual sigue funcionando hasta que n8n use el nuevo, así no se interrumpe nada.
                        </Alert>
                    )}

                    {lifecycle?.pendingRotation && (
                        <Alert tone="info">
                            Hay un token de reemplazo que n8n todavía no ha usado. Actualízalo en n8n: el anterior se retirará solo cuando el nuevo se use. Si el anterior no se usa, vence en su fecha.
                        </Alert>
                    )}

                    {lifecycle?.previous?.length > 0 && (
                        <ul className="space-y-1 text-sm text-ink-muted" aria-label="Tokens anteriores todavía válidos">
                            {lifecycle.previous.map((token) => (
                                <li key={token.id} className="flex flex-wrap items-center gap-2">
                                    <span>
                                        Anterior terminado en <code className="font-mono text-ink">••••{token.hint}</code> · vence {token.expiresAt}
                                        {token.lastUsedAt ? ` · último uso ${token.lastUsedAt}` : ''}
                                    </span>
                                    {canTokens && (
                                        <SecondaryButton type="button" onClick={() => revokeOne(token.id)} className="text-danger">
                                            Revocar
                                        </SecondaryButton>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {canTokens && (
                        <div className="flex flex-wrap gap-2">
                            {agentToken.configured && lifecycle && (
                                <PrimaryButton type="button" onClick={rotate}>
                                    Rotar token (sin interrupción)
                                </PrimaryButton>
                            )}
                            {agentToken.configured && lifecycle ? (
                                <SecondaryButton type="button" onClick={generate}>
                                    Generar nuevo token (reemplaza ya)
                                </SecondaryButton>
                            ) : (
                                <PrimaryButton type="button" onClick={generate}>
                                    {agentToken.configured ? 'Generar nuevo token' : 'Generar token'}
                                </PrimaryButton>
                            )}
                            {agentToken.configured && (
                                <SecondaryButton type="button" onClick={revoke} className="text-danger">
                                    Revocar
                                </SecondaryButton>
                            )}
                        </div>
                    )}
                </div>

                <p className="border-t border-line pt-4 text-sm text-ink-muted">
                    Las direcciones de Ava, los ejemplos y cómo usar este token en n8n están en{' '}
                    <Link href={route('integrations.index')} className="font-semibold text-primary underline-offset-2 hover:underline">
                        Integraciones &gt; n8n
                    </Link>
                    , junto con la conexión general de tu instancia.
                </p>
            </Card>
        </>
    );
}
