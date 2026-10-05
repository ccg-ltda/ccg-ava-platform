import { Head, router } from '@inertiajs/react';
import { Plug, Plus } from 'lucide-react';
import { useState } from 'react';
import { useConfirm } from '@/Components/ConfirmDialog';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import PrimaryButton from '@/Components/PrimaryButton';
import AppLayout from '@/Layouts/AppLayout';
import IntegrationCard from './Partials/IntegrationCard';
import IntegrationFormModal from './Partials/IntegrationFormModal';

/**
 * Integraciones: the external connections of the active Workspace, one card each. The form is generic (HTTP/REST
 * today); the server tells which types exist. Results (created, tested, errors) arrive as the global toasts.
 */
export default function Index({ integrations, catalog }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState(null); // null = closed, 'new' = creating, otherwise the integration
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

            <PageHeader title="Integraciones" description="Conecta Ava con otros servicios mediante sus APIs.">
                <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                    <Plus className="size-4" aria-hidden="true" />
                    Nueva integración
                </PrimaryButton>
            </PageHeader>

            {integrations.length === 0 ? (
                <EmptyState icon={Plug} title="Todavía no hay integraciones" description="Crea la primera para conectar este Workspace con un servicio externo.">
                    <PrimaryButton type="button" onClick={() => setEditing('new')} className="gap-2">
                        <Plus className="size-4" aria-hidden="true" />
                        Nueva integración
                    </PrimaryButton>
                </EmptyState>
            ) : (
                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    {integrations.map((integration, index) => (
                        <IntegrationCard
                            key={integration.id}
                            integration={integration}
                            delay={index * 50}
                            testing={testingId === integration.id}
                            onEdit={() => setEditing(integration)}
                            onTest={() => test(integration)}
                            onToggle={() => toggle(integration)}
                        />
                    ))}
                </div>
            )}

            {editing && <IntegrationFormModal key={editing === 'new' ? 'new' : editing.id} integration={editing === 'new' ? null : editing} catalog={catalog} onClose={() => setEditing(null)} />}
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
