import { Head } from '@inertiajs/react';
import { Layers, Package } from 'lucide-react';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import CatalogPanel from './Partials/CatalogPanel';
import PlansPanel from './Partials/PlansPanel';

/**
 * Planes: CCG's commercial administration (superusers inside Desarrollo_DEV). Two tabs: the plans themselves and the catalog
 * of modules they are built from. The tab lives in the URL (?tab=) like the other multi-tab modules.
 */
export default function Index({ can, plans, catalog, filters }) {
    // Each tab needs its own permission (`manage-plans`, `manage-modules`); the server enforces both on every action.
    const sections = [
        can.plans && { id: 'planes', label: 'Planes', icon: Layers, count: filters.search ? undefined : plans.length, panel: <PlansPanel plans={plans} catalog={catalog} filters={filters} /> },
        can.modules && { id: 'modulos', label: 'Catálogo de módulos', icon: Package, count: catalog.reduce((total, category) => total + category.modules.length, 0), panel: <CatalogPanel catalog={catalog} /> },
    ].filter(Boolean);

    const [selected, select] = useUrlTab(sections.map((section) => section.id));

    return (
        <>
            <Head title="Planes" />

            <PageHeader title="Planes" description="Combina módulos reutilizables en planes comerciales y asígnalos a los Workspaces de tus clientes." />

            <Tabs tabs={sections} selectedIndex={selected} onChange={select}>
                {sections.map((section) => section.panel)}
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
