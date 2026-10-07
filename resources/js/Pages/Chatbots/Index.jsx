import { Head } from '@inertiajs/react';
import { Bot, Plus } from 'lucide-react';
import { useState } from 'react';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import { ReadOnlyWorkspaceNotice, WorkspaceViewSelector } from '@/Components/WorkspaceViewSelector';
import AppLayout from '@/Layouts/AppLayout';
import ChatbotCard from './Partials/ChatbotCard';
import NewChatbotModal from './Partials/NewChatbotModal';

/**
 * Chatbots of one Workspace, one card each. Creating is only offered to who may manage them in their own
 * Workspace; an administrator of the platform can also look (read only) at the chatbots of another Workspace.
 */
export default function Index({ chatbots, scope }) {
    const [creating, setCreating] = useState(false);
    const create = scope.canManage && (
        <PrimaryButton type="button" onClick={() => setCreating(true)} className="gap-2">
            <Plus className="size-4" aria-hidden="true" />
            Nuevo chatbot
        </PrimaryButton>
    );

    return (
        <>
            <Head title="Chatbots" />

            <PageHeader title="Chatbots" description="Los asistentes de este Workspace y los canales por los que responden.">
                <WorkspaceViewSelector scope={scope} routeName="chatbots.index" id="chatbots_workspace" />
                {create}
            </PageHeader>

            <ReadOnlyWorkspaceNotice scope={scope} what="los chatbots" />

            {chatbots.length === 0 ? (
                <EmptyState icon={Bot} title="Todavía no hay chatbots" description={scope.canManage ? 'Crea el primero para configurar su identidad y sus canales.' : 'Este Workspace aún no tiene chatbots.'}>
                    {create}
                </EmptyState>
            ) : (
                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {chatbots.map((chatbot, index) => (
                        <ChatbotCard key={chatbot.id} chatbot={chatbot} delay={index * 50} workspaceId={scope.readOnly ? scope.workspace.id : undefined} />
                    ))}
                </div>
            )}

            {creating && <NewChatbotModal onClose={() => setCreating(false)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
