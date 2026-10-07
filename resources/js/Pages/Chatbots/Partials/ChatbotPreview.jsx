import { Cable, Pencil } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import SecondaryButton from '@/Components/SecondaryButton';
import { channelIcon } from '@/config/chatbots';
import ChatbotAvatar from './ChatbotAvatar';

/**
 * What a channel says about itself on the preview, from what Ava really knows: a channel appears only when it is
 * switched on, and a channel whose connection can be tested says what that test found (or that none has been run).
 */
function channelChip(channel) {
    if (!channel.testable) return { tone: 'green', note: null };
    if (!channel.connection) return { tone: 'neutral', note: 'sin verificar' };

    return channel.connection.ok ? { tone: 'green', note: 'verificado' } : { tone: 'red', note: 'error de conexión' };
}

/**
 * General once the identity is saved: the chatbot as its customers meet it, not a form. Built only from stored data
 * and the real state of its channels, so nothing here is a promise. Editing lives in IA y comportamiento.
 */
export default function ChatbotPreview({ chatbot, channels, canManage, onEdit, onChannels }) {
    const live = channels.filter((channel) => channel.state === 'active');

    return (
        <Card className="mx-auto w-full max-w-xl overflow-hidden">
            <div className="h-24 bg-linear-to-br from-primary to-navy sm:h-28" aria-hidden="true" />

            <div className="-mt-10 px-5 pb-6 sm:-mt-12 sm:px-8">
                <ChatbotAvatar url={chatbot.avatarUrl} name={chatbot.name} className="size-20 border-4 border-card shadow-card sm:size-24" />

                <div className="mt-4 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h2 className="min-w-0 text-2xl font-extrabold tracking-tight break-words text-ink">{chatbot.name}</h2>
                    <Badge tone={chatbot.isActive ? 'green' : 'neutral'}>{chatbot.isActive ? 'Activo' : 'Inactivo'}</Badge>
                </div>

                <p className={`mt-2 text-sm whitespace-pre-wrap ${chatbot.description ? 'text-ink' : 'text-ink-muted'}`}>{chatbot.description || 'Sin descripción'}</p>

                <div className="mt-6 border-t border-line pt-4">
                    <p className="text-xs font-semibold tracking-wider text-ink-muted uppercase">Responde por</p>

                    {live.length > 0 ? (
                        <ul className="mt-3 flex flex-wrap gap-2">
                            {live.map((channel) => {
                                const Icon = channelIcon(channel.key);
                                const { tone, note } = channelChip(channel);

                                return (
                                    <li key={channel.key}>
                                        <Badge tone={tone} className="gap-1.5 py-1 normal-case tracking-normal">
                                            <Icon className="size-3.5" aria-hidden="true" />
                                            {channel.label}
                                            {note && <span className="font-normal">· {note}</span>}
                                        </Badge>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <p className="mt-2 text-sm text-ink-muted">Todavía no responde por ningún canal.</p>
                    )}
                </div>

                <div className="mt-6 flex flex-wrap gap-2">
                    <SecondaryButton type="button" onClick={onChannels} className="gap-2">
                        <Cable className="size-4" aria-hidden="true" />
                        Ver canales
                    </SecondaryButton>
                    {canManage && (
                        <SecondaryButton type="button" onClick={onEdit} className="gap-2">
                            <Pencil className="size-4" aria-hidden="true" />
                            Editar
                        </SecondaryButton>
                    )}
                </div>
            </div>
        </Card>
    );
}
