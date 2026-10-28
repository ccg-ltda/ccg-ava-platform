import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Bot, Globe, Mail, MessageSquare, Palette, Receipt, SlidersHorizontal } from 'lucide-react';
import PageHeader from '@/Components/PageHeader';
import Tabs from '@/Components/Tabs';
import { useToast } from '@/Components/Toast';
import useUrlTab from '@/Hooks/useUrlTab';
import AppLayout from '@/Layouts/AppLayout';
import AppearancePanel from './Partials/AppearancePanel';
import EmailTemplatePanel from './Partials/EmailTemplatePanel';
import AutoClosePanel from './Partials/AutoClosePanel';
import GeneralPanel from './Partials/GeneralPanel';
import RegionalPanel from './Partials/RegionalPanel';
import SupportAiPanel from './Partials/SupportAiPanel';
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

const BASE_TAB_IDS = ['general', 'regional', 'apariencia', 'impuestos'];

/**
 * Configuraciones: preferences of the ACTIVE Workspace in four tabs. Each tab shows a summary of what is saved; «Editar» opens the form of that tab only
 * (one at a time) and saving or cancelling returns to the summary. The one form of the page holds the saved values while nothing is edited (the server
 * only ever writes to the Workspace of the session). Success arrives as a toast.
 */
export default function Index({ settings, catalog, canEdit, emailTemplate = null, wompi = null, autoClose = null, supportAi = null }) {
    const toast = useToast();
    // The global email template tab exists only for the platform administration (the server sends its data to nobody else).
    const TAB_IDS = [...BASE_TAB_IDS, ...(autoClose ? ['conversaciones'] : []), ...(supportAi ? ['soporte-ia'] : []), ...(emailTemplate ? ['correos'] : [])];
    const [selected, select] = useUrlTab(TAB_IDS);
    const form = useForm({ ...settings, logo: null, remove_logo: false });
    const [editing, setEditing] = useState(null); // the id of the tab being edited, or null

    const baseline = (values) => ({ ...values, logo: null, remove_logo: false });
    const startEditing = (id) => {
        form.clearErrors();
        form.setData(baseline(settings));
        setEditing(id);
    };
    const cancel = () => {
        form.clearErrors();
        form.setData(baseline(settings));
        form.setDefaults(baseline(settings));
        setEditing(null);
    };
    // What every tab needs to switch between its summary and its form.
    const sectionOf = (id) => ({ editing: editing === id, canEdit, onEdit: () => startEditing(id), onCancel: cancel });

    const save = (event) => {
        event.preventDefault();

        form.post(route('settings.update'), {
            preserveScroll: true,
            onSuccess: (page) => {
                // The server's saved values become the new baseline (and the visible values) of the form.
                const saved = baseline(page.props.settings);
                form.setDefaults(saved);
                form.setData(saved);
                setEditing(null);
            },
            onError: (errors) => {
                toast.error('Revisa los campos marcados.');

                // The values typed stay in the form and the section stays open: nothing is lost.
                const tab = TAB_IDS.indexOf(TAB_OF_FIELD[Object.keys(errors)[0]]);
                if (tab >= 0) {
                    select(tab);
                    setEditing(TAB_IDS[tab]);
                }
            },
        });
    };

    const tabs = [
        { id: 'general', label: 'General', icon: SlidersHorizontal },
        { id: 'regional', label: 'Regional', icon: Globe },
        { id: 'apariencia', label: 'Apariencia', icon: Palette },
        { id: 'impuestos', label: 'Impuestos y facturación', icon: Receipt },
        ...(autoClose ? [{ id: 'conversaciones', label: 'Conversaciones', icon: MessageSquare }] : []),
        ...(supportAi ? [{ id: 'soporte-ia', label: 'Soporte con IA', icon: Bot }] : []),
        ...(emailTemplate ? [{ id: 'correos', label: 'Plantilla HTML para correos', icon: Mail }] : []),
    ];

    return (
        <>
            <Head title="Configuraciones" />

            <PageHeader title="Configuraciones" description="Personaliza tu Workspace: identidad, formatos, apariencia e impuestos." />

            <Tabs tabs={tabs} selectedIndex={selected} onChange={select}>
                <GeneralPanel settings={settings} form={form} onSave={save} section={sectionOf('general')} />
                <RegionalPanel settings={settings} form={form} onSave={save} catalog={catalog} section={sectionOf('regional')} />
                <AppearancePanel settings={settings} form={form} onSave={save} catalog={catalog} section={sectionOf('apariencia')} />
                <TaxesPanel settings={settings} form={form} onSave={save} catalog={catalog} section={sectionOf('impuestos')} wompi={wompi} />
                {autoClose && <AutoClosePanel autoClose={autoClose} canEdit={canEdit} />}
                {supportAi && <SupportAiPanel supportAi={supportAi} />}
                {emailTemplate && <EmailTemplatePanel template={emailTemplate} />}
            </Tabs>
        </>
    );
}

Index.layout = (page) => <AppLayout>{page}</AppLayout>;
