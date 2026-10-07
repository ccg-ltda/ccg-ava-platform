import { Link, router, usePage } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import { useConfirm } from '@/Components/ConfirmDialog';
import CopyBlock from '@/Components/CopyBlock';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { channelIcon, channelStates } from '@/config/chatbots';

/** Explains why a channel cannot be switched on, in the words of its state (and where to fix it, when allowed). */
function Hint({ channel, canConfigure }) {
    const state = channelStates[channel.state];
    const text = channel.state === 'unavailable' ? channel.unavailable : channel.state === 'in_use' && channel.usedBy ? `${channel.usedBy} ya responde por este canal.` : state.hint;

    if (!text) return null;

    return (
        <p className="mt-1 text-sm text-ink-muted">
            {text}{' '}
            {canConfigure && ['not_configured', 'integration_inactive'].includes(channel.state) && (
                <Link href={route('integrations.index')} className="font-semibold text-primary underline-offset-2 hover:underline">
                    Ir a Integraciones
                </Link>
            )}
        </p>
    );
}

/** What Ava verified about the channel's account: nothing yet, or the result of the last real test. Never inferred from saved data. */
function Connection({ connection }) {
    if (!connection) return <p className="mt-3 text-sm text-ink-muted">Conexión sin verificar. Se prueba desde Integraciones.</p>;

    return (
        <p className={`mt-3 rounded-lg border p-3 text-sm ${connection.ok ? 'border-accent-green/25 bg-accent-green/10' : 'border-danger/25 bg-danger/10'}`}>
            <strong>{connection.ok ? 'Conexión verificada. ' : 'Error de conexión. '}</strong>
            {connection.message}
            <span className="mt-0.5 block text-xs text-ink-muted">{connection.at}</span>
        </p>
    );
}

/**
 * Where the chatbot answers. Every channel of the global catalog is listed with the state THIS chatbot has in it;
 * the server decides it (it knows the Workspace's own integration) and also decides whether it can be switched on.
 * A channel holds no credentials: they live in the Workspace's integration.
 */
export default function ChannelsTab({ chatbot, channels, canManage, workspaceId }) {
    const confirm = useConfirm();
    const { auth } = usePage().props;
    const canConfigure = auth.user.permissions.includes('manage-settings');
    const canSeeConversations = auth.user.permissions.includes('view-conversations');
    const [pending, setPending] = useState(null);

    const toggle = async (channel) => {
        const activating = channel.state !== 'active';
        const confirmed = await confirm({
            description: activating
                ? `${chatbot.name} empezará a responder por ${channel.label}.`
                : `${chatbot.name} dejará de responder por ${channel.label}. La integración del Workspace no se modifica.`,
            confirmLabel: activating ? 'Activar' : 'Desactivar',
        });

        if (!confirmed) return;

        router.put(route('chatbots.channels.update', { chatbot: chatbot.id, channel: channel.key }), { is_active: activating }, {
            preserveScroll: true,
            onStart: () => setPending(channel.key),
            onFinish: () => setPending(null),
        });
    };

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            {channels.map((channel, index) => {
                const state = channelStates[channel.state];
                const Icon = channelIcon(channel.key);
                const active = channel.state === 'active';

                return (
                    <Card key={channel.key} delay={index * 50} className="flex flex-col p-5">
                        <div className="flex flex-wrap items-start gap-3">
                            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                                <Icon className="size-5" aria-hidden="true" />
                            </span>
                            <div className="min-w-[11rem] flex-1">
                                <h3 className="text-base font-bold text-ink">{channel.label}</h3>
                                <p className="text-sm text-ink-muted">{channel.description}</p>
                            </div>
                            <Badge tone={state.tone}>{state.label}</Badge>
                        </div>

                        <Hint channel={channel} canConfigure={canConfigure} />
                        {channel.testable && !['unavailable', 'not_configured'].includes(channel.state) && <Connection connection={channel.connection} />}

                        {channel.installCode && (
                            <div className="mt-4 space-y-2">
                                <p className="text-sm font-semibold text-ink">Código de instalación</p>
                                <p className="text-sm text-ink-muted">Pégalo antes de &lt;/body&gt; en las páginas donde quieras mostrar el chat.</p>
                                <CopyBlock value={channel.installCode} label="Código de instalación del widget" />
                            </div>
                        )}

                        {channel.conversations && canSeeConversations && channel.state !== 'not_configured' && (
                            <div className="mt-4">
                                <Link
                                    href={route('chatbots.conversations', { chatbot: chatbot.id, channel: channel.key, ...(workspaceId ? { workspace: workspaceId } : {}) })}
                                    className="inline-flex items-center gap-2 rounded-lg border border-line bg-card px-4 py-2 text-sm font-semibold text-ink transition-colors hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-primary"
                                >
                                    <MessagesSquare className="size-4" aria-hidden="true" />
                                    Ver conversaciones
                                </Link>
                            </div>
                        )}

                        {canManage && !state.locked && (
                            <div className="mt-4 flex justify-end border-t border-line pt-3">
                                {active ? (
                                    <SecondaryButton type="button" onClick={() => toggle(channel)} disabled={pending === channel.key} className="text-danger">
                                        Desactivar
                                    </SecondaryButton>
                                ) : (
                                    <PrimaryButton type="button" onClick={() => toggle(channel)} disabled={pending === channel.key}>
                                        Activar
                                    </PrimaryButton>
                                )}
                            </div>
                        )}
                    </Card>
                );
            })}
        </div>
    );
}
