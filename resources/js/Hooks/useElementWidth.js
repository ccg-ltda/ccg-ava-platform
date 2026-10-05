import { useEffect, useRef, useState } from 'react';

/** Width of an element, kept up to date while it resizes (charts draw at the pixel size they have). */
export default function useElementWidth(initial = 320) {
    const ref = useRef(null);
    const [width, setWidth] = useState(initial);

    useEffect(() => {
        const element = ref.current;
        if (!element) return undefined;

        const update = () => setWidth(Math.max(Math.round(element.getBoundingClientRect().width), 120));
        update();

        const observer = new ResizeObserver(update);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return [ref, width];
}
