import { Plus, Trash2 } from 'lucide-react';
import IconButton from '@/Components/IconButton';
import InputError from '@/Components/InputError';
import TimeField from '@/Components/TimeField';

const MAX_BLOCKS = 3;

/**
 * The weekly schedule of a professional: for each day, one or more working blocks (local time). A break is simply the gap between
 * two blocks. Controlled: `value` is a flat list `{ weekday, startsAt, endsAt }`, `onChange(list)`; the server validates it again.
 */
export default function ScheduleEditor({ days, locations = [], value, onChange, error }) {
    const blocksOf = (weekday) => value.filter((block) => block.weekday === weekday);
    const replace = (weekday, blocks) => onChange([...value.filter((block) => block.weekday !== weekday), ...blocks].sort((a, b) => a.weekday - b.weekday || a.startsAt.localeCompare(b.startsAt)));
    const edit = (weekday, index, field, text) => replace(weekday, blocksOf(weekday).map((block, i) => (i === index ? { ...block, [field]: text } : block)));
    const add = (weekday) => {
        const blocks = blocksOf(weekday);
        const last = blocks.at(-1);

        replace(weekday, [...blocks, last ? { weekday, startsAt: last.endsAt < '17:00' ? '14:00' : '18:00', endsAt: '18:00', locationId: last.locationId ?? null } : { weekday, startsAt: '09:00', endsAt: '17:00', locationId: null }]);
    };

    return (
        <fieldset>
            <legend className="text-sm font-medium text-ink">Horario semanal</legend>
            <p className="mt-0.5 text-xs text-ink-muted">Un día sin tramos es un día libre. Para un descanso, deja un hueco entre dos tramos (por ejemplo 09:00–13:00 y 14:00–18:00).{locations.length > 0 && ' «Todas las sedes» significa que el tramo vale en cualquier sede.'}</p>
            <div className="mt-3 divide-y divide-line rounded-lg border border-line">
                {days.map(({ value: weekday, label }) => {
                    const blocks = blocksOf(weekday);

                    return (
                        <div key={weekday} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-3 py-2.5">
                            <span className="w-full shrink-0 text-sm font-medium text-ink sm:w-24">{label}</span>
                            <div className="flex w-full min-w-0 flex-wrap items-center gap-2 sm:w-auto sm:flex-1">
                                {blocks.length === 0 && <span className="text-sm text-ink-muted">Libre</span>}
                                {blocks.map((block, index) => (
                                    <span key={index} className="flex w-full flex-wrap items-center gap-1.5 rounded-lg bg-canvas px-2 py-1 sm:inline-flex sm:w-auto sm:max-w-full sm:flex-wrap">
                                        <TimeField compact aria-label={`${label}: inicio del tramo ${index + 1}`} className="min-w-[7.5rem] flex-1 sm:w-[9.5rem] sm:flex-none" value={block.startsAt} onChange={(time) => edit(weekday, index, 'startsAt', time)} />
                                        <span className="text-ink-muted" aria-hidden="true">–</span>
                                        <TimeField compact aria-label={`${label}: fin del tramo ${index + 1}`} className="min-w-[7.5rem] flex-1 sm:w-[9.5rem] sm:flex-none" value={block.endsAt} onChange={(time) => edit(weekday, index, 'endsAt', time)} />
                                        {locations.length > 0 && (
                                            <select aria-label={`${label}: sede del tramo ${index + 1}`} className="field min-w-[7.5rem] flex-1 !px-2 !py-1 !text-[13px] sm:!w-[9.5rem] sm:flex-none sm:!px-3 sm:!text-sm" value={block.locationId ?? ''} onChange={(e) => edit(weekday, index, 'locationId', e.target.value ? Number(e.target.value) : null)}>
                                                <option value="">Todas las sedes</option>
                                                {locations.map((location) => (
                                                    <option key={location.id} value={location.id}>{location.name}{location.isActive ? '' : ' (inactiva)'}</option>
                                                ))}
                                            </select>
                                        )}
                                        <IconButton icon={Trash2} label="Quitar tramo" context={label} tone="danger" className="!size-7" onClick={() => replace(weekday, blocks.filter((_, i) => i !== index))} />
                                    </span>
                                ))}
                            </div>
                            {blocks.length < MAX_BLOCKS && (
                                <button type="button" onClick={() => add(weekday)} className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                                    <Plus className="size-3.5" aria-hidden="true" />
                                    Tramo
                                </button>
                            )}
                        </div>
                    );
                })}
            </div>
            <InputError message={error} className="mt-1" />
        </fieldset>
    );
}
