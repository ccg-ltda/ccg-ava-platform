import { useLayoutEffect, useRef, useState } from 'react';
import TextInput from '@/Components/TextInput';
import { caretFor, parsePrice, priceToDisplay } from '@/lib/price';

/**
 * Price field in Colombian format: it shows «1.500.000» (comma for decimals) while the person types and reports the plain number («1500000», «4000.5») to
 * `onChange`, which is what the server receives. The caret stays after the digits it had, so editing in the middle never jumps or duplicates digits.
 */
export default function PriceInput({ value, onChange, ...props }) {
    const ref = useRef(null);
    const caret = useRef(null);
    const [display, setDisplay] = useState(() => priceToDisplay(value));

    // A value changed from outside (a reset, another record) is shown in the same format; the one this input reported itself keeps what the person sees.
    const shown = parsePrice(display).value === String(value ?? '') ? display : priceToDisplay(value);

    useLayoutEffect(() => {
        if (caret.current !== null && ref.current) {
            ref.current.setSelectionRange(caret.current, caret.current);
            caret.current = null;
        }
    });

    const change = (event) => {
        const typed = event.target.value;
        const next = parsePrice(typed);

        caret.current = caretFor(typed, event.target.selectionStart ?? typed.length, next.display);
        setDisplay(next.display);
        onChange(next.value);
    };

    return <TextInput {...props} ref={undefined} type="text" inputMode="decimal" autoComplete="off" value={shown} onChange={(event) => { ref.current = event.target; change(event); }} />;
}
