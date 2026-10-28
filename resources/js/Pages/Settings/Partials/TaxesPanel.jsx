import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import Select from '@/Components/Select';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import FiscalCards from './FiscalCards';
import SettingsSection, { Fact } from './SettingsSection';
import WompiPanel from './WompiPanel';

/** Impuestos y facturación: country, tax and currency on top, then the two protagonist cards (fiscal authority and electronic invoicing). Country defaults are starting values the user can change. */
export default function TaxesPanel({ settings, form, onSave, catalog, section, wompi = null }) {
    const { data, setData, errors } = form;
    const country = catalog.taxCountries.find((item) => item.code === data.tax_country);
    const savedCountry = catalog.taxCountries.find((item) => item.code === settings.tax_country);
    const currencyLabel = (code) => catalog.currencies.find((option) => option.value === code)?.label ?? code;

    // Only what is configured: nothing is assumed about rates or obligations the Workspace has not set.
    const summary = (
        <div className="space-y-6">
            <section aria-label="País, impuesto y moneda">
                <h3 className="text-sm font-bold text-ink">País, impuesto y moneda</h3>
                <dl className="mt-3 grid gap-3 sm:grid-cols-3">
                    <Fact label="País fiscal">{savedCountry ? `${savedCountry.flag} ${savedCountry.name}` : null}</Fact>
                    <Fact label="Impuesto principal">{settings.tax_enabled ? `${settings.tax_name || 'Impuesto'} · ${settings.tax_rate}%` : 'No se aplica impuesto'}</Fact>
                    <Fact label="Moneda">{currencyLabel(settings.currency)}</Fact>
                </dl>
            </section>
            <FiscalCards country={savedCountry} />
            {wompi && <WompiPanel wompi={wompi} />}
        </div>
    );

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
        <SettingsSection title="Impuestos y facturación" description="País fiscal, impuesto principal y moneda de tu negocio." form={form} onSave={onSave} summary={summary} wide {...section}>
            <section aria-label="País, impuesto y moneda" className="space-y-4 rounded-xl border border-line p-4 sm:p-5">
                <h3 className="text-sm font-bold text-ink">País, impuesto y moneda</h3>

                <div className="grid gap-5 md:grid-cols-2">
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

                    <div>
                        <InputLabel htmlFor="settings_tax_currency" value="Moneda" />
                        <Select id="settings_tax_currency" className="mt-1" value={data.currency} onChange={(value) => setData('currency', value)} options={catalog.currencies} invalid={Boolean(errors.currency)} />
                        <InputError message={errors.currency} className="mt-1" />
                        <p className="mt-1 text-xs text-ink-muted">Es la misma moneda de la pestaña Regional.</p>
                    </div>
                </div>

                <div className="space-y-4 rounded-lg border border-line bg-canvas/40 p-4">
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
            </section>

            <FiscalCards country={country} />
        </SettingsSection>
    );
}
