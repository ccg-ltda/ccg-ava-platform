import { Head, useForm } from '@inertiajs/react';
import { Globe, Palette, Receipt, SlidersHorizontal } from 'lucide-react';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import { useToast } from '@/Components/Toast';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import AppearancePanel from './Partials/AppearancePanel';
import GeneralPanel from './Partials/GeneralPanel';
import RegionalPanel from './Partials/RegionalPanel';
import TaxesPanel from './Partials/TaxesPanel';

/** The tab that owns each field, to take the user to the first one with an error. */
const TAB_OF_FIELD = {
    name: 'general',
    description: 'general',
    currency: 'regional',
    timezone: 'regional',
    date_format: 'regional',
    time_format: 'regional',
    logo: 'apariencia',
    primary_color: 'apariencia',
    appearance: 'apariencia',
    tax_country: 'impuestos',
    tax_enabled: 'impuestos',
    tax_name: 'impuestos',
    tax_rate: 'impuestos',
};

const TAB_IDS = ['general', 'regional', 'apariencia', 'impuestos'];

/**
 * Configuraciones: preferences of the ACTIVE Workspace in four tabs. One form and one save button for all of
 * them (the server only ever writes to the Workspace of the session). Success arrives as a toast.
 */
export default function Index({ settings, catalog }) {
    const toast = useToast();
    const [selected, select] = useUrlTab(TAB_IDS);
    const form = useForm({ ...settings, logo: null, remove_logo: false });

    const save = (event) => {
        event.preventDefault();

        form.post(route('settings.update'), {
            preserveScroll: true,
            onSuccess: (page) => {
                // The server's saved values become the new baseline (and the visible values) of the form.
                const saved = { ...page.props.settings, logo: null, remove_logo: false };
                form.setDefaults(saved);
                form.setData(saved);
            },
            onError: (errors) => {
                toast.error('Revisa los campos marcados.');

                const tab = TAB_IDS.indexOf(TAB_OF_FIELD[Object.keys(errors)[0]]);
                if (tab >= 0) select(tab);
            },
        });
    };

    const tabs = [
        { id: 'general', label: 'General', icon: SlidersHorizontal },
        { id: 'regional', label: 'Regional', icon: Globe },
        { id: 'apariencia', label: 'Apariencia', icon: Palette },
        { id: 'impuestos', label: 'Impuestos', icon: Receipt },
    ];

    return (
        <>
            <Head title="Configuraciones" />

            <PageHeader title="Configuraciones" description="Personaliza tu Workspace: identidad, formatos, apariencia e impuestos." />

            <Tabs tabs={tabs} selectedIndex={selected} onChange={select}>
                <GeneralPanel form={form} onSave={save} />
                <RegionalPanel form={form} onSave={save} catalog={catalog} />
                <AppearancePanel form={form} onSave={save} catalog={catalog} />
                <TaxesPanel form={form} onSave={save} catalog={catalog} />
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
