import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import Badge from '@/Components/Badge';
import Card from '@/Components/Card';
import ChatbotAvatar from './ChatbotAvatar';

/** One chatbot: avatar, state, the channels it answers through and a link to its configuration. */
export default function ChatbotCard({ chatbot, delay, workspaceId }) {
    const { id, name, description, isActive, avatarUrl, activeChannels, updatedAt } = chatbot;
    // Looking at another Workspace keeps that choice in the link; the server revalidates it.
    const href = route('chatbots.show', workspaceId ? { chatbot: id, workspace: workspaceId } : id);

    return (
        <Card hover delay={delay} className={`flex flex-col ${isActive ? '' : 'bg-canvas/40'}`}>
            <Link href={href} className="flex flex-1 flex-col rounded-card p-5 outline-none focus-visible:outline-2 focus-visible:outline-primary" aria-label={`Configurar ${name}`}>
                <div className="flex items-start gap-3">
                    <ChatbotAvatar url={avatarUrl} name={name} />
                    <div className="min-w-0 flex-1">
                        <h2 className="truncate text-base font-bold text-ink" title={name}>
                            {name}
                        </h2>
                        <p className="line-clamp-2 text-sm text-ink-muted">{description || 'Sin descripción'}</p>
                    </div>
                    <Badge tone={isActive ? 'green' : 'neutral'}>{isActive ? 'Activo' : 'Inactivo'}</Badge>
                </div>

                <div className="mt-4 flex flex-wrap gap-1.5">
                    {activeChannels.length > 0 ? (
                        activeChannels.map((label) => (
                            <Badge key={label} tone="blue">
                                {label}
                            </Badge>
                        ))
                    ) : (
                        <span className="text-sm text-ink-muted">Sin canales activos</span>
                    )}
                </div>

                <div className="mt-4 flex items-center justify-between gap-3 border-t border-line pt-3">
                    <p className="min-w-0 truncate text-xs text-ink-muted">Actualizado {updatedAt}</p>
                    <ChevronRight className="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
                </div>
            </Link>
        </Card>
    );
}
