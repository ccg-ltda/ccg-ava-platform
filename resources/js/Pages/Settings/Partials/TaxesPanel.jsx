import { Landmark } from 'lucide-react';
import Alert from '@/Components/Alert';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import SettingsSection from './SettingsSection';

/** Impuestos: fiscal country and the main tax. Country defaults are starting values the user can change. */
export default function TaxesPanel({ form, onSave, catalog }) {
    const { data, setData, errors } = form;
    const country = catalog.taxCountries.find((item) => item.code === data.tax_country);

    // Picking a country loads its suggested currency and tax; both stay editable afterwards.
    const chooseCountry = (code) => {
        const next = catalog.taxCountries.find((item) => item.code === code);

        setData((previous) => ({
            ...previous,
            tax_country: code,
            currency: next.currency,
            tax_enabled: next.tax.enabled,
            tax_name: next.tax.name,
            tax_rate: next.tax.rate,
        }));
    };

    return (
        <SettingsSection title="Impuestos" description="País fiscal y el impuesto principal que aplica tu negocio." form={form} onSave={onSave}>
            <div>
                <InputLabel htmlFor="settings_tax_country" value="País fiscal" />
                <Select
                    id="settings_tax_country"
                    className="mt-1"
                    value={data.tax_country}
                    onChange={chooseCountry}
                    options={catalog.taxCountries.map((item) => ({ value: item.code, label: `${item.flag} ${item.name}` }))}
                    invalid={Boolean(errors.tax_country)}
                />
                <InputError message={errors.tax_country} className="mt-1" />
                <p className="mt-1 text-xs text-ink-muted">Al elegir un país se sugieren su moneda y su impuesto; puedes ajustarlos.</p>
            </div>

            <div className="space-y-4 rounded-lg border border-line p-4">
                <Toggle
                    checked={Boolean(data.tax_enabled)}
                    onChange={(value) => setData('tax_enabled', value)}
                    label="Aplicar impuesto principal"
                    description="Desactívalo si tu negocio no cobra impuesto."
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel htmlFor="settings_tax_name" value="Nombre del impuesto" />
                        <TextInput id="settings_tax_name" className="mt-1" value={data.tax_name} onChange={(e) => setData('tax_name', e.target.value)} invalid={Boolean(errors.tax_name)} maxLength={40} />
                        <InputError message={errors.tax_name} className="mt-1" />
                    </div>

                    <div>
                        <InputLabel htmlFor="settings_tax_rate" value="Tasa (%)" />
                        <TextInput
                            id="settings_tax_rate"
                            type="number"
                            inputMode="decimal"
                            min="0"
                            max="100"
                            step="0.01"
                            className="mt-1"
                            value={data.tax_rate}
                            onChange={(e) => setData('tax_rate', e.target.value)}
                            invalid={Boolean(errors.tax_rate)}
                        />
                        <InputError message={errors.tax_rate} className="mt-1" />
                    </div>
                </div>
            </div>

            {country && (
                <div className="rounded-lg border border-line bg-canvas/60 p-4" aria-label="Información fiscal del país">
                    <p className="flex items-center gap-2 text-sm font-bold text-ink">
                        <Landmark className="size-4 text-primary" aria-hidden="true" />
                        Información de {country.name}
                    </p>
                    <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Autoridad fiscal</dt>
                            <dd className="mt-0.5 text-ink">{country.authority}</dd>
                        </div>
                        <div>
                            <dt className="text-[11px] font-semibold tracking-wider text-ink-muted uppercase">Facturación</dt>
                            <dd className="mt-0.5 text-ink">{country.system}</dd>
                        </div>
                    </dl>
                    {country.note && (
                        <Alert tone="info" className="mt-3">
                            {country.note}
                        </Alert>
                    )}
                    <p className="mt-3 text-xs text-ink-muted">Solo informativo. La facturación electrónica y las integraciones fiscales llegarán en una fase futura.</p>
                </div>
            )}
        </SettingsSection>
    );
}
