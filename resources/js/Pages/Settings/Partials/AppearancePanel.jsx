import { Monitor, Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';
import Badge from '@/Components/Badge';
import ImageField from '@/Components/ImageField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { brandVariables, contrastWithWhite, isHexColor } from '@/lib/theme';
import AppearancePreview from './AppearancePreview';
import SettingsSection, { Fact } from './SettingsSection';

const MODES = [
    { value: 'light', icon: Sun },
    { value: 'dark', icon: Moon },
    { value: 'system', icon: Monitor },
];

const MIN_CONTRAST = 4.5;

/** Logo of the Workspace: upload, preview and removal. The file is validated again by the server. */
function LogoField({ form }) {
    const { data, setData, errors } = form;

    return (
        <ImageField
            id="settings_logo"
            label="Logo del Workspace"
            noun="logo"
            alt="Logo del Workspace"
            file={data.logo}
            removed={data.remove_logo}
            currentUrl={data.logo_url}
            onChoose={(file) => setData((previous) => ({ ...previous, logo: file, remove_logo: false }))}
            onRemove={() => setData((previous) => ({ ...previous, logo: null, remove_logo: true }))}
            error={errors.logo}
        />
    );
}

/** Primary color: suggested swatches or any readable color. Only this accent changes; the rest of Ava stays. */
function ColorField({ form, palette }) {
    const { data, setData, errors } = form;
    const valid = isHexColor(data.primary_color);
    const readable = valid && contrastWithWhite(data.primary_color) >= MIN_CONTRAST;

    return (
        <div>
            <InputLabel htmlFor="settings_primary_color" value="Color principal" />
            <div className="mt-2 flex flex-wrap items-center gap-2" role="group" aria-label="Colores sugeridos">
                {palette.map((color) => (
                    <button
                        key={color}
                        type="button"
                        onClick={() => setData('primary_color', color)}
                        aria-label={`Usar el color ${color}`}
                        aria-pressed={data.primary_color.toLowerCase() === color}
                        style={{ backgroundColor: color }}
                        className="size-8 rounded-full ring-2 ring-transparent ring-offset-2 ring-offset-card transition focus-visible:outline-2 focus-visible:outline-primary aria-pressed:ring-ink"
                    />
                ))}
            </div>

            <div className="mt-3 flex items-center gap-3">
                <input
                    type="color"
                    aria-label="Elegir otro color"
                    value={valid ? data.primary_color : '#1d4ed8'}
                    onChange={(e) => setData('primary_color', e.target.value)}
                    className="h-10 w-12 cursor-pointer rounded-lg border border-field bg-card p-1"
                />
                <TextInput
                    id="settings_primary_color"
                    className="max-w-40 font-mono uppercase"
                    value={data.primary_color}
                    onChange={(e) => setData('primary_color', e.target.value)}
                    invalid={Boolean(errors.primary_color) || !valid}
                    maxLength={7}
                />
            </div>
            {valid && !readable && <p className="mt-1 text-sm text-accent-amber">Este color es demasiado claro: el texto blanco no se leería bien.</p>}
            <InputError message={errors.primary_color} className="mt-1" />

            {valid && (
                <div style={brandVariables(data.primary_color)} className="mt-4 flex flex-wrap items-center gap-3 rounded-lg border border-line bg-canvas/60 p-4">
                    <span className="rounded-md bg-primary px-4 py-2 text-xs font-semibold tracking-widest text-white uppercase">Botón</span>
                    <Badge tone="blue">Etiqueta</Badge>
                    <span className="text-sm font-semibold text-primary underline">Enlace</span>
                </div>
            )}
        </div>
    );
}

/** Appearance: logo, primary color and light / dark / system mode. */
export default function AppearancePanel({ settings, form, onSave, catalog, section }) {
    const { data, setData } = form;
    const modeLabel = (value) => catalog.appearances.find((mode) => mode.value === value)?.label;

    // The preview shows the saved values in the summary and the values being edited in the form, and never touches the real theme.
    const summary = (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)]">
            <dl className="grid content-start gap-3 sm:grid-cols-2 lg:grid-cols-1">
                <Fact label="Nombre">{settings.name}</Fact>
                <Fact label="Logo">
                    {settings.logo_url ? <img src={settings.logo_url} alt="Logo del Workspace" className="mt-1 h-12 max-w-40 rounded object-contain" /> : null}
                </Fact>
                <Fact label="Color principal">
                    <span className="inline-flex items-center gap-2">
                        <span className="size-5 rounded-full ring-1 ring-line" style={{ backgroundColor: settings.primary_color }} aria-hidden="true" />
                        <span className="font-mono uppercase">{settings.primary_color}</span>
                    </span>
                </Fact>
                <Fact label="Modo de apariencia">{modeLabel(settings.appearance)}</Fact>
            </dl>
            <AppearancePreview name={settings.name} color={settings.primary_color} appearance={settings.appearance} logoUrl={settings.logo_url} />
        </div>
    );

    // A chosen file is previewed from a temporary local URL, released when it changes or the form closes.
    const [chosenUrl, setChosenUrl] = useState(null);
    useEffect(() => {
        if (!data.logo) {
            setChosenUrl(null);
            return undefined;
        }
        const url = URL.createObjectURL(data.logo);
        setChosenUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [data.logo]);
    const editingLogo = chosenUrl ?? (data.remove_logo ? null : data.logo_url);

    return (
        <SettingsSection title="Apariencia" description="Personaliza la identidad de tu Workspace sin salirte del estilo de Ava." form={form} onSave={onSave} summary={summary} {...section}>
            <LogoField form={form} />
            <ColorField form={form} palette={catalog.palette} />

            <fieldset>
                <legend className="text-sm font-medium text-ink">Modo de apariencia</legend>
                <div className="mt-2 grid gap-3 sm:grid-cols-3">
                    {catalog.appearances.map((mode) => {
                        const Icon = MODES.find((item) => item.value === mode.value)?.icon ?? Sun;

                        return (
                            <label
                                key={mode.value}
                                className="flex cursor-pointer items-center gap-3 rounded-lg border border-field px-4 py-3 text-sm font-medium text-ink transition-colors duration-150 hover:border-primary/40 has-checked:border-primary has-checked:bg-primary-soft has-focus-visible:outline-2 has-focus-visible:outline-primary"
                            >
                                <input
                                    type="radio"
                                    name="appearance"
                                    value={mode.value}
                                    checked={data.appearance === mode.value}
                                    onChange={() => setData('appearance', mode.value)}
                                    className="sr-only"
                                />
                                <Icon className="size-4 text-primary" aria-hidden="true" />
                                {mode.label}
                            </label>
                        );
                    })}
                </div>
                <p className="mt-2 text-xs text-ink-muted">«Sistema» sigue el modo claro u oscuro del dispositivo de cada usuario.</p>
            </fieldset>

            <div>
                <p className="mb-2 text-sm font-medium text-ink">Vista previa con estos ajustes</p>
                <AppearancePreview name={data.name} color={data.primary_color} appearance={data.appearance} logoUrl={editingLogo} />
            </div>
        </SettingsSection>
    );
}
