import { useEffect, useState } from 'react';

const loading = {};

/** Loads the public widget script once (the same file customers install) and returns `window.AvaWidget` when ready. */
function load(url) {
    if (window.AvaWidget) return Promise.resolve(window.AvaWidget);

    loading[url] ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onload = () => resolve(window.AvaWidget);
        script.onerror = () => {
            delete loading[url];
            reject(new Error('No se pudo cargar el script del widget.'));
        };
        document.head.appendChild(script);
    });

    return loading[url];
}

/** @returns {{ widget: object|null, failed: boolean }} */
export default function useAvaWidgetScript(url) {
    const [state, setState] = useState({ widget: window.AvaWidget ?? null, failed: false });

    useEffect(() => {
        let active = true;

        load(url).then(
            (widget) => active && setState({ widget, failed: false }),
            () => active && setState({ widget: null, failed: true }),
        );

        return () => {
            active = false;
        };
    }, [url]);

    return state;
}
