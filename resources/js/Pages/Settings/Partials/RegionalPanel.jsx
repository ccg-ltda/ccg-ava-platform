import { useMemo } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import { formatDate, formatTime } from '@/lib/format';
import RegionalPreview from './RegionalPreview';
import SettingsSection, { Fact } from './SettingsSection';

/** Regional: currency, timezone and the date/time format used across the Workspace; the preview of what each choice does goes below the fields. */
export default function RegionalPanel({ settings, form, onSave, catalog, section }) {
    const { data, setData, errors } = form;
    const now = useMemo(() => new Date(), []);
    const labelOf = (options, value) => options.find((option) => option.value === value)?.label;

    const summary = (
        <div className="space-y-6">
            <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <Fact label="Moneda">{labelOf(catalog.currencies, settings.currency)}</Fact>
                <Fact label="Zona horaria">{labelOf(catalog.timezones, settings.timezone)}</Fact>
                <Fact label="Formato de fecha">{labelOf(catalog.dateFormats, settings.date_format)}</Fact>
                <Fact label="Formato de hora">{labelOf(catalog.timeFormats, settings.time_format)}</Fact>
            </dl>
            <RegionalPreview currency={settings.currency} timezone={settings.timezone} dateFormat={settings.date_format} timeFormat={settings.time_format} catalog={catalog} />
        </div>
    );

    const example = (format) => formatDate(now, { timezone: data.timezone, dateFormat: format.value });
    const timeExample = (format) => formatTime(now, { timezone: data.timezone, timeFormat: format.value });

    return (
        <SettingsSection title="Regional" description="Moneda, zona horaria y formatos que verán todos los usuarios de este Workspace." form={form} onSave={onSave} summary={summary} wide {...section}>
            <div className="max-w-2xl space-y-6">
                <div>
                    <InputLabel htmlFor="settings_currency" value="Moneda" />
                    <Select id="settings_currency" className="mt-1" value={data.currency} onChange={(value) => setData('currency', value)} options={catalog.currencies} invalid={Boolean(errors.currency)} />
                    <InputError message={errors.currency} className="mt-1" />
                    <p className="mt-1.5 text-xs text-ink-muted">Define el símbolo y los separadores de los montos.</p>
                </div>

                <div>
                    <InputLabel htmlFor="settings_timezone" value="Zona horaria" />
                    <Select id="settings_timezone" className="mt-1" value={data.timezone} onChange={(value) => setData('timezone', value)} options={catalog.timezones} invalid={Boolean(errors.timezone)} />
                    <InputError message={errors.timezone} className="mt-1" />
                    <p className="mt-1.5 text-xs text-ink-muted">Las fechas y horas de Ava se muestran en esta zona.</p>
                </div>

                <div className="grid gap-6 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="settings_date_format" value="Formato de fecha" />
                        <Select
                            id="settings_date_format"
                            className="mt-1"
                            value={data.date_format}
                            onChange={(value) => setData('date_format', value)}
                            options={catalog.dateFormats.map((format) => ({ value: format.value, label: `${format.label} · ${example(format)}` }))}
                            invalid={Boolean(errors.date_format)}
                        />
                        <InputError message={errors.date_format} className="mt-1" />
                        <p className="mt-1.5 text-xs text-ink-muted">Orden de día, mes y año.</p>
                    </div>

                    <div>
                        <InputLabel htmlFor="settings_time_format" value="Formato de hora" />
                        <Select
                            id="settings_time_format"
                            className="mt-1"
                            value={data.time_format}
                            onChange={(value) => setData('time_format', value)}
                            options={catalog.timeFormats.map((format) => ({ value: format.value, label: `${format.label} · ${timeExample(format)}` }))}
                            invalid={Boolean(errors.time_format)}
                        />
                        <InputError message={errors.time_format} className="mt-1" />
                        <p className="mt-1.5 text-xs text-ink-muted">24 horas o AM/PM.</p>
                    </div>
                </div>
            </div>

            <section aria-label="Vista previa" className="space-y-3 border-t border-line pt-6">
                <div>
                    <h3 className="text-sm font-bold text-ink">Vista previa</h3>
                    <p className="mt-0.5 text-xs text-ink-muted">Se actualiza con lo que eliges. Son ejemplos: no se guarda nada ni se modifican los registros existentes hasta que pulses «Guardar cambios».</p>
                </div>
                <RegionalPreview currency={data.currency} timezone={data.timezone} dateFormat={data.date_format} timeFormat={data.time_format} catalog={catalog} />
            </section>
        </SettingsSection>
    );
}
