import { Head } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import Card from '@/Components/Card';
import EmptyState from '@/Components/EmptyState';
import PageHeader from '@/Components/PageHeader';
import AppLayout from '@/Layouts/AppLayout';

const MESSAGES = {
    empty: { title: 'No tienes módulos habilitados actualmente', description: 'Contacta con soporte si necesitas ayuda.' },
    subscription: { title: 'La suscripción de tu Workspace no permite acceder a los módulos', description: 'Contacta con soporte si necesitas ayuda. Tu información se conserva.' },
    permissions: { title: 'Tu rol no tiene acceso a los módulos habilitados', description: 'Pide a la administración de tu Workspace que revise tus permisos.' },
};

/** Shown instead of the home page when the Workspace has nothing to open: no modules in its plan, a subscription that gives no access, or no permission. */
export default function NoModules({ reason }) {
    const message = MESSAGES[reason] ?? MESSAGES.empty;

    return (
        <>
            <Head title="Sin módulos" />
            <PageHeader title="Tu Workspace" description="Los módulos disponibles dependen del plan contratado." />
            <Card className="p-4 sm:p-6">
                <EmptyState icon={LayoutGrid} title={message.title} description={message.description} />
            </Card>
        </>
    );
}

NoModules.layout = (page) => <AppLayout>{page}</AppLayout>;
