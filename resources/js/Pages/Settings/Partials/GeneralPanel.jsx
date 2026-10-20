import { FileText, Tag } from 'lucide-react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import IdentityCard from './IdentityCard';
import SettingsSection from './SettingsSection';

const DESCRIPTION_MAX = 500;

/** A short explanation under a field: what the data is for and where it is used. */
function Help({ icon: Icon, children }) {
    return (
        <p className="mt-1.5 flex items-start gap-2 text-xs text-ink-muted">
            <Icon className="mt-0.5 size-3.5 shrink-0 text-primary" aria-hidden="true" />
            <span>{children}</span>
        </p>
    );
}

/** General: the identity of the business. The summary and the live preview are the same card, fed with the saved or the typed values. */
export default function GeneralPanel({ settings, form, onSave, section }) {
    const { data, setData, errors } = form;

    const summary = (
        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <IdentityCard name={settings.name} description={settings.description} color={settings.primary_color} logoUrl={settings.logo_url} />
            <div className="space-y-3 text-sm text-ink-muted">
                <p className="font-semibold text-ink">Para qué sirven estos datos</p>
                <p>
                    <strong className="text-ink">El nombre</strong> identifica a tu negocio en la barra superior de Ava y lo reconoce tu equipo.
                </p>
                <p>
                    <strong className="text-ink">La descripción</strong> explica en una frase a qué se dedica. Es opcional.
                </p>
                <p>El logo y el color se cambian en la pestaña Apariencia.</p>
            </div>
        </div>
    );

    const preview = (
        <>
            <p className="text-xs font-semibold tracking-wider text-ink-muted uppercase">Así se verá</p>
            <IdentityCard name={data.name} description={data.description} color={data.primary_color} logoUrl={data.logo_url} />
            <p className="text-xs text-ink-muted">Es una vista previa: no se guarda hasta que pulses «Guardar cambios».</p>
        </>
    );

    return (
        <SettingsSection
            title="General"
            description="El nombre y la descripción con los que se identifica tu Workspace."
            form={form}
            onSave={onSave}
            summary={summary}
            aside={preview}
            {...section}
        >
            <div>
                <InputLabel htmlFor="settings_name" value="Nombre del Workspace" />
                <TextInput id="settings_name" className="mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} maxLength={255} />
                <InputError message={errors.name} className="mt-1" />
                <Help icon={Tag}>Es el nombre con el que tú y tu equipo reconocen al negocio. Usa el nombre comercial, no una razón social larga.</Help>
            </div>

            <div>
                <InputLabel htmlFor="settings_description" value="Descripción (opcional)" />
                <Textarea
                    id="settings_description"
                    className="mt-1"
                    value={data.description ?? ''}
                    onChange={(e) => setData('description', e.target.value)}
                    invalid={Boolean(errors.description)}
                    maxLength={DESCRIPTION_MAX}
                    placeholder="Una frase sobre tu negocio"
                />
                <div className="mt-1 flex items-start justify-between gap-3">
                    <InputError message={errors.description} />
                    <span className="ms-auto text-xs text-ink-muted">
                        {(data.description ?? '').length}/{DESCRIPTION_MAX}
                    </span>
                </div>
                <Help icon={FileText}>Una frase breve sobre a qué se dedica tu negocio. Ayuda a que el equipo sepa en qué Workspace está trabajando.</Help>
            </div>
        </SettingsSection>
    );
}
