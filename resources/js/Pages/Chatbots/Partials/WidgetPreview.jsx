import { useEffect, useRef } from 'react';
import useAvaWidgetScript from '@/Hooks/useAvaWidgetScript';

/**
 * The live preview of a channel's button / widget. It runs the SAME script customers install (public/widget/ava-widget.js,
 * in preview mode) over a mock page, so what is shown here is what the visitor will see. `config` has the shape of the
 * public configuration; every change redraws it without saving.
 */
export default function WidgetPreview({ scriptUrl, config, startOpen, dark, large = false }) {
    const { widget, failed } = useAvaWidgetScript(scriptUrl);
    const container = useRef(null);
    const controller = useRef(null);
    const latest = useRef(config);
    latest.current = config;

    useEffect(() => {
        if (!widget || !container.current) return undefined;

        controller.current = widget.mount(latest.current, { container: container.current, preview: true, open: startOpen });

        return () => {
            controller.current?.destroy();
            controller.current = null;
        };
    }, [widget, startOpen]);

    useEffect(() => {
        controller.current?.update(config);
    }, [config]);

    const line = dark ? 'bg-white/10' : 'bg-slate-300/70';
    const height = large ? 'h-[34rem] sm:h-[40rem] lg:h-[44rem]' : 'h-[30rem] sm:h-[36rem]';

    return (
        <div
            ref={container}
            aria-label="Vista previa"
            className={`relative overflow-hidden rounded-xl border border-line ${height} ${dark ? 'bg-slate-900' : 'bg-slate-50'}`}
        >
            <div className="pointer-events-none" aria-hidden="true">
                <div className="flex items-center gap-3 bg-linear-to-r from-primary to-navy px-5 py-3">
                    <span className="size-6 rounded-lg bg-white/25" />
                    <span className="h-2.5 w-20 rounded bg-white/40" />
                    <span className="ml-auto hidden h-2 w-12 rounded bg-white/30 sm:block" />
                    <span className="hidden h-2 w-12 rounded bg-white/30 sm:block" />
                    <span className="h-2 w-12 rounded bg-white/30" />
                </div>
                <div className="space-y-3 p-5 sm:p-8">
                    <div className={`h-4 w-1/2 rounded ${line}`} />
                    <div className={`h-2.5 w-2/3 rounded ${line}`} />
                    <div className={`h-2.5 w-1/2 rounded ${line}`} />
                    <div className="grid grid-cols-3 gap-3 pt-4">
                        <div className={`h-20 rounded-lg ${line}`} />
                        <div className={`h-20 rounded-lg ${line}`} />
                        <div className={`h-20 rounded-lg ${line}`} />
                    </div>
                    <div className={`h-2.5 w-3/4 rounded ${line}`} />
                    <div className={`h-2.5 w-2/5 rounded ${line}`} />
                </div>
            </div>
            {failed && <p className="absolute inset-x-4 top-4 rounded-lg bg-danger/10 p-3 text-sm text-danger">No se pudo cargar la vista previa.</p>}
        </div>
    );
}
