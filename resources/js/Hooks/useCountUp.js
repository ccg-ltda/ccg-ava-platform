import { useEffect, useRef, useState } from 'react';

/**
 * A number that rises from 0 to `target` the first time it appears and then follows changes smoothly. Users who ask for
 * less motion get the final number at once.
 */
export default function useCountUp(target, duration = 600) {
    const [shown, setShown] = useState(0);
    const latest = useRef(0);

    useEffect(() => {
        const set = (value) => {
            latest.current = value;
            setShown(value);
        };

        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            set(target);

            return undefined;
        }

        const from = latest.current;
        const start = performance.now();
        let frame;

        const step = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            set(Math.round(from + (target - from) * (1 - (1 - progress) ** 3)));

            if (progress < 1) frame = requestAnimationFrame(step);
        };
        frame = requestAnimationFrame(step);

        return () => cancelAnimationFrame(frame);
    }, [target, duration]);

    return shown;
}
