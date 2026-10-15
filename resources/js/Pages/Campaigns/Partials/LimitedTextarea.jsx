import { useLayoutEffect, useRef, useState } from 'react';
import Textarea from '@/Components/Textarea';
import { applyChange, countCharacters, excess } from '@/config/messageLimits';

const MAX_HEIGHT = 480;

/**
 * A textarea with a character limit that counts the way the server does (Unicode characters, not UTF-16 units: `maxLength` would count an
 * emoji twice). Text that would pass the limit is refused with a message instead of being cut, the counter is always visible, and the field
 * grows with its content up to a comfortable height.
 */
export default function LimitedTextarea({ id, value, onChange, max, minRows = 8, disabled = false, invalid = false }) {
    const ref = useRef(null);
    const [notice, setNotice] = useState(null);
    const count = countCharacters(value);
    const over = excess(value, max);
    const tone = over > 0 ? 'text-danger' : count >= max * 0.9 ? 'text-accent-amber' : 'text-ink-muted';

    useLayoutEffect(() => {
        const element = ref.current;

        if (!element) return;

        element.style.height = 'auto';
        element.style.height = `${Math.min(element.scrollHeight + 2, MAX_HEIGHT)}px`;
    }, [value]);

    const change = (event) => {
        const result = applyChange(value, event.target.value, max);

        setNotice(result.rejected);

        if (result.value !== value) onChange(result.value);
        else event.target.value = value;
    };

    return (
        <div>
            <Textarea id={id} ref={ref} rows={minRows} value={value} disabled={disabled} onChange={change} invalid={invalid || over > 0} aria-describedby={`${id}_count`} className="mt-1 min-h-40 overflow-y-auto" />
            <div className="mt-1 flex flex-wrap items-start justify-between gap-2">
                <p role="status" className="min-w-0 text-xs text-accent-amber">
                    {notice}
                </p>
                <span id={`${id}_count`} className={`ms-auto shrink-0 text-xs font-semibold tabular-nums ${tone}`}>
                    {count} / {max}
                </span>
            </div>
        </div>
    );
}
