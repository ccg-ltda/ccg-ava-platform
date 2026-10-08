import { router } from '@inertiajs/react';
import { useState } from 'react';
import Badge from '@/Components/Badge';
import { useConfirm } from '@/Components/ConfirmDialog';
import Select from '@/Components/Select';
import { channelIcon, channelStates } from '@/config/chatbots';
import ChannelAppearanceEditor from './ChannelAppearanceEditor';
import ChannelPreview from './ChannelPreview';

const DOTS = { green: 'bg-accent-green', amber: 'bg-accent-amber', blue: 'bg-accent-blue', neutral: 'bg-ink-muted/40' };

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** The channel list of the inner sidebar: one entry per channel with a look to configure; unavailable ones are listed as coming soon. */
function ChannelList({ channels, runtimes, selected, onSelect }) {
    return (
        <nav aria-label="Canales" className="hidden lg:block">
            <ul className="space-y-1">
                {channels.map((channel) => {
                    const Icon = channelIcon(channel.key);
                    const state = channelStates[runtimes[channel.key]?.state];

                    return (
                        <li key={channel.key}>
                            <button
                                type="button"
                                disabled={!channel.available}
                                onClick={() => onSelect(channel.key)}
                                aria-current={selected === channel.key ? 'true' : undefined}
                                className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-semibold text-ink-muted outline-none transition-colors hover:bg-primary-soft hover:text-ink focus-visible:outline-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent aria-[current=true]:bg-primary-soft aria-[current=true]:text-primary"
                            >
                                <Icon className="size-4 shrink-0" aria-hidden="true" />
                                <span className="min-w-0 flex-1 truncate">{channel.label}</span>
                                {!channel.available ? (
                                    <Badge className="px-1.5 tracking-normal normal-case">Pronto</Badge>
                                ) : (
                                    state && (
                                        <span title={state.label} className={`size-2 shrink-0 rounded-full ${DOTS[state.tone] ?? 'bg-ink-muted/40'}`}>
                                            <span className="sr-only">{state.label}</span>
                                        </span>
                                    )
                                )}
                            </button>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/**
 * Canales y apariencia: the client chooses how each channel's button / widget looks on their site. Left: the channels
 * (a dropdown on small screens); right: the selected channel's editor with live preview and install code. Only channels
 * the platform really serves can be configured; the others are listed as coming soon and do nothing. The edits of every
 * channel are kept while the user switches between them; saving applies to the selected channel only.
 */
export default function AppearanceTab({ chatbot, appearance, channels, canManage, canConfigure, onChannels }) {
    const { options, mode, scriptUrl } = appearance;
    const configurable = appearance.channels.filter((channel) => channel.available);
    const [selected, setSelected] = useState(configurable[0]?.key);
    const [drafts, setDrafts] = useState({});
    const [status, setStatus] = useState({});
    // Channels the user opened the form on. Until a look is saved, the form is what the channel shows.
    const [editing, setEditing] = useState({});
    const confirm = useConfirm();

    const channel = configurable.find((item) => item.key === selected);
    const runtimes = Object.fromEntries(channels.map((item) => [item.key, item]));

    if (!channel) return <p className="text-sm text-ink-muted">Ningún canal permite personalizar su apariencia todavía.</p>;

    const draft = drafts[channel.key] ?? channel.values;
    const dirty = !same(draft, channel.values);
    const isEditing = editing[channel.key] ?? !channel.saved;

    const save = () => {
        router.put(route('chatbots.channels.appearance.update', { chatbot: chatbot.id, channel: channel.key }), draft, {
            preserveScroll: true,
            onStart: () => setStatus((previous) => ({ ...previous, [channel.key]: 'saving' })),
            // What the server stored becomes the new baseline, so the draft stops being "unsaved".
            onSuccess: () => {
                setDrafts((previous) => ({ ...previous, [channel.key]: undefined }));
                setStatus((previous) => ({ ...previous, [channel.key]: 'saved' }));
                setEditing((previous) => ({ ...previous, [channel.key]: false }));
                window.scrollTo({ top: 0, behavior: 'smooth' });
            },
            onError: () => setStatus((previous) => ({ ...previous, [channel.key]: 'error' })),
        });
    };

    const change = (next) => {
        setDrafts((previous) => ({ ...previous, [channel.key]: next }));
        setStatus((previous) => ({ ...previous, [channel.key]: undefined }));
    };

    const cancel = async () => {
        if (dirty) {
            const discard = await confirm({ description: 'Tienes cambios sin guardar. Si sales ahora, se perderán.', confirmLabel: 'Descartar cambios' });

            if (!discard) return;
        }

        setDrafts((previous) => ({ ...previous, [channel.key]: undefined }));
        setStatus((previous) => ({ ...previous, [channel.key]: undefined }));
        setEditing((previous) => ({ ...previous, [channel.key]: false }));
    };

    return (
        <div className="grid gap-6 lg:grid-cols-[11rem_minmax(0,1fr)]">
            <aside className="min-w-0">
                <div className="lg:hidden">
                    <label htmlFor="appearance_channel" className="mb-2 block text-sm font-medium text-ink">Seleccionar canal</label>
                    <Select id="appearance_channel" value={selected} onChange={setSelected} options={configurable.map((item) => ({ value: item.key, label: item.label }))} />
                    {appearance.channels.some((item) => !item.available) && (
                        <p className="mt-2 text-xs text-ink-muted">Próximamente: {appearance.channels.filter((item) => !item.available).map((item) => item.label).join(' y ')}.</p>
                    )}
                </div>
                <ChannelList channels={appearance.channels} runtimes={runtimes} selected={selected} onSelect={setSelected} />
            </aside>

            <div className="min-w-0">
                {isEditing ? (
                    <ChannelAppearanceEditor
                        key={channel.key}
                        channel={channel}
                        runtime={runtimes[channel.key]}
                        draft={draft}
                        onChange={change}
                        dirty={dirty}
                        status={status[channel.key]}
                        onSave={save}
                        onCancel={channel.saved ? cancel : undefined}
                        chatbot={chatbot}
                        options={options}
                        mode={mode}
                        scriptUrl={scriptUrl}
                        canManage={canManage}
                        canConfigure={canConfigure}
                        onChannels={onChannels}
                    />
                ) : (
                    <ChannelPreview
                        key={channel.key}
                        channel={channel}
                        runtime={runtimes[channel.key]}
                        chatbot={chatbot}
                        mode={mode}
                        scriptUrl={scriptUrl}
                        canManage={canManage}
                        canConfigure={canConfigure}
                        onEdit={() => setEditing((previous) => ({ ...previous, [channel.key]: true }))}
                        onChannels={onChannels}
                    />
                )}
            </div>
        </div>
    );
}
