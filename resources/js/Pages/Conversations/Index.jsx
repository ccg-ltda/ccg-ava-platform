import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, MessagesSquare, Plug } from 'lucide-react';
import Badge from '@/Components/Badge';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import { ReadOnlyWorkspaceNotice } from '@/Components/WorkspaceViewSelector';
import { channelIcon, channelStates } from '@/config/chatbots';
import usePolling from '@/Hooks/usePolling';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import ChatPanel from './Partials/ChatPanel';
import ConnectionPanel from './Partials/ConnectionPanel';
import ConversationList from './Partials/ConversationList';

const TAB_IDS = ['conversaciones', 'conexion'];

/**
 * The operation page of a chatbot's channel (today WhatsApp): its conversations as a chat, read only, with the
 * technical connection kept in a second tab. Everything shown was reported by the automation; nothing is invented, and
 * the page refreshes itself from the server while it is open.
 */
export default function Index({ chatbot, channel, conversations, selected, filters, reportEndpoint, canConfigure, scope }) {
    const [tab, selectTab] = useUrlTab(TAB_IDS);
    const Icon = channelIcon(channel.key);
    const state = channelStates[channel.state];
    const workspaceId = scope.readOnly ? scope.workspace.id : undefined;

    usePolling(['conversations', 'selected', 'channel']);

    const baseUrl = route('chatbots.conversations', { chatbot: chatbot.id, channel: channel.key });
    const baseParams = { ...(workspaceId ? { workspace: workspaceId } : {}), ...(selected ? { c: selected.id } : {}) };
    const hrefFor = (id) => route('chatbots.conversations', { chatbot: chatbot.id, channel: channel.key, ...(workspaceId ? { workspace: workspaceId } : {}), ...(filters.q ? { q: filters.q } : {}), ...(id ? { c: id } : {}) });

    const tabs = [
        { id: 'conversaciones', label: 'Conversaciones', icon: MessagesSquare, count: conversations.length },
        { id: 'conexion', label: 'Conexión', icon: Plug },
    ];

    return (
        <>
            <Head title={`${channel.label} · ${chatbot.name}`} />

            <Link
                href={route('chatbots.show', { chatbot: chatbot.id, tab: 'canales', ...(workspaceId ? { workspace: workspaceId } : {}) })}
                className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-muted outline-none hover:text-ink focus-visible:text-primary"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                {chatbot.name}
            </Link>

            <PageHeader title={channel.label} description={`Conversaciones de ${chatbot.name} por ${channel.label}.`}>
                <span className="grid size-10 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                    <Icon className="size-5" aria-hidden="true" />
                </span>
                <Badge tone={state.tone}>{state.label}</Badge>
            </PageHeader>

            <ReadOnlyWorkspaceNotice scope={scope} what="las conversaciones" />

            <Tabs tabs={tabs} selectedIndex={tab} onChange={selectTab}>
                {conversations.length === 0 && !filters.q ? (
                    <EmptyState icon={MessagesSquare} title="Todavía no hay conversaciones" description={`Aparecerán aquí cuando n8n reporte los mensajes de ${channel.label} a Ava. La pestaña Conexión explica cómo.`} />
                ) : (
                    <div className="grid overflow-hidden rounded-card border border-line bg-card shadow-card lg:h-[calc(100vh-19rem)] lg:min-h-[32rem] lg:grid-cols-[22rem_1fr]">
                        <div className={`${selected ? 'hidden lg:flex' : 'flex'} min-h-0 max-h-[70vh] flex-col border-line lg:max-h-none lg:border-r`}>
                            <ConversationList conversations={conversations} selectedId={selected?.id} search={filters.q} hrefFor={hrefFor} baseUrl={baseUrl} baseParams={baseParams} />
                        </div>

                        <div className={`${selected ? 'flex' : 'hidden lg:flex'} h-[75vh] min-h-0 flex-col lg:h-auto`}>
                            {selected ? (
                                <ChatPanel selected={selected} backHref={hrefFor(null)} />
                            ) : (
                                <div className="grid flex-1 place-items-center p-8 text-center">
                                    <div>
                                        <MessagesSquare className="mx-auto size-10 text-ink-muted" aria-hidden="true" />
                                        <p className="mt-3 text-sm font-semibold text-ink">Selecciona una conversación</p>
                                        <p className="mt-1 text-sm text-ink-muted">Verás aquí sus mensajes.</p>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                <ConnectionPanel channel={channel} chatbot={chatbot} reportEndpoint={reportEndpoint} canConfigure={canConfigure} workspaceId={workspaceId} />
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
