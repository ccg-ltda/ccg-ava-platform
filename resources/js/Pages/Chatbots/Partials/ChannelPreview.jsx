import { Pencil } from 'lucide-react';
import { useMemo } from 'react';
import Card from '@/Components/Card';
import PrimaryButton from '@/Components/PrimaryButton';
import { DestinationNotice, InstallSection, previewConfig } from './ChannelAppearanceEditor';
import WidgetPreview from './WidgetPreview';

/**
 * What a channel looks like once its appearance is saved: a wide mock page with the real button / widget (drawn from the
 * values the SERVER stored, never from a draft) next to the install code. Editing is one button away.
 */
export default function ChannelPreview({ channel, runtime, chatbot, mode, scriptUrl, canManage, canConfigure, onEdit, onChannels }) {
    const dark = mode === 'dark' || (mode === 'system' && Boolean(window.matchMedia?.('(prefers-color-scheme: dark)').matches));
    const config = useMemo(() => previewConfig({ channel, draft: channel.values, chatbot, mode }), [channel, chatbot, mode]);

    return (
        <div className="space-y-4">
            <DestinationNotice channel={channel} canConfigure={canConfigure} />

            <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,1fr)]">
                <Card className="min-w-0 p-4">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 className="text-base font-bold text-ink">Vista previa</h3>
                            <p className="text-xs text-ink-muted">Así se verá en tu página, con la apariencia guardada.</p>
                        </div>
                        {canManage && (
                            <PrimaryButton type="button" onClick={onEdit} className="gap-2">
                                <Pencil className="size-4" aria-hidden="true" />
                                Editar apariencia
                            </PrimaryButton>
                        )}
                    </div>
                    <WidgetPreview scriptUrl={scriptUrl} config={config} startOpen={channel.kind === 'widget'} dark={dark} large />
                </Card>

                <div className="min-w-0">
                    <InstallSection channel={channel} runtime={runtime} canConfigure={canConfigure} onChannels={onChannels} />
                </div>
            </div>
        </div>
    );
}
