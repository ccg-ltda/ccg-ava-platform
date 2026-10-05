import { ImagePlus, Monitor, Moon, Sun, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useRef } from 'react';
import Badge from '@/Components/Badge';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { brandVariables, contrastWithWhite, isHexColor } from '@/lib/theme';
import SettingsSection from './SettingsSection';

const MODES = [
    { value: 'light', icon: Sun },
    { value: 'dark', icon: Moon },
    { value: 'system', icon: Monitor },
];

const MIN_CONTRAST = 4.5;

/** Logo of the Workspace: upload, preview and removal. The file is validated again by the server. */
function LogoField({ form }) {
    const { data, setData, errors } = form;
    const input = useRef(null);
    const preview = useMemo(() => (data.logo ? URL.createObjectURL(data.logo) : null), [data.logo]);
    const shown = data.remove_logo ? null : (preview ?? data.logo_url);

    useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview]);

    const choose = (event) => {
        const file = event.target.files?.[0];

        if (file) {
            setData((previous) => ({ ...previous, logo: file, remove_logo: false }));
        }

        event.target.value = '';
    };

    const remove = () => setData((previous) => ({ ...previous, logo: null, remove_logo: true }));

    return (
        <div>
            <InputLabel htmlFor="settings_logo" value="Logo del Workspace" />
            <div className="mt-2 flex flex-wrap items-center gap-4">
                <div className="grid size-20 shrink-0 place-items-center overflow-hidden rounded-xl border border-line bg-canvas">
                    {shown ? (
                        <img src={shown} alt="Logo del Workspace" className="size-full object-contain p-1" />
                    ) : (
                        <ImagePlus className="size-6 text-ink-muted" aria-hidden="true" />
                    )}
                </div>

                <div className="space-y-2">
                    <div className="flex flex-wrap gap-2">
                        <SecondaryButton type="button" onClick={() => input.current?.click()} className="gap-2">
                            <ImagePlus className="size-4" aria-hidden="true" />
                            {shown ? 'Cambiar logo' : 'Subir logo'}
                        </SecondaryButton>
                        {shown && (
                            <SecondaryButton type="button" onClick={remove} className="gap-2 text-danger">
                                <Trash2 className="size-4" aria-hidden="true" />
                                Quitar
                            </SecondaryButton>
                        )}
                    </div>
                    <p className="text-xs text-ink-muted">PNG, JPG o WebP. Máximo 1 MB.</p>
                </div>

                <input ref={input} id="settings_logo" type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={choose} />
            </div>
            <InputError message={errors.logo} className="mt-2" />
        </div>
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
export default function AppearancePanel({ form, onSave, catalog }) {
    const { data, setData } = form;

    return (
        <SettingsSection title="Apariencia" description="Personaliza la identidad de tu Workspace sin salirte del estilo de Ava." form={form} onSave={onSave}>
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
        </SettingsSection>
    );
}
