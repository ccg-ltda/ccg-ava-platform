import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, MessagesSquare, Plug } from 'lucide-react';
import Badge from '@/Components/Badge';
import PageHeader from '@/Components/PageHeader';
import Select from '@/Components/Select';
import Tabs from '@/Components/Tabs';
import { ReadOnlyWorkspaceNotice } from '@/Components/WorkspaceViewSelector';
import { channelIcon, channelStates } from '@/config/chatbots';
import usePolling from '@/Hooks/usePolling';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import ChatPanel from './Partials/ChatPanel';
import ConnectionPanel from './Partials/ConnectionPanel';
import ConversationList from './Partials/ConversationList';
import { DemoPanel } from './Partials/DemoTools';
import StatusFilter from './Partials/StatusFilter';

const TAB_IDS = ['conversaciones', 'conexion'];
const ALL = { value: '', label: 'Todos' };

/**
 * The inbox of conversations. On `/conversations` it covers every chatbot and channel of the Workspace; on the page of
 * one channel of a chatbot it is limited to that channel and keeps its technical connection in a second tab. Who answers
 * (AI, waiting for an agent, in attention, resolved) comes from the server, and so do the actions the user has on a
 * conversation; the page refreshes itself from the server while it is open, so nothing is guessed in the browser.
 */
export default function Index({ chatbot, channel, conversations, counts, selected, agents, filters, options, demo, reportEndpoint, canConfigure, scope }) {
    const [tab, selectTab] = useUrlTab(TAB_IDS);
    const workspaceId = scope.readOnly ? scope.workspace.id : undefined;
    const channelMode = Boolean(channel);

    // A conversation open in front of an agent refreshes faster than a list nobody is looking at closely.
    usePolling(['conversations', 'counts', 'selected', 'agents'], selected ? 5000 : 15000);

    const baseUrl = channelMode ? route('chatbots.conversations', { chatbot: chatbot.id, channel: channel.key }) : route('conversations.index');
    const query = (overrides = {}) => {
        const merged = {
            ...(workspaceId ? { workspace: workspaceId } : {}),
            ...(filters.status !== 'all' ? { status: filters.status } : {}),
            ...(filters.q ? { q: filters.q } : {}),
            ...(!channelMode && filters.channel ? { channel: filters.channel } : {}),
            ...(!channelMode && filters.chatbot ? { chatbot: filters.chatbot } : {}),
            ...overrides,
        };

        return Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== undefined && value !== null && value !== '' && value !== 'all'));
    };

    const hrefFor = (id) => `${baseUrl}${toQuery(query({ c: id ?? undefined }))}`;
    const statusHref = (status) => `${baseUrl}${toQuery(query({ status, c: undefined }))}`;
    const listParams = query({ q: undefined, c: selected ? selected.id : undefined });
    const filter = (key) => (value) => router.get(baseUrl, query({ [key]: value || undefined, c: undefined }), { preserveState: true, preserveScroll: true, replace: true });

    const Icon = channelMode ? channelIcon(channel.key) : MessagesSquare;
    const state = channelMode ? channelStates[channel.state] : null;
    const inbox = (
        // The bottom padding keeps the floating chat button of Ava from covering the composer when the page is scrolled to its end.
        <div className="space-y-3">
            <div className={selected ? 'hidden lg:block' : ''}>
                <StatusFilter counts={counts} current={filters.status} hrefFor={statusHref} />
            </div>

            <div className="grid overflow-hidden rounded-card border border-line bg-card shadow-card lg:h-[calc(100dvh-16.5rem)] lg:min-h-[26rem] grid-cols-[minmax(0,1fr)] lg:grid-cols-[22.5rem_minmax(0,1fr)]">
                <div className={`${selected ? 'hidden lg:flex' : 'flex'} max-h-[calc(100dvh-14rem)] min-h-[16rem] flex-col border-line lg:max-h-none lg:border-r`}>
                    <ConversationList
                        conversations={conversations}
                        selectedId={selected?.id}
                        search={filters.q}
                        hrefFor={hrefFor}
                        baseUrl={baseUrl}
                        baseParams={listParams}
                        showChannel={!channelMode}
                        filtered={filters.status !== 'all' || Boolean(filters.channel) || Boolean(filters.chatbot)}
                    />
                </div>

                <div className={`${selected ? 'flex' : 'hidden lg:flex'} h-[calc(100dvh-7rem)] min-h-[22rem] flex-col lg:h-auto lg:min-h-0`}>
                    {selected ? (
                        <ChatPanel selected={selected} backHref={hrefFor(null)} agents={agents} />
                    ) : (
                        <div className="grid flex-1 place-items-center p-8 text-center">
                            <div>
                                <MessagesSquare className="mx-auto size-10 text-ink-muted" aria-hidden="true" />
                                <p className="mt-3 text-sm font-semibold text-ink">Selecciona una conversación</p>
                                <p className="mt-1 text-sm text-ink-muted">Verás aquí sus mensajes y quién la atiende.</p>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );

    return (
        <>
            <Head title={channelMode ? `${channel.label} · ${chatbot.name}` : 'Conversaciones'} />

            {channelMode && (
                <Link
                    href={route('chatbots.show', { chatbot: chatbot.id, tab: 'canales', ...(workspaceId ? { workspace: workspaceId } : {}) })}
                    className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-muted outline-none hover:text-ink focus-visible:text-primary"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {chatbot.name}
                </Link>
            )}

            <div className={selected && !channelMode ? 'hidden space-y-3 lg:block' : 'space-y-3'}>
                <PageHeader
                    title={
                        <span className="flex items-center gap-3">
                            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-accent-blue">
                                <Icon className="size-5" aria-hidden="true" />
                            </span>
                            {channelMode ? channel.label : 'Conversaciones'}
                        </span>
                    }
                    description={channelMode ? `Conversaciones de ${chatbot.name} por ${channel.label}.` : 'Atiende a tus contactos: la IA responde y tú tomas el control cuando haga falta.'}
                >
                    {state && <Badge tone={state.tone}>{state.label}</Badge>}
                    {options && (
                        <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto" role="group" aria-label="Filtros de la bandeja">
                            <Select value={filters.channel ?? ''} onChange={filter('channel')} options={[{ ...ALL, label: 'Todos los canales' }, ...options.channels]} size="sm" className="sm:w-44" aria-label="Filtrar por canal" />
                            <Select value={filters.chatbot ?? ''} onChange={filter('chatbot')} options={[{ ...ALL, label: 'Todos los asistentes' }, ...options.chatbots]} size="sm" className="sm:w-48" aria-label="Filtrar por asistente" />
                        </div>
                    )}
                </PageHeader>

                {demo && !channelMode && (
                    <div className="flex sm:justify-end">
                        <DemoPanel demo={demo} />
                    </div>
                )}
            </div>

            <ReadOnlyWorkspaceNotice scope={scope} what="las conversaciones" />

            {channelMode ? (
                <Tabs
                    tabs={[
                        { id: 'conversaciones', label: 'Conversaciones', icon: MessagesSquare, count: Object.values(counts).reduce((sum, total) => sum + total, 0) },
                        { id: 'conexion', label: 'Conexión', icon: Plug },
                    ]}
                    selectedIndex={tab}
                    onChange={selectTab}
                >
                    {inbox}
                    <ConnectionPanel channel={channel} chatbot={chatbot} reportEndpoint={reportEndpoint} canConfigure={canConfigure} workspaceId={workspaceId} />
                </Tabs>
            ) : (
                inbox
            )}
        </>
    );
}

/** `?a=1&b=2` for the given params, or an empty string. */
function toQuery(params) {
    const text = new URLSearchParams(params).toString();

    return text ? `?${text}` : '';
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
