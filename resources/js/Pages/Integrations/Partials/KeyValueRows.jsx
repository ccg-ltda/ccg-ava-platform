import { Plus, Trash2 } from 'lucide-react';
import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';

/**
 * Editable list of name/value pairs (HTTP headers, query parameters). A row flagged "Secreto" hides its value and the
 * server stores it encrypted; when editing, a stored secret shows only a placeholder and a blank value keeps it.
 */
export default function KeyValueRows({ id, label, rows, onChange, errors, max, namePlaceholder, addLabel }) {
    const update = (index, patch) => onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    const remove = (index) => onChange(rows.filter((_, i) => i !== index));
    const add = () => onChange([...rows, { name: '', value: '', secret: false, secret_set: false }]);

    return (
        <div>
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium text-ink">{label}</p>
                <SecondaryButton type="button" onClick={add} disabled={rows.length >= max} className="gap-1.5 py-1.5">
                    <Plus className="size-3.5" aria-hidden="true" />
                    {addLabel}
                </SecondaryButton>
            </div>
            <InputError message={errors[id]} className="mt-1" />

            {rows.length > 0 && (
                <ul className="mt-4 space-y-3">
                    {rows.map((row, index) => (
                        <li key={index} className="rounded-lg border border-line bg-card p-4">
                            <div className="grid gap-3 sm:grid-cols-[1fr_1.4fr]">
                                <div>
                                    <TextInput
                                        aria-label={`${label}: nombre ${index + 1}`}
                                        value={row.name}
                                        onChange={(e) => update(index, { name: e.target.value })}
                                        placeholder={namePlaceholder}
                                        invalid={Boolean(errors[`${id}.${index}.name`])}
                                        autoComplete="off"
                                    />
                                    <InputError message={errors[`${id}.${index}.name`]} className="mt-1" />
                                </div>
                                <div>
                                    <TextInput
                                        aria-label={`${label}: valor ${index + 1}`}
                                        type={row.secret ? 'password' : 'text'}
                                        value={row.value}
                                        onChange={(e) => update(index, { value: e.target.value })}
                                        placeholder={row.secret ? (row.secret_set ? '•••••••••••• (guardado; vacío = conservar)' : 'Valor secreto') : 'Valor'}
                                        invalid={Boolean(errors[`${id}.${index}.value`])}
                                        autoComplete="off"
                                    />
                                    <InputError message={errors[`${id}.${index}.value`]} className="mt-1" />
                                </div>
                            </div>
                            <div className="mt-3 flex items-center justify-between gap-3">
                                <label className="flex items-center gap-2 text-sm text-ink">
                                    <Checkbox checked={row.secret} onChange={(e) => update(index, { secret: e.target.checked })} />
                                    Secreto (se guarda cifrado y no se vuelve a mostrar)
                                </label>
                                <button
                                    type="button"
                                    onClick={() => remove(index)}
                                    aria-label={`Quitar ${label.toLowerCase()} ${index + 1}`}
                                    className="grid size-8 place-items-center rounded-lg text-danger transition-colors duration-150 hover:bg-danger/10 focus-visible:outline-2 focus-visible:outline-danger"
                                >
                                    <Trash2 className="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
