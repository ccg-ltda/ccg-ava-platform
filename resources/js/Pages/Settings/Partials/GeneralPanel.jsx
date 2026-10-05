import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Textarea from '@/Components/Textarea';
import TextInput from '@/Components/TextInput';
import SettingsSection from './SettingsSection';

const DESCRIPTION_MAX = 500;

/** General: how the Workspace is called and described. Codes and IDs are never shown. */
export default function GeneralPanel({ form, onSave }) {
    const { data, setData, errors } = form;

    return (
        <SettingsSection title="General" description="El nombre y la descripción con los que se identifica tu Workspace." form={form} onSave={onSave}>
            <div>
                <InputLabel htmlFor="settings_name" value="Nombre del Workspace" />
                <TextInput id="settings_name" className="mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} invalid={Boolean(errors.name)} maxLength={255} />
                <InputError message={errors.name} className="mt-1" />
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
            </div>
        </SettingsSection>
    );
}
