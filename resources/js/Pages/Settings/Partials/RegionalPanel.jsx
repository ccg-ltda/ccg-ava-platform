import { Clock } from 'lucide-react';
import { useMemo } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import { formatDate, formatMoney, formatTime } from '@/lib/format';
import SettingsSection from './SettingsSection';

/** Regional: currency, timezone and the date/time format used across the Workspace. */
export default function RegionalPanel({ form, onSave, catalog }) {
    const { data, setData, errors } = form;
    const now = useMemo(() => new Date(), []);
    const preferences = { timezone: data.timezone, dateFormat: data.date_format, timeFormat: data.time_format };

    const example = (format) => formatDate(now, { timezone: data.timezone, dateFormat: format.value });
    const timeExample = (format) => formatTime(now, { timezone: data.timezone, timeFormat: format.value });

    return (
        <SettingsSection title="Regional" description="Moneda, zona horaria y formatos que verán todos los usuarios de este Workspace." form={form} onSave={onSave}>
            <div>
                <InputLabel htmlFor="settings_currency" value="Moneda" />
                <Select id="settings_currency" className="mt-1" value={data.currency} onChange={(value) => setData('currency', value)} options={catalog.currencies} invalid={Boolean(errors.currency)} />
                <InputError message={errors.currency} className="mt-1" />
            </div>

            <div>
                <InputLabel htmlFor="settings_timezone" value="Zona horaria" />
                <Select id="settings_timezone" className="mt-1" value={data.timezone} onChange={(value) => setData('timezone', value)} options={catalog.timezones} invalid={Boolean(errors.timezone)} />
                <InputError message={errors.timezone} className="mt-1" />
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
                </div>
            </div>

            <div className="flex items-start gap-3 rounded-lg border border-line bg-canvas/60 p-4 text-sm">
                <Clock className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                <p className="text-ink">
                    <span className="font-semibold">Vista previa:</span> {formatDate(now, preferences)} {formatTime(now, preferences)} · {formatMoney(1234567.89, data.currency)}
                </p>
            </div>
        </SettingsSection>
    );
}
