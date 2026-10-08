import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Cable, Palette, Power, SlidersHorizontal, Sparkles } from 'lucide-react';
import Badge from '@/Components/Badge';
import { useConfirm } from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import SecondaryButton from '@/Components/SecondaryButton';
import Tabs from '@/Components/Tabs';
import { useToast } from '@/Components/Toast';
import { ReadOnlyWorkspaceNotice } from '@/Components/WorkspaceViewSelector';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import AppearanceTab from './Partials/AppearanceTab';
import BehaviorTab from './Partials/BehaviorTab';
import ChannelsTab from './Partials/ChannelsTab';
import ChatbotAvatar from './Partials/ChatbotAvatar';
import GeneralTab from './Partials/GeneralTab';

const TAB_IDS = ['general', 'canales', 'apariencia', 'ia'];
const tabIndex = (id) => TAB_IDS.indexOf(id);
const FIELDS = ['name', 'description', 'instructions', 'avatar'];

/**
 * One chatbot in four tabs: General (its presentation: the identity form the first time, the preview afterwards),
 * Canales (where it answers), Canales y apariencia (how each channel's button / widget looks on the customer's site)
 * and IA y comportamiento (where it is edited and where the automation gets its access).
 * The form lives here so it survives switching tabs. Everything is read only when the user cannot manage chatbots or
 * is looking at another Workspace.
 */
export default function Show({ chatbot, channels, appearance, agentToken, instructionsMax, avatar, scope }) {
    const { auth } = usePage().props;
    const confirm = useConfirm();
    const toast = useToast();
    const [selected, select] = useUrlTab(TAB_IDS);
    const form = useForm({ name: chatbot.name, description: chatbot.description, instructions: chatbot.instructions, avatar: null, remove_avatar: false });

    const save = (event) => {
        event.preventDefault();

        form.post(route('chatbots.update', chatbot.id), {
            preserveScroll: true,
            onSuccess: (page) => {
                // What the server saved becomes the new baseline of the form.
                const saved = { name: page.props.chatbot.name, description: page.props.chatbot.description, instructions: page.props.chatbot.instructions, avatar: null, remove_avatar: false };
                form.setDefaults(saved);
                form.setData(saved);
            },
            onError: (errors) => {
                toast.error('Revisa los campos marcados.');

                // The fields live in General until the identity is saved, and in IA y comportamiento afterwards.
                if (FIELDS.includes(Object.keys(errors)[0])) select(tabIndex(chatbot.profileSaved ? 'ia' : 'general'));
            },
        });
    };

    const toggle = async () => {
        const deactivating = chatbot.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `${chatbot.name} dejará de estar activo, pero conserva su configuración y sus canales y puede reactivarse.`
                : `${chatbot.name} volverá a estar activo.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (confirmed) {
            router.post(route(deactivating ? 'chatbots.deactivate' : 'chatbots.activate', chatbot.id), {}, { preserveScroll: true });
        }
    };

    const tabs = [
        { id: 'general', label: 'General', icon: SlidersHorizontal },
        { id: 'canales', label: 'Canales', icon: Cable },
        { id: 'apariencia', label: 'Canales y apariencia', icon: Palette },
        { id: 'ia', label: 'IA y comportamiento', icon: Sparkles },
    ];

    return (
        <>
            <Head title={chatbot.name} />

            <Link
                href={route('chatbots.index', scope.readOnly ? { workspace: scope.workspace.id } : {})}
                className="inline-flex items-center gap-1.5 text-sm font-semibold text-ink-muted outline-none hover:text-ink focus-visible:text-primary"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                Chatbots
            </Link>

            <PageHeader title={chatbot.name} description={chatbot.description || 'Sin descripción'}>
                <div className="flex items-center gap-3">
                    <ChatbotAvatar url={chatbot.avatarUrl} name={chatbot.name} className="size-10" />
                    <Badge tone={chatbot.isActive ? 'green' : 'neutral'}>{chatbot.isActive ? 'Activo' : 'Inactivo'}</Badge>
                </div>
                {scope.canManage && (
                    <SecondaryButton type="button" onClick={toggle} className={`gap-2 ${chatbot.isActive ? 'text-danger' : ''}`}>
                        <Power className="size-4" aria-hidden="true" />
                        {chatbot.isActive ? 'Desactivar' : 'Activar'}
                    </SecondaryButton>
                )}
            </PageHeader>

            <ReadOnlyWorkspaceNotice scope={scope} what="este chatbot" />

            <Tabs tabs={tabs} selectedIndex={selected} onChange={select}>
                <GeneralTab form={form} chatbot={chatbot} channels={channels} canManage={scope.canManage} avatarMaxKb={avatar.maxKb} onSubmit={save} onEdit={() => select(tabIndex('ia'))} onChannels={() => select(tabIndex('canales'))} />
                <ChannelsTab chatbot={chatbot} channels={channels} canManage={scope.canManage} workspaceId={scope.readOnly ? scope.workspace.id : undefined} />
                <AppearanceTab chatbot={chatbot} appearance={appearance} channels={channels} canManage={scope.canManage} canConfigure={auth.user.permissions.includes('manage-settings')} onChannels={() => select(tabIndex('canales'))} />
                <BehaviorTab form={form} chatbot={chatbot} canManage={scope.canManage} instructionsMax={instructionsMax} avatarMaxKb={avatar.maxKb} onSubmit={save} agentToken={agentToken} />
            </Tabs>
        </>
    );
}

Show.layout = (page) => <AppLayout>{page}</AppLayout>;
