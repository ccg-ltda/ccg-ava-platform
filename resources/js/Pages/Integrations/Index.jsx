import { Head, router } from '@inertiajs/react';
import { Plug, Plus } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import { ReadOnlyWorkspaceNotice, WorkspaceViewSelector } from '@/Components/WorkspaceViewSelector';
import AppLayout from '@/Layouts/AppLayout';
import AutomationCard from './Partials/AutomationCard';
import ChannelCard from './Partials/ChannelCard';
import ChannelIntegrationModal from './Partials/ChannelIntegrationModal';
import IntegrationCard from './Partials/IntegrationCard';
import IntegrationFormModal from './Partials/IntegrationFormModal';

/**
 * Integraciones. Three parts: n8n (the automation, as the Workspace can verify it), the channels of the global catalog (WhatsApp, Instagram... the same for every Workspace)
 * with what THIS Workspace has configured for each, and the Workspace's generic connections (HTTP/REST today). An
 * administrator of the platform can look (read only) at one other Workspace. Results arrive as the global toasts.
 */
export default function Index({ channels, integrations, catalog, automation, scope }) {
    const confirm = useConfirm();
    const readOnly = scope.readOnly;
    const [editing, setEditing] = useState(null); // null = closed, 'new' = creating, otherwise the integration
    const [configuring, setConfiguring] = useState(null); // the channel being configured
    const [testingId, setTestingId] = useState(null);

    const toggle = async (integration) => {
        const deactivating = integration.isActive;
        const confirmed = await confirm({
            description: deactivating
                ? `${integration.name} dejará de usarse para conexiones reales, pero conserva su configuración y puede reactivarse.`
                : `${integration.name} volverá a poder usarse para conexiones reales.`,
            confirmLabel: deactivating ? 'Desactivar' : 'Activar',
        });

        if (confirmed) {
            router.post(route(deactivating ? 'integrations.deactivate' : 'integrations.activate', integration.id), {}, { preserveScroll: true });
        }
    };

    // A channel test only reads from the provider (it changes nothing there), so it needs no confirmation.
    const testChannel = (channel) =>
        router.post(route('integrations.test', channel.integration.id), {}, {
            preserveScroll: true,
            onStart: () => setTestingId(`channel-${channel.key}`),
            onFinish: () => setTestingId(null),
        });

    const test = async (integration) => {
        const { method } = integration.summary;

        // A test sends the configured request as it is: warn before anything that may change data on the other side.
        if (method !== 'GET') {
            const confirmed = await confirm({
                description: `La prueba enviará una solicitud ${method} real a ${integration.summary.host}. Si ese método modifica datos en el servicio, se modificarán.`,
                confirmLabel: 'Probar conexión',
            });

            if (!confirmed) return;
        }

        router.post(route('integrations.test', integration.id), {}, {
            preserveScroll: true,
            onStart: () => setTestingId(integration.id),
            onFinish: () => setTestingId(null),
        });
    };

    return (
        <>
            <Head title="Integraciones" />

            <PageHeader title="Integraciones" description="Conecta Ava con otros servicios. Cada Workspace configura las suyas.">
                <WorkspaceViewSelector scope={scope} routeName="integrations.index" id="integrations_workspace" />
                {!readOnly && (
                    <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nueva integración
                    </PrimaryButton>
                )}
            </PageHeader>

            <ReadOnlyWorkspaceNotice scope={scope} what="las integraciones" />

            <section aria-labelledby="automation-heading" className="space-y-4">
                <div>
                    <h2 id="automation-heading" className="text-lg font-bold text-ink">
                        Automatización
                    </h2>
                    <p className="text-sm text-ink-muted">Quién ejecuta el flujo de cada chatbot.</p>
                </div>
                <AutomationCard automation={automation} workspaceId={readOnly ? scope.workspace.id : undefined} />
            </section>

            <section aria-labelledby="channels-heading" className="space-y-4">
                <div>
                    <h2 id="channels-heading" className="text-lg font-bold text-ink">
                        Canales
                    </h2>
                    <p className="text-sm text-ink-muted">Las cuentas de mensajería por las que pueden responder los chatbots.</p>
                </div>
                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {channels.map((channel, index) => (
                        <ChannelCard
                            key={channel.key}
                            channel={channel}
                            delay={index * 50}
                            readOnly={readOnly}
                            testing={testingId === `channel-${channel.key}`}
                            onTest={() => testChannel(channel)}
                            onConfigure={() => setConfiguring(channel)}
                            onToggle={() => toggle({ id: channel.integration.id, name: channel.label, isActive: channel.integration.isActive })}
                        />
                    ))}
                </div>
            </section>

            <section aria-labelledby="connections-heading" className="space-y-4">
                <div>
                    <h2 id="connections-heading" className="text-lg font-bold text-ink">
                        Conexiones API
                    </h2>
                    <p className="text-sm text-ink-muted">Conexiones genéricas a otros servicios mediante sus APIs.</p>
                </div>

                {integrations.length === 0 ? (
                    <EmptyState icon={Plug} title="Todavía no hay conexiones API" description={readOnly ? 'Este Workspace no tiene conexiones API.' : 'Crea la primera para conectar este Workspace con un servicio externo.'}>
                        {!readOnly && (
                            <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                                <Plus className="size-4" aria-hidden="true" />
                                Nueva integración
                            </PrimaryButton>
                        )}
                    </EmptyState>
                ) : (
                    <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                        {integrations.map((integration, index) => (
                            <IntegrationCard
                                key={integration.id}
                                integration={integration}
                                delay={index * 50}
                                testing={testingId === integration.id}
                                readOnly={readOnly}
                                onEdit={() => setEditing(integration)}
                                onTest={() => test(integration)}
                                onToggle={() => toggle(integration)}
                            />
                        ))}
                    </div>
                )}
            </section>

            {configuring && <ChannelIntegrationModal key={configuring.key} channel={configuring} onClose={() => setConfiguring(null)} />}
            {editing && <IntegrationFormModal key={editing === 'new' ? 'new' : editing.id} integration={editing === 'new' ? null : editing} catalog={catalog} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
